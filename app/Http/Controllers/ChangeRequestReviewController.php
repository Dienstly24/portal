<?php

namespace App\Http\Controllers;

use App\Models\ChangeRequestDocument;
use App\Models\CustomerChangeRequest;
use App\Models\CustomerMessage;
use App\Services\ChangeRequest\ChangeProofVerifier;
use App\Services\ChangeRequestService;
use App\Services\CustomerMessageNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Mitarbeiter-Bereich "Kundenänderungen": offene Self-Service-Anträge
 * prüfen, genehmigen oder ablehnen. Erst bei Genehmigung wendet der
 * ChangeRequestService die Daten an (in einer Transaktion).
 *
 * Zu jeder sensiblen Änderung (Bank, Adresse, Name) liegen der Nachweis
 * des Kunden und das Ergebnis der automatischen Prüfung vor - der
 * Mitarbeiter sieht auf einen Blick, ob der beantragte Wert wirklich im
 * Dokument steht. Rückfragen ("ab wann gilt das?") gehen mit einem Klick
 * als Chat-Nachricht an den Kunden.
 *
 * Sichtbarkeit: admin/manager alles, support/employee nur zugewiesene
 * Kunden (inkl. Vertretungen) - via Policy und Listen-Scoping.
 */
class ChangeRequestReviewController extends Controller
{
    /** Vorschläge für die Rückfrage im Chat (ein Klick statt Abtippen). */
    public const QUICK_QUESTIONS = [
        'effective' => 'ab wann die Änderung gelten soll',
        'proof' => 'einen Nachweis (Ausweis / Meldebescheinigung)',
        'quality' => 'ein besser lesbares Foto des Nachweises',
    ];

    public function index(Request $request)
    {
        $status = in_array($request->query('status'), ['pending', 'approved', 'rejected'], true)
            ? $request->query('status') : 'pending';

        $query = CustomerChangeRequest::with(['customer.user', 'requester', 'reviewer', 'documents'])
            ->withCount(['notifications', 'notifications as open_notifications' => fn ($q) => $q->where('status', 'pending')])
            ->where('status', $status)
            ->orderBy('created_at', $status === 'pending' ? 'asc' : 'desc');

        // Gleiche Portfolio-Sichtbarkeit wie überall im Admin-Bereich
        $user = auth()->user();
        if (! $user->canSeeAllCustomers()) {
            $query->whereIn('customer_id', $user->visibleCustomerIdsWithSubstitution());
        }

        return view('admin.change_requests', [
            'requests' => $query->paginate(25)->withQueryString(),
            'status' => $status,
            'counts' => [
                'pending' => $this->scopedCount('pending'),
                'approved' => $this->scopedCount('approved'),
                'rejected' => $this->scopedCount('rejected'),
            ],
        ]);
    }

    private function scopedCount(string $status): int
    {
        $q = CustomerChangeRequest::where('status', $status);
        $user = auth()->user();
        if (! $user->canSeeAllCustomers()) {
            $q->whereIn('customer_id', $user->visibleCustomerIdsWithSubstitution());
        }
        return $q->count();
    }

    /** Eingereichtes Dokument eines Change Requests sicher ausliefern. */
    public function document($id)
    {
        $changeRequest = CustomerChangeRequest::findOrFail($id);
        $this->authorize('review', $changeRequest);

        $path = $changeRequest->new_data['document_path'] ?? null;
        abort_if(! $path, 404);
        $disk = $changeRequest->new_data['document_disk'] ?? 'public';
        abort_unless(Storage::disk($disk)->exists($path), 404);
        return Storage::disk($disk)->download(
            $path, $changeRequest->new_data['document_name'] ?? basename($path)
        );
    }

    /**
     * Nachweis (Ausweis, Meldebescheinigung, Kontonachweis) anzeigen -
     * inline für Bilder/PDF, damit der Mitarbeiter ihn direkt neben den
     * beantragten Daten sieht. Immer über die private Disk und den
     * Portfolio-Check, nie per öffentlicher URL.
     */
    public function proof($id, Request $request)
    {
        $document = ChangeRequestDocument::with('changeRequest')->findOrFail($id);
        $this->authorize('review', $document->changeRequest);

        $disk = Storage::disk($document->disk ?: 'local');
        abort_unless($disk->exists($document->file_path), 404);

        if ($request->boolean('download') || ! $document->isViewable()) {
            return $disk->download($document->file_path, $document->file_name);
        }

        return $disk->response($document->file_path, $document->file_name, [
            'Content-Type' => $document->mimeType(),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Prüfung erneut anstoßen (z.B. nachdem OCR auf dem Server aktiviert wurde). */
    public function recheck($id, ChangeProofVerifier $verifier)
    {
        $changeRequest = CustomerChangeRequest::with('documents')->findOrFail($id);
        $this->authorize('review', $changeRequest);

        if ($changeRequest->documents()->count() === 0) {
            return back()->with('error', 'Zu dieser Anfrage liegt kein Nachweis vor.');
        }
        if (! $verifier->isAvailable()) {
            return back()->with('error', 'Automatische Prüfung nicht verfügbar (OCR ist auf dem Server nicht aktiviert).');
        }

        $result = $verifier->verify($changeRequest);
        $state = CustomerChangeRequest::PROOF_STATES[$result['status']] ?? [];

        return back()->with('success', 'Nachweis erneut geprüft: '.($state['label'] ?? $result['status']));
    }

    /**
     * Rückfrage an den Kunden: legt eine Chat-Nachricht an (gleicher Weg
     * wie der Kunden-Chat) und führt den Mitarbeiter direkt in die
     * Unterhaltung. So bleibt die Frage "ab wann gilt Ihre neue Adresse?"
     * dort, wo die Antwort später ankommt.
     */
    public function ask(Request $request, $id)
    {
        $changeRequest = CustomerChangeRequest::with('customer.user')->findOrFail($id);
        $this->authorize('review', $changeRequest);

        $data = $request->validate([
            'body' => 'required|string|max:2000',
            'email_mode' => 'nullable|in:'.implode(',', CustomerMessage::EMAIL_MODES),
        ]);

        // Standard "hint": der Kunde bekommt nur den Hinweis auf eine neue
        // Nachricht per E-Mail, den Inhalt liest er im Portal (Datenschutz).
        $emailMode = $data['email_mode'] ?? 'hint';

        $message = CustomerMessage::create([
            'customer_id' => $changeRequest->customer_id,
            'sender_id' => auth()->id(),
            'body' => $data['body'],
            'from_staff' => true,
            'email_mode' => $emailMode,
        ]);
        CustomerMessageNotifier::notifyCustomer($message, $emailMode);

        return redirect()
            ->route('admin.customer_chat', ['kunde' => $changeRequest->customer_id])
            ->with('success', 'Rückfrage an den Kunden gesendet.');
    }

    public function action(Request $request, $id, ChangeRequestService $service)
    {
        $changeRequest = CustomerChangeRequest::with('customer.user')->findOrFail($id);
        $this->authorize('review', $changeRequest);

        $data = $request->validate([
            'action' => 'required|in:approve,reject',
            'notes' => 'nullable|string|max:1000',
        ]);

        $result = $data['action'] === 'approve'
            ? $service->approve($changeRequest, auth()->user(), $data['notes'] ?? null)
            : $service->reject($changeRequest, auth()->user(), $data['notes'] ?? null);

        if (! $result['ok']) {
            return back()->with('error', $result['error']);
        }

        if ($data['action'] === 'reject') {
            return back()->with('success', 'Anfrage abgelehnt.');
        }

        if ($result['notifications'] > 0) {
            return redirect()
                ->route('admin.change_requests.notifications', $changeRequest->id)
                ->with('success', 'Anfrage genehmigt – die Kundendaten wurden aktualisiert. '
                    .$result['notifications'].' Mitteilung(en) an Gesellschaften wurden vorbereitet.');
        }

        return back()->with('success', 'Anfrage genehmigt – die Kundendaten wurden aktualisiert.');
    }
}
