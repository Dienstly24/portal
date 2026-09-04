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
use Illuminate\Http\Request;
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

        $created = [];
        foreach ($request->file('documents') as $file) {
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

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'count' => count($created)]);
        }
        return back()->with('success', count($created).' Dokument(e) hochgeladen.');
    }

    /** Sicherer Dokument-Download (Admin) - nur mit Zugriff auf den Kunden. */
    /** Datei eines bestehenden Dokuments austauschen - setzt updated_by. (Punkt 3) */
    public function documentReplace(ReplaceCustomerDocumentRequest $request, $id) {
        $doc = Document::findOrFail($id);
        $this->authorizeDocumentAccess($doc);

        $file = $request->file('document');
        $newPath = $file->store('customers/'.$doc->customer_id.'/documents', 'local');

        // Alte Datei entfernen (best effort), dann DB aktualisieren
        try { Storage::disk($doc->disk ?: 'public')->delete($doc->file_path); } catch (\Throwable $e) {}

        $doc->update([
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $newPath,
            'disk' => 'local',
            'file_size' => $file->getSize(),
            'updated_by' => auth()->id(),
        ]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'document_replaced',
            'entity_type' => 'document',
            'entity_id' => $doc->id,
            'meta' => json_encode(['customer_id' => (string) $doc->customer_id, 'file' => $doc->file_name], JSON_UNESCAPED_UNICODE),
        ]);

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
        // Dokumenten-Eingang); sonst herunterladen.
        return $request->boolean('view')
            ? Storage::disk($disk)->response($doc->file_path, $doc->file_name)
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
}
