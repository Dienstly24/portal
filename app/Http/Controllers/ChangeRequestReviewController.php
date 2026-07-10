<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CustomerChangeRequest;
use App\Services\ChangeRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mitarbeiter-Bereich "Kundenänderungen": offene Self-Service-Anträge
 * prüfen, genehmigen oder ablehnen. Erst bei Genehmigung wendet der
 * ChangeRequestService die Daten an (in einer Transaktion).
 * Sichtbarkeit: admin/manager alles, support/employee nur zugewiesene
 * Kunden (inkl. Vertretungen) - via Policy und Listen-Scoping.
 */
class ChangeRequestReviewController extends Controller
{
    public function index(Request $request)
    {
        $status = in_array($request->query('status'), ['pending', 'approved', 'rejected'], true)
            ? $request->query('status') : 'pending';

        $query = CustomerChangeRequest::with(['customer.user', 'requester', 'reviewer'])
            ->where('status', $status)
            ->orderBy('created_at', $status === 'pending' ? 'asc' : 'desc');

        // Gleiche Portfolio-Sichtbarkeit wie überall im Admin-Bereich
        $user = auth()->user();
        if (!$user->canSeeAllCustomers()) {
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
        if (!$user->canSeeAllCustomers()) {
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
        abort_if(!$path, 404);
        $disk = $changeRequest->new_data['document_disk'] ?? 'public';
        abort_unless(\Illuminate\Support\Facades\Storage::disk($disk)->exists($path), 404);
        return \Illuminate\Support\Facades\Storage::disk($disk)->download(
            $path, $changeRequest->new_data['document_name'] ?? basename($path)
        );
    }

    public function action(Request $request, $id, ChangeRequestService $service)
    {
        $changeRequest = CustomerChangeRequest::findOrFail($id);
        $this->authorize('review', $changeRequest);

        $data = $request->validate([
            'action' => 'required|in:approve,reject',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($changeRequest->status !== 'pending') {
            return back()->with('error', 'Diese Anfrage wurde bereits bearbeitet.');
        }

        DB::transaction(function () use ($changeRequest, $data, $service) {
            if ($data['action'] === 'approve') {
                // Erst anwenden - schlägt das fehl, bleibt der Antrag pending
                $service->apply($changeRequest);
            }

            $changeRequest->update([
                'status' => $data['action'] === 'approve' ? 'approved' : 'rejected',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            // Punkt 11: Audit-Log ("Admin Ahmad genehmigte neue Bankverbindung")
            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => $data['action'] === 'approve' ? 'change_request_approved' : 'change_request_rejected',
                'entity_type' => 'change_request',
                'entity_id' => $changeRequest->id,
                'meta' => json_encode([
                    'customer' => $changeRequest->customer?->user?->name,
                    'customer_id' => (string) $changeRequest->customer_id,
                    'type' => $changeRequest->type,
                    'type_label' => $changeRequest->typeLabel(),
                    'notes' => $data['notes'] ?? null,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        });

        $this->notifyCustomer($changeRequest, $data['action'], $data['notes'] ?? null);

        return back()->with('success', $data['action'] === 'approve'
            ? 'Anfrage genehmigt – die Kundendaten wurden aktualisiert.'
            : 'Anfrage abgelehnt.');
    }

    /**
     * Statusmeldung an den Kunden (Review Punkt 8, Schritt 5):
     * Portal-Glocke informiert über Genehmigung/Ablehnung.
     */
    private function notifyCustomer(CustomerChangeRequest $changeRequest, string $action, ?string $notes): void
    {
        $userId = $changeRequest->customer?->user_id;
        if (!$userId) {
            return;
        }
        $approved = $action === 'approve';
        \App\Models\InternalNotification::create([
            'user_id' => $userId,
            'title' => 'Änderungsanfrage ' . ($approved ? 'genehmigt' : 'abgelehnt'),
            'body' => 'Ihre Änderung (' . $changeRequest->typeLabel() . ') wurde '
                . ($approved ? 'genehmigt und übernommen.' : 'abgelehnt.' . ($notes ? ' Grund: ' . \Illuminate\Support\Str::limit($notes, 120) : '')),
            'link' => route('portal.change_requests'),
        ]);
    }
}
