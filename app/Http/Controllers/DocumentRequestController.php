<?php
namespace App\Http\Controllers;

use App\Mail\DocumentRequestMail;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DocumentRequest;
use App\Models\InternalNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Mitarbeiter-Seite der Dokumentenanfragen (Architekturplan
 * Abschnitte 9/14, Priorität 7): anlegen (mit Kundenbenachrichtigung),
 * prüfen (freigeben/zurückweisen). Eigener Controller statt Anbau an
 * den AdminController (Plan Abschnitt 20.5).
 */
class DocumentRequestController extends Controller
{
    public function index()
    {
        $awaitingReview = DocumentRequest::with(['customer.user', 'contract', 'document'])
            ->awaitingReview()->orderBy('uploaded_at')->get();
        $open = DocumentRequest::with(['customer.user', 'contract'])
            ->openForCustomer()->orderBy('deadline')->get();

        return view('admin.document_requests', compact('awaitingReview', 'open'));
    }

    public function store(Request $request, $customerId)
    {
        $customer = Customer::with('user')->findOrFail($customerId);
        abort_unless(auth()->user()->canAccessCustomer($customer->id), 403);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'deadline' => 'nullable|date|after_or_equal:today',
            'contract_id' => 'nullable|uuid',
        ]);

        if (!empty($data['contract_id'])) {
            // Vertrag muss zum Kunden gehören - keine Fremdzuordnung.
            abort_unless($customer->contracts()->where('id', $data['contract_id'])->exists(), 422);
        }

        $documentRequest = DocumentRequest::create($data + [
            'customer_id' => $customer->id,
            'status' => 'open',
            'requested_by' => auth()->id(),
        ]);

        // Kunde informieren (Abschnitt 14) - die Mitarbeiter-Aktion ist
        // die Freigabestufe, daher direkt versendbar.
        if ($customer->user?->email) {
            Mail::to($customer->user->email)->send(new DocumentRequestMail($documentRequest));
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'document_request_created',
            'entity_type' => 'document_request',
            'entity_id' => $documentRequest->id,
            'meta' => json_encode(['customer_id' => (string) $customer->id, 'title' => $data['title']], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'Dokumentenanfrage erstellt und Kunde benachrichtigt.');
    }

    public function approve($id)
    {
        $documentRequest = DocumentRequest::findOrFail($id);
        abort_unless(auth()->user()->canAccessCustomer($documentRequest->customer_id), 403);
        if ($documentRequest->status !== 'uploaded') {
            return back()->with('error', 'Diese Anfrage wartet nicht auf Prüfung.');
        }

        $documentRequest->update([
            'status' => 'approved',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'document_request_approved',
            'entity_type' => 'document_request',
            'entity_id' => $documentRequest->id,
            'meta' => json_encode(['customer_id' => (string) $documentRequest->customer_id], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'Dokument freigegeben – Anfrage abgeschlossen.');
    }

    public function reject(Request $request, $id)
    {
        $documentRequest = DocumentRequest::with('customer.user')->findOrFail($id);
        abort_unless(auth()->user()->canAccessCustomer($documentRequest->customer_id), 403);
        if ($documentRequest->status !== 'uploaded') {
            return back()->with('error', 'Diese Anfrage wartet nicht auf Prüfung.');
        }

        $data = $request->validate(['rejection_note' => 'required|string|max:1000']);

        $documentRequest->update([
            'status' => 'rejected',
            'rejection_note' => $data['rejection_note'],
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        // Kunde erneut informieren, mit Begründung.
        if ($documentRequest->customer->user?->email) {
            Mail::to($documentRequest->customer->user->email)->send(new DocumentRequestMail($documentRequest));
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'document_request_rejected',
            'entity_type' => 'document_request',
            'entity_id' => $documentRequest->id,
            'meta' => json_encode(['customer_id' => (string) $documentRequest->customer_id, 'note' => $data['rejection_note']], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'Dokument zurückgewiesen – Kunde wurde informiert.');
    }
}
