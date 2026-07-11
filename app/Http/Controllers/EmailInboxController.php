<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Commission;
use App\Models\Customer;
use App\Models\CustomerChangeRequest;
use App\Models\DocumentRequest;
use App\Models\EmailMessage;
use App\Services\FondsFinanz\FondsFinanzImportService;
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
    public function __construct(private readonly FondsFinanzImportService $fondsFinanz)
    {
    }

    public function index()
    {
        $suggested = EmailMessage::with(['customer.user', 'account'])
            ->where('match_status', 'suggested')
            ->orderBy('received_at')->get();

        $unmatched = EmailMessage::with('account')
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

    /** Vorgeschlagene Zuordnung bestätigen (Ein-Klick, Abschnitt 13). */
    public function confirm($id)
    {
        $message = EmailMessage::with('customer')->findOrFail($id);
        if ($message->match_status !== 'suggested' || $message->customer === null) {
            return back()->with('error', 'Diese E-Mail wartet nicht auf Bestätigung.');
        }

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

    private function applyAssignment(EmailMessage $message, Customer $customer, string $logAction): void
    {
        if ($message->category === 'fonds_finanz') {
            // Bestätigter FF-Kunde: jetzt den eigentlichen Import ausführen.
            $this->fondsFinanz->importForCustomer($message, $customer);
        } else {
            $message->forceFill(['match_status' => 'confirmed', 'customer_id' => $customer->id])->save();
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
