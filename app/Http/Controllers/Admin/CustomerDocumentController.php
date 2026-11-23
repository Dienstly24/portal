<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ScopesCustomerAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReplaceCustomerDocumentRequest;
use App\Http\Requests\Admin\StoreCustomerDocumentRequest;
use App\Http\Requests\Admin\UpdateCustomerDocumentRequest;
use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Support\Dateiname;
use App\Support\InlineDatei;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Dokumente an der Kundenakte (ARCH-5, aus AdminController herausgeloest).
 *
 * Hochladen, Ersetzen, Bearbeiten, Loeschen, Anzeigen und Herunterladen -
 * inklusive der Anhaenge von Gast-Anfragen. Rein mechanisch verschoben:
 * Routen, Berechtigungen (authorizeDocumentAccess aus dem gemeinsamen
 * Trait), Validierung und Antworten sind unveraendert.
 */
class CustomerDocumentController extends Controller
{
    use ScopesCustomerAccess;

    public function storeDocument(StoreCustomerDocumentRequest $request, $id) {
        // Die Zugriffspruefung bleibt hier: sie haengt am Kunden aus dem Pfad
        // und ist eine Berechtigungs-, keine Formatfrage (ARCH-6).
        $this->authorizeCustomerAccess($id);

        // Vertragszuordnung nur zulassen, wenn der Vertrag zu DIESEM Kunden gehört.
        $contractId = $request->filled('contract_id')
            ? Contract::where('id', $request->contract_id)->where('customer_id', $id)->value('id')
            : null;

        // Doppelt-Pruefung VOR dem Speichern (Betreiber-Meldung 23.11.2026,
        // KI-126): vorher wurde eine bereits vorhandene Datei kommentarlos ein
        // zweites Mal abgelegt - ohne Hinweis und ohne Angebot, die alte zu
        // ersetzen. Ohne Entscheidung (bei_konflikt) wird bei einem Konflikt
        // NICHTS gespeichert, sondern zurueckgefragt.
        $files = $request->file('documents');
        $konflikte = $this->findeKonflikte((string) $id, $files);
        $entscheidung = $request->input('bei_konflikt');

        if ($konflikte !== [] && $entscheidung === null) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'konflikte' => array_values($konflikte),
                    'message' => 'Mindestens eine Datei ist in dieser Akte bereits vorhanden.',
                ], 409);
            }
            $namen = implode(', ', array_map(fn ($k) => $k['file_name'], $konflikte));

            return back()->with('warning', 'Nicht hochgeladen - bereits in der Akte vorhanden: '.$namen.'. Bitte erneut hochladen und waehlen: ersetzen, ueberspringen oder beide behalten.');
        }

        $created = [];
        $ersetzt = [];
        $uebersprungen = [];
        foreach ($files as $index => $file) {
            $konflikt = $konflikte[$index] ?? null;

            if ($konflikt !== null && $entscheidung === 'ueberspringen') {
                $uebersprungen[] = $file->getClientOriginalName();
                continue;
            }
            // Identischer Inhalt + "ersetzen": die Datei ist schon da - ein
            // Austausch gegen sich selbst aendert nichts, also ueberspringen.
            if ($konflikt !== null && $entscheidung === 'ersetzen' && $konflikt['art'] === 'identisch') {
                $uebersprungen[] = $file->getClientOriginalName();
                continue;
            }
            if ($konflikt !== null && $entscheidung === 'ersetzen') {
                $alt = Document::where('customer_id', $id)->find($konflikt['document_id']);
                if ($alt !== null) {
                    $this->ersetzeDatei($alt, $file);
                    $ersetzt[] = $alt->file_name;
                    continue;
                }
            }

            // Neue Uploads landen grundsätzlich im privaten Storage.
            $path = $file->store("customers/$id/documents", 'local');
            $doc = Document::create([
                'id' => Str::uuid(),
                'customer_id' => $id,
                'contract_id' => $contractId,
                'category' => $request->category ?? 'other',
                'color' => $request->color ?? 'green',
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'disk' => 'local',
                // Über die Sichtbarkeit entscheidet ausschließlich der Mitarbeiter.
                'visibility' => $request->visibility ?? 'customer',
                'uploaded_by' => auth()->id(),
                'file_size' => $file->getSize(),
            ]);
            $created[] = $doc;

            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'document_uploaded',
                'entity_type' => 'document',
                'entity_id' => $doc->id,
                'meta' => json_encode(['customer_id' => (string) $id, 'file' => $doc->file_name, 'visibility' => $doc->visibility], JSON_UNESCAPED_UNICODE),
            ]);
        }

        $meldung = count($created).' Dokument(e) hochgeladen';
        if ($ersetzt !== []) {
            $meldung .= ', '.count($ersetzt).' ersetzt';
        }
        if ($uebersprungen !== []) {
            $meldung .= ', '.count($uebersprungen).' uebersprungen (bereits vorhanden)';
        }
        $meldung .= '.';

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'count' => count($created),
                'ersetzt' => count($ersetzt),
                'uebersprungen' => count($uebersprungen),
                'message' => $meldung,
            ]);
        }
        return back()->with('success', $meldung);
    }

    /** Sicherer Dokument-Download (Admin) - nur mit Zugriff auf den Kunden. */
    /** Datei eines bestehenden Dokuments austauschen - setzt updated_by. (Punkt 3) */
    public function documentReplace(ReplaceCustomerDocumentRequest $request, $id) {
        $doc = Document::findOrFail($id);
        $this->authorizeDocumentAccess($doc);

        $this->ersetzeDatei($doc, $request->file('document'));

        return back()->with('success', 'Dokument ersetzt.');
    }

    /**
     * Dokument-Metadaten bearbeiten: Vertragszuordnung, Kategorie, Sichtbarkeit
     * (intern/Kunde), Priorität und Anzeigename. Datei-Inhalt bleibt unberührt.
     */
    public function documentUpdate(UpdateCustomerDocumentRequest $request, $id) {
        $doc = Document::findOrFail($id);
        $this->authorizeDocumentAccess($doc);

        // Vertrag muss zum selben Kunden gehören (Fremdzuordnung verhindern).
        $contractId = $request->filled('contract_id')
            ? Contract::where('id', $request->contract_id)->where('customer_id', $doc->customer_id)->value('id')
            : null;

        $doc->update([
            'contract_id' => $contractId,
            'category' => $request->category ?: $doc->category,
            'visibility' => $request->visibility ?: $doc->visibility,
            'color' => $request->color ?: ($doc->color ?? 'green'),
            'file_name' => $request->filled('file_name') ? $request->file_name : $doc->file_name,
            'updated_by' => auth()->id(),
        ]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'document_updated',
            'entity_type' => 'document',
            'entity_id' => $doc->id,
            'meta' => json_encode(['customer_id' => (string) $doc->customer_id, 'visibility' => $doc->visibility, 'contract_id' => $contractId], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'Dokument aktualisiert.');
    }

    /** Dokument löschen (Datei + Datensatz). Nur mit Zugriff auf den Kunden. */
    public function documentDestroy($id) {
        $doc = Document::findOrFail($id);
        $this->authorizeDocumentAccess($doc);

        try {
            Storage::disk($doc->disk ?: 'public')->delete($doc->file_path);
        } catch (\Throwable $e) { /* Datei evtl. schon weg - Datensatz trotzdem entfernen */ }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'document_deleted',
            'entity_type' => 'document',
            'entity_id' => $doc->id,
            'meta' => json_encode(['customer_id' => (string) $doc->customer_id, 'file' => $doc->file_name], JSON_UNESCAPED_UNICODE),
        ]);

        $doc->delete();

        return back()->with('success', 'Dokument gelöscht.');
    }

    /**
     * Aufloesung fuer direkte Dokument-Links (GET /admin/documents/{id}).
     * Eine eigene Detailseite existiert nicht - der Aufruf kommt aus dem
     * Browser-Verlauf (Formular-Action des Bearbeiten-/Loeschen-Dialogs),
     * aus alten Lesezeichen oder von Hand gekuerzten Download-Links.
     * Statt einer 404-Sackgasse: dorthin weiterleiten, wo das Dokument
     * tatsaechlich liegt (Kundenakte bzw. Dokumenten-Eingang).
     */
    public function documentShow($id) {
        $doc = Document::find($id);
        if (! $doc) {
            return redirect()->route('admin.documents.inbox')
                ->with('warning', 'Dokument nicht gefunden - es wurde geloescht oder der Link ist veraltet.');
        }
        $this->authorizeDocumentAccess($doc);
        if ($doc->customer_id !== null) {
            return redirect()->to(route('admin.customer', $doc->customer_id).'#tab-dokumente');
        }
        return redirect()->route('admin.documents.inbox');
    }

    public function documentDownload(Request $request, $id) {
        $doc = Document::findOrFail($id);
        $this->authorizeDocumentAccess($doc);
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'document_viewed',
            'entity_type' => 'document',
            'entity_id' => $doc->id,
            'meta' => json_encode(['file' => $doc->file_name], JSON_UNESCAPED_UNICODE),
        ]);
        $disk = $doc->disk ?: 'public';
        abort_unless(Storage::disk($disk)->exists($doc->file_path), 404);
        // ?view=1 -> im Browser anzeigen (Vorschau, z.B. "Anzeigen"-Button im
        // Dokumenten-Eingang); sonst herunterladen. "Anzeigen" nur fuer
        // Typen, die der Browser nicht ausfuehrt - Dokumente kommen auch
        // aus E-Mails fremder Absender (KI-025, InlineDatei).
        return $request->boolean('view')
            ? InlineDatei::antwort(Storage::disk($disk), $doc->file_path, $doc->file_name)
            : Storage::disk($disk)->download($doc->file_path, $doc->file_name);
    }

    public function downloadAttachment($id) {
        $a = TicketAttachment::findOrFail($id);
        $ticket = Ticket::findOrFail($a->ticket_id);
        $this->authorizeTicketAccess($ticket);
        if (($a->disk ?? 'public') === 'local') {
            return Storage::disk('local')->download($a->file_path, $a->file_name);
        }
        // 404 statt 500, wenn die Datei fehlt (z. B. manuell geloescht)
        abort_unless(is_file(storage_path('app/public/'.$a->file_path)), 404);
        return response()->download(storage_path('app/public/'.$a->file_path), $a->file_name);
    }

    /**
     * Datei eines vorhandenen Dokuments austauschen. Der Datensatz (ID,
     * Kategorie, Vertrag, Sichtbarkeit, Verlauf) bleibt, nur die Datei wird neu.
     * Der Inhalts-Hash wird NEU bestimmt - vorher behielt ein ersetztes
     * Dokument den Hash der ALTEN Datei, und die Doppelt-Erkennung meldete
     * danach die falsche Datei als vorhanden.
     */
    private function ersetzeDatei(Document $doc, UploadedFile $file): void
    {
        $newPath = $file->store('customers/'.$doc->customer_id.'/documents', 'local');

        // Alte Datei entfernen (best effort), dann DB aktualisieren
        try { Storage::disk($doc->disk ?: 'public')->delete($doc->file_path); } catch (\Throwable $e) {}

        $doc->update([
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $newPath,
            'disk' => 'local',
            'file_size' => $file->getSize(),
            'content_hash' => Document::hashStoredFile('local', $newPath),
            'duplicate_of' => null,
            'updated_by' => auth()->id(),
        ]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'document_replaced',
            'entity_type' => 'document',
            'entity_id' => $doc->id,
            'meta' => json_encode(['customer_id' => (string) $doc->customer_id, 'file' => $doc->file_name], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Welche der hochgeladenen Dateien liegen in DIESER Akte schon vor?
     * - "identisch": gleicher Inhalt (SHA-256), egal unter welchem Namen.
     * - "gleicher_name": gleicher Dateiname, aber anderer Inhalt - typisch
     *   eine neue Fassung desselben Dokuments; dann wird "ersetzen" angeboten.
     * Gesucht wird nur in der eigenen Akte: ein Treffer bei einem fremden
     * Kunden darf hier weder Name noch Existenz verraten.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array<string, mixed>>  Schluessel = Index der Datei
     */
    private function findeKonflikte(string $customerId, array $files): array
    {
        $vorhanden = Document::where('customer_id', $customerId)
            ->get(['id', 'file_name', 'content_hash', 'created_at']);
        if ($vorhanden->isEmpty()) {
            return [];
        }

        $konflikte = [];
        $imStapel = [];
        foreach ($files as $index => $file) {
            $hash = @hash_file('sha256', $file->getRealPath()) ?: null;
            $name = self::normalisiereDateiname($file->getClientOriginalName());

            $treffer = $hash !== null ? $vorhanden->firstWhere('content_hash', $hash) : null;
            $art = 'identisch';
            if ($treffer === null) {
                $treffer = $vorhanden->first(fn ($d) => self::normalisiereDateiname((string) $d->file_name) === $name);
                $art = 'gleicher_name';
            }
            if ($treffer === null) {
                continue;
            }
            // Dieselbe Datei zweimal in EINER Auswahl: nur einmal melden.
            $schluessel = $treffer->id.'|'.$art;
            if (isset($imStapel[$schluessel])) {
                continue;
            }
            $imStapel[$schluessel] = true;

            $konflikte[$index] = [
                'index' => $index,
                'file_name' => $file->getClientOriginalName(),
                'art' => $art,
                'document_id' => (string) $treffer->id,
                'vorhanden_name' => $treffer->file_name,
                'vorhanden_seit' => LocalTime::for($treffer->created_at)?->format('d.m.Y'),
            ];
        }

        return $konflikte;
    }

    /**
     * Dateiname fuer den Vergleich: klein, ohne Browser-Kopienzusatz
     * ("Police (1).pdf" = "Police.pdf") und ohne doppelte Leerzeichen.
     */
    public static function normalisiereDateiname(string $name): string
    {
        $name = mb_strtolower(trim(Dateiname::dekodiere($name)));
        $name = (string) preg_replace('/\s*\(\d+\)(?=\.[^.]+$|$)/u', '', $name);

        return (string) preg_replace('/\s+/u', ' ', $name);
    }
}
