<?php

namespace App\Console\Commands;

use App\Console\Concerns\ProcessesRecordsSafely;
use App\Mail\DocumentRequestMail;
use App\Models\DocumentRequest;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Facades\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Fristen-Watchdog für Dokumentenanfragen (Phase 3, Prüfbericht M3 /
 * Plan Abschnitt 14): erinnert Kunden kurz vor Fristablauf und
 * benachrichtigt Mitarbeiter bei Überschreitung. Jede Stufe feuert
 * genau einmal (reminder_sent_at / overdue_notified_at).
 */
class RemindDocumentRequests extends Command
{
    use ProcessesRecordsSafely;

    protected $signature = 'document-requests:remind';
    protected $description = 'Erinnert Kunden vor Fristablauf offener Dokumentenanfragen und meldet Überschreitungen intern';

    public function handle(): int
    {
        $reminded = $this->remindUpcoming();
        $escalated = $this->notifyOverdue();

        $this->info("$reminded Erinnerung(en) an Kunden, $escalated Überfälligkeits-Hinweis(e) intern.");

        return $this->ergebnisMitUebersprungenen(self::SUCCESS);
    }

    /** Frist in <= 2 Tagen: Kunde einmalig erinnern. */
    private function remindUpcoming(): int
    {
        $due = DocumentRequest::with('customer.user')
            ->openForCustomer()
            ->whereNull('reminder_sent_at')
            ->whereNotNull('deadline')
            ->whereBetween('deadline', [today(), today()->addDays(2)])
            ->get();

        // Je Anfrage abgesichert. Ohne das beendete EINE abgelehnte Adresse
        // den ganzen Lauf - alle weiteren Kunden bekamen ihre Fristen-
        // Erinnerung nie, und weil reminder_sent_at erst NACH dem Versand
        // gesetzt wird, blockierte derselbe Datensatz auch jeden Folgetag.
        $versendet = 0;
        $this->verarbeiteEinzeln($due, function (DocumentRequest $request) use (&$versendet) {
            $email = $request->customer?->user?->email;
            if ($email && ! str_contains($email, '@dienstly24.internal')) {
                Mail::to($email)->send(new DocumentRequestMail($request));
                $versendet++;
            }
            // Erst nach erfolgreichem Versand als erinnert markieren: ein
            // voruebergehender Mailfehler soll es am naechsten Tag erneut
            // versuchen. Bleibt eine Adresse dauerhaft kaputt, meldet der
            // Lauf sie taeglich - sichtbar auf /admin/systemzustand, statt
            // dass der Kunde still nie erinnert wird.
            $request->forceFill(['reminder_sent_at' => now()])->save();
        }, 'Dokumentenanfrage');

        return $versendet;
    }

    /** Frist überschritten: Betreuer (Fallback admin/manager) einmalig informieren. */
    private function notifyOverdue(): int
    {
        $overdue = DocumentRequest::with('customer.user')
            ->openForCustomer()
            ->whereNull('overdue_notified_at')
            ->whereNotNull('deadline')
            ->whereDate('deadline', '<', today())
            ->get();

        return $this->verarbeiteEinzeln($overdue, function (DocumentRequest $request) {
            $recipients = $request->customer?->betreuer()->get() ?? collect();
            if ($recipients->isEmpty()) {
                $recipients = User::whereIn('role', ['admin', 'manager'])->where('is_active', true)->get();
            }
            Notify::pushMany($recipients->pluck('id'), [
                'type' => NotificationService::TYPE_DOCUMENT,
                'title' => 'Dokumentenanfrage überfällig: '.$request->title,
                'body' => ($request->customer?->user?->name ?? 'Kunde').' hat die Frist '.$request->deadline->format('d.m.Y').' überschritten.',
                'link' => route('admin.document_requests'),
                'dedup_key' => 'doc-overdue-'.$request->id,
            ]);
            $request->forceFill(['overdue_notified_at' => now()])->save();
        }, 'Dokumentenanfrage');
    }
}
