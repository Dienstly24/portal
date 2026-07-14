<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AiDecision;
use App\Models\Commission;
use App\Models\Customer;
use App\Models\CustomerChangeRequest;
use App\Models\DocumentRequest;
use App\Models\EmailMessage;
use App\Models\Ticket;
use App\Services\FondsFinanz\FondsFinanzImportService;
use App\Services\Mailbox\EmailAttachmentService;
use Illuminate\Http\Request;

/**
 * Zentrale Arbeitsliste für die E-Mail-Verarbeitung (Architekturplan
 * Abschnitt 11: "Aufgaben-Inbox" als wichtigster neuer Bildschirm):
 * Zuordnungsvorschläge bestätigen (HITL 70-90%), nicht zugeordnete
 * Mails manuell einem Kunden zuweisen, Überblick über die anderen
 * Freigabe-Warteschlangen (Provisionen, Dokumentenanfragen,
 * Kundenänderungen).
 */
class EmailInboxController extends Controller
{
    public function __construct(
        private readonly FondsFinanzImportService $fondsFinanz,
        private readonly EmailAttachmentService $attachments,
    ) {
    }

    public function index()
    {
        $suggested = EmailMessage::with(['customer.user', 'account'])
            ->where('match_status', 'suggested')
            ->orderBy('received_at')->get();

        $unmatched = EmailMessage::with(['account', 'aiDecisions' => fn ($q) => $q->suggested()])
            ->where('match_status', 'unmatched')
            ->whereNull('customer_id')
            ->whereNotNull('processed_at')
            ->latest('received_at')->limit(50)->get();

        $queues = [
            'commissions' => Commission::pendingReview()->count(),
            'document_requests' => DocumentRequest::awaitingReview()->count(),
            'change_requests' => CustomerChangeRequest::pending()->count(),
        ];

        return view('admin.email_inbox', compact('suggested', 'unmatched', 'queues'));
    }

    /**
     * Einzelne E-Mail vollstaendig anzeigen (Betreff, Absender, Body,
     * Anhaenge, Verarbeitungsstatus). Behebt die Luecke "man kommt nicht
     * an die Original-Mail heran" - von hier aus laesst sich zuordnen,
     * bestaetigen oder der Anhang oeffnen.
     */
    public function show($id)
    {
        $message = EmailMessage::with(['customer.user', 'account'])->findOrFail($id);

        // DSGVO/Zugriff: ist die Mail bereits einem Kunden zugeordnet, darf
        // sie nur sehen, wer auch auf diesen Kunden zugreifen darf.
        if ($message->customer_id !== null) {
            abort_unless(auth()->user()->canAccessCustomer($message->customer_id), 403);
        }

        $tasks = \App\Models\Task::where('email_message_id', $message->id)
            ->with('assignedTo')->latest()->get();

        return view('admin.email_message', compact('message', 'tasks'));
    }

    /** Anhang einer E-Mail herunterladen (aus dem Roh-Speicher). */
    public function downloadAttachment($id, int $index)
    {
        $message = EmailMessage::findOrFail($id);
        if ($message->customer_id !== null) {
            abort_unless(auth()->user()->canAccessCustomer($message->customer_id), 403);
        }

        $entry = ($message->attachments_meta ?? [])[$index] ?? null;
        abort_if($entry === null, 404);
        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($entry['path']), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->download($entry['path'], $entry['filename']);
    }

    /** Vorgeschlagene Zuordnung bestätigen (Ein-Klick, Abschnitt 13). */
    public function confirm($id)
    {
        $message = EmailMessage::with('customer')->findOrFail($id);
        if ($message->match_status !== 'suggested' || $message->customer === null) {
            return back()->with('error', 'Diese E-Mail wartet nicht auf Bestätigung.');
        }
        // Audit-Fix H2: Bestätigen nur mit Zugriff auf den vorgeschlagenen Kunden.
        abort_unless(auth()->user()->canAccessCustomer($message->customer_id), 403);

        $this->applyAssignment($message, $message->customer, 'email_match_confirmed');

        return back()->with('success', 'Zuordnung bestätigt.');
    }

    /** Vorgeschlagene Zuordnung ablehnen -> zurück in die manuelle Liste. */
    public function reject($id)
    {
        $message = EmailMessage::findOrFail($id);
        if ($message->match_status !== 'suggested') {
            return back()->with('error', 'Diese E-Mail wartet nicht auf Bestätigung.');
        }
        // Audit-Fix H2: Auch Ablehnen ist eine Entscheidung über diesen Kunden.
        if ($message->customer_id !== null) {
            abort_unless(auth()->user()->canAccessCustomer($message->customer_id), 403);
        }

        $message->forceFill(['match_status' => 'unmatched', 'customer_id' => null, 'match_score' => null])->save();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'email_match_rejected',
            'entity_type' => 'email_message',
            'entity_id' => $message->id,
            'meta' => json_encode(['subject' => $message->subject], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'Vorschlag abgelehnt – E-Mail liegt zur manuellen Zuordnung.');
    }

    /** Manuelle Zuordnung einer nicht erkannten E-Mail zu einem Kunden. */
    public function assign(Request $request, $id)
    {
        $message = EmailMessage::findOrFail($id);
        if ($message->customer_id !== null || $message->match_status === 'suggested') {
            return back()->with('error', 'Diese E-Mail ist bereits zugeordnet oder wartet auf Bestätigung.');
        }

        $data = $request->validate(['customer_id' => 'required|uuid']);
        $customer = Customer::findOrFail($data['customer_id']);
        abort_unless(auth()->user()->canAccessCustomer($customer->id), 403);

        $this->applyAssignment($message, $customer, 'email_manually_assigned');

        return back()->with('success', 'E-Mail dem Kunden zugeordnet.');
    }

    /** KI-Kategorievorschlag übernehmen (Phase 3, Freigabe-Gateway). */
    public function aiAccept($decisionId, \App\Services\Workflow\EmailWorkflowService $workflow)
    {
        $decision = AiDecision::with('emailMessage')->findOrFail($decisionId);
        if ($decision->status !== 'suggested' || $decision->emailMessage === null) {
            return back()->with('error', 'Dieser Vorschlag wurde bereits bearbeitet.');
        }

        $workflow->applyCategory($decision->emailMessage, $decision->output['category']);
        $decision->update(['status' => 'accepted', 'decided_by' => auth()->id(), 'decided_at' => now()]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'ai_suggestion_accepted',
            'entity_type' => 'ai_decision',
            'entity_id' => $decision->id,
            'meta' => json_encode(['category' => $decision->output['category'], 'confidence' => $decision->confidence], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'KI-Vorschlag übernommen – Kategorie angewendet.');
    }

    /** KI-Kategorievorschlag verwerfen. */
    public function aiReject($decisionId)
    {
        $decision = AiDecision::findOrFail($decisionId);
        if ($decision->status !== 'suggested') {
            return back()->with('error', 'Dieser Vorschlag wurde bereits bearbeitet.');
        }

        $decision->update(['status' => 'rejected', 'decided_by' => auth()->id(), 'decided_at' => now()]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'ai_suggestion_rejected',
            'entity_type' => 'ai_decision',
            'entity_id' => $decision->id,
            'meta' => json_encode(['category' => $decision->output['category'] ?? null], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'KI-Vorschlag verworfen.');
    }

    private function applyAssignment(EmailMessage $message, Customer $customer, string $logAction): void
    {
        if ($message->category === 'fonds_finanz') {
            // Bestätigter FF-Kunde: jetzt den eigentlichen Import ausführen.
            $this->fondsFinanz->importForCustomer($message, $customer);
        } else {
            $message->forceFill(['match_status' => 'confirmed', 'customer_id' => $customer->id])->save();
        }

        // Audit-Fix H1: Anhänge erst JETZT (bestätigte Zuordnung) in die Akte übernehmen.
        $this->attachments->createDocuments($message->fresh());

        // Prüfbericht M4 (Phase 3): Gast-Tickets desselben Absenders
        // nachträglich mit dem bestätigten Kunden verknüpfen.
        if ($message->from_address) {
            Ticket::whereNull('customer_id')
                ->where('source', 'email')
                ->where('guest_email', $message->from_address)
                ->update(['customer_id' => $customer->id]);
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => $logAction,
            'entity_type' => 'email_message',
            'entity_id' => $message->id,
            'meta' => json_encode([
                'customer_id' => (string) $customer->id,
                'subject' => $message->subject,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }
}
