<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AiDecision;
use App\Models\Customer;
use App\Models\CustomerMerge;
use App\Models\EmailMessage;
use App\Models\User;
use App\Services\Mailbox\EmailAttachmentService;
use Illuminate\Support\Facades\Storage;

/**
 * DSGVO-konforme, vollständige Kundenlöschung (Einzel- UND Massen-
 * Löschung nutzen exakt dieselbe Logik - keine zwei Löschpfade).
 *
 * Entscheidung Hard- statt Soft-Delete: Der Zweck ist die Bereinigung
 * fehlerhaft angelegter/importierter Kunden inkl. aller personenbezogenen
 * Daten (Art. 17). Ein Soft-Delete würde Mail-Volltexte, Dokumente und
 * den Login-Account behalten. Verknüpfte Daten werden explizit
 * behandelt:
 * - Dokumente/Anhänge: physische Dateien werden gelöscht (H3)
 * - E-Mails: Volltexte + Anhangdateien werden gelöscht
 * - Tickets/Notizen/Aufgaben/Verträge/Familie/Timeline: FK-Kaskade
 * - Aktivitätslog: bleibt als Nachweis der Löschung erhalten
 */
class CustomerDeletionService
{
    public function __construct(private readonly EmailAttachmentService $attachments)
    {
    }

    public function delete(Customer $customer, ?int $actorId = null): void
    {
        // ARCH-7: die Relation ausdruecklich sicherstellen. Beim Bulk-Loeschen
        // laeuft delete() in einer Schleife - ohne diese Zeile holt jeder
        // Durchlauf seinen User einzeln nach (N+1), und mit strengem Eloquent
        // scheitert der Aufruf sogar.
        $customer->loadMissing('user');

        $user = $customer->user;
        $customerNumber = $customer->customer_number;
        $documentIds = $customer->documents()->pluck('id');

        // KI-Entscheidungsprotokoll (Smart Document Upload) bereinigen:
        // der Eintrag selbst (Skill/Status/Zeitpunkt) bleibt als Nachweis
        // erhalten, aber sein Inhalt (Name/Kundennummer des Matches, KI-
        // Zusammenfassung) wird entfernt - sonst ueberlebt personenbezogene
        // Information die Loeschung, weil ai_decisions.document_id absichtlich
        // NICHT kaskadierend geloescht wird (Art. 17 DSGVO).
        if ($documentIds->isNotEmpty()) {
            // Ueber die Modelle iterieren (nicht per Mass-Update), damit der
            // encrypted:array-Cast beim Schreiben tatsaechlich greift.
            AiDecision::whereIn('document_id', $documentIds)->get()->each(
                fn ($decision) => $decision->update(['output' => ['redacted_on_customer_deletion' => true]])
            );
        }

        // Physische Dokumentdateien (beide Disks) + Kundenverzeichnis
        foreach ($customer->documents()->get() as $doc) {
            try {
                Storage::disk($doc->disk ?: 'public')->delete($doc->file_path);
            } catch (\Throwable $e) {
                \Log::warning('Dokumentdatei bei Kundenlöschung nicht entfernbar: '.$doc->file_path);
            }
        }
        Storage::disk('local')->deleteDirectory('customers/'.$customer->id);

        // E-Mail-Volltexte + Anhangdateien des Kunden
        foreach (EmailMessage::where('customer_id', $customer->id)->get() as $mail) {
            $this->attachments->deleteFiles($mail);
            $mail->delete();
        }

        // Archivierte Huellen zusammengefuehrter Akten (KI-064) und die
        // stillgelegten Zugaenge aus diesen Zusammenfuehrungen gehen mit:
        // es sind Daten DIESES Kunden, auch wenn keine Liste sie zeigt
        // (Art. 17 DSGVO). Die Zeilen selbst entfernt die FK-Kaskade
        // (merged_into_id, customer_merges); die Konten muss man einsammeln,
        // BEVOR die Protokolle verschwinden.
        $nebenKonten = Customer::mitArchiv()->where('merged_into_id', $customer->id)->pluck('user_id')
            ->merge(CustomerMerge::where('primary_customer_id', $customer->id)->get()
                ->map(fn (CustomerMerge $m) => $m->protokoll['konto']['verlierer_user_id'] ?? null))
            ->filter()->unique()->values();

        // Kunde (FK-Kaskaden: Verträge, Tickets, Notizen, Familie,
        // Dokumente-Zeilen, Timeline, Aufgaben, Dokumentanfragen, ...)
        $customer->delete();

        foreach ($nebenKonten as $kontoId) {
            $konto = User::find($kontoId);
            // Nur Kundenkonten, und nur, wenn KEINE Akte (auch keine
            // archivierte) mehr darauf zeigt.
            if ($konto && $konto->role === 'customer'
                && ! Customer::mitArchiv()->where('user_id', $konto->id)->exists()) {
                $konto->delete();
            }
        }

        // Portal-Login-Account entfernen – aber NUR echte Kunden-Accounts.
        // Sollte ein Kundendatensatz (etwa durch fehlerhaften Import) mit einem
        // Mitarbeiter-/Admin-Konto verknüpft sein, darf dieses NIEMALS mitgelöscht
        // werden (sonst sperrt man sich beim Massen-Löschen selbst aus).
        if ($user && $user->role === 'customer') {
            $user->delete();
        }

        ActivityLog::create([
            'user_id' => $actorId,
            'action' => 'customer_deleted',
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
            'meta' => json_encode(['customer_number' => $customerNumber], JSON_UNESCAPED_UNICODE),
        ]);
    }
}
