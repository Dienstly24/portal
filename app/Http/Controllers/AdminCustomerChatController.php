<?php
namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerMessage;
use Illuminate\Http\Request;

/**
 * Zentraler Kunden-Chat der Beraterwelt: alle Portal-Unterhaltungen an
 * einem Ort (WhatsApp-Stil, zweispaltig). Frueher waren Kundennachrichten
 * nur ueber Glocke + Kundenakte auffindbar und wurden leicht uebersehen.
 * Zugriff strikt auf das eigene Kunden-Portfolio gescoped.
 */
class AdminCustomerChatController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        // Unterhaltungen: alle sichtbaren Kunden MIT Nachrichten, sortiert
        // nach letzter Aktivitaet; Ungelesen-Zaehler = Kundenantworten.
        $conversations = $user->getAccessibleCustomers()
            ->whereHas('messages')
            ->withCount(['messages as unread_count' => fn ($q) => $q->fromCustomer()->unread()])
            ->withMax('messages as last_message_at', 'created_at')
            // Neueste Nachricht pro Kunde (Laravel-Eager-Limit wirkt pro
            // Elternmodell). Bewusst KEIN latestOfMany(): das waehlt bei
            // UUID-Schluesseln ueber MAX(id) die falsche Zeile.
            ->with(['messages' => fn ($q) => $q->latest()->limit(1)])
            ->orderByDesc('last_message_at')
            ->get();

        // Suche: auch Kunden OHNE bisherige Nachrichten, um eine neue
        // Unterhaltung zu starten (gleiche Suche wie die Kundenliste).
        $searchResults = null;
        if (trim((string) $request->query('q')) !== '') {
            $searchResults = $user->getAccessibleCustomers()
                ->search($request->query('q'))
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get();
        }

        // Aktive Unterhaltung (?kunde=...): EINE chronologische Timeline
        // ueber alle Kanaele (Omnichannel Phase A); Kundenantworten im Chat
        // gelten als gelesen, sobald die Unterhaltung sichtbar ist.
        $active = null;
        $timeline = collect();
        $activeTicket = null;
        if ($request->query('kunde')) {
            abort_unless($user->canAccessCustomer($request->query('kunde')), 403);
            $active = Customer::with('user')->findOrFail($request->query('kunde'));
            CustomerMessage::where('customer_id', $active->id)
                ->fromCustomer()->unread()
                ->update(['read_at' => now()]);
            $service = new \App\Services\CustomerConversationService();
            $includeEmails = in_array($user->role, ['admin', 'manager', 'support'], true);
            $timeline = $service->timeline($active, includeEmails: $includeEmails);
            $timelineVersion = $service->version($active, includeEmails: $includeEmails);
            // Schnellaktion Ticket-Status: das juengste noch offene Ticket.
            $activeTicket = \App\Models\Ticket::where('customer_id', $active->id)
                ->whereNotIn('status', ['resolved', 'closed'])
                ->latest()->first();
            // Vorbefuellung fuer "Vorgang aus Unterhaltung": letzte
            // Kundennachricht als Beschreibung, damit der Mitarbeiter nicht
            // abtippen muss.
            $ticketPrefill = CustomerMessage::where('customer_id', $active->id)
                ->fromCustomer()->latest()->value('body');
        }

        return view('admin.customer_chat', [
            'conversations' => $conversations,
            'searchResults' => $searchResults,
            'active' => $active,
            'timeline' => $timeline,
            'timelineVersion' => $timelineVersion ?? '',
            'activeTicket' => $activeTicket,
            'ticketPrefill' => $ticketPrefill ?? '',
            'templates' => \App\Models\MessageTemplate::where('category', 'kunde')
                ->orderBy('sort')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * JSON-Feed einer Unterhaltung (Polling). mark_read=1 markiert
     * Kundenantworten als gelesen, solange der Chat geoeffnet ist.
     */
    public function feed(Request $request, $customerId)
    {
        abort_unless(auth()->user()->canAccessCustomer($customerId), 403);
        $customer = Customer::findOrFail($customerId);

        if ($request->boolean('mark_read')) {
            CustomerMessage::where('customer_id', $customer->id)
                ->fromCustomer()->unread()
                ->update(['read_at' => now()]);
        }

        $messages = CustomerMessage::where('customer_id', $customer->id)
            ->with(['sender', 'attachments', 'customer.user'])
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'unread' => $messages->where('from_staff', false)->whereNull('read_at')->count(),
            'messages' => $messages->map(fn ($m) => $m->toChatPayload(staffView: true))->values(),
            // Nicht-Chat-Kanaele (Tickets, E-Mails, Dokumente, Notizen):
            // aendert sich die Version, blendet die Seite einen
            // Aktualisieren-Hinweis ein (Chat selbst ist bereits live).
            'timeline_version' => (new \App\Services\CustomerConversationService())->version(
                $customer,
                includeEmails: in_array(auth()->user()->role, ['admin', 'manager', 'support'], true),
            ),
        ]);
    }

    /**
     * Vorgang aus der laufenden Unterhaltung eroeffnen ("Anfrage ->
     * Conversation -> Ticket"): ein Ticket ist nur der STATUS/Workflow
     * ueber der bestehenden Kommunikation - der Chatverlauf bleibt
     * erhalten, der Mitarbeiter arbeitet weiter am selben Ort. Der
     * Betreff/Beschreibung wird vorbefuellt aus der letzten
     * Kundennachricht uebergeben. Ticket-Bearbeitung erfordert
     * (wie ueberall) can_manage_tickets.
     */
    public function createTicket(Request $request, $customerId)
    {
        abort_unless(auth()->user()->canAccessCustomer($customerId), 403);
        $user = auth()->user();
        abort_if($user->role === 'employee' && !$user->can_manage_tickets, 403,
            'Keine Berechtigung, Tickets zu bearbeiten.');

        $data = $request->validate([
            'type' => 'required|in:' . implode(',', array_keys(\App\Models\Ticket::TYPES)),
            'priority' => 'required|in:niedrig,mittel,hoch,dringend',
            'subject' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'assign_me' => 'nullable|boolean',
        ]);
        $customer = Customer::findOrFail($customerId);

        $ticket = \App\Models\Ticket::create([
            'customer_id' => $customer->id,
            'type' => $data['type'],
            'priority' => $data['priority'],
            'subject' => $data['subject'],
            'description' => $data['description'],
            'status' => $request->boolean('assign_me') ? 'in_progress' : 'open',
            'assigned_to' => $request->boolean('assign_me') ? $user->id : null,
            'source' => 'kundenkommunikation',
        ]);
        if ($request->boolean('assign_me')) {
            $ticket->logEvent('assigned', 'an ' . $user->name);
        }
        \App\Services\TicketNotifier::notifyNewTicket($ticket);

        return redirect()
            ->route('admin.customer_chat', ['kunde' => $customer->id])
            ->with('success', 'Vorgang ' . $ticket->ticket_number . ' aus der Unterhaltung eröffnet.');
    }
}
