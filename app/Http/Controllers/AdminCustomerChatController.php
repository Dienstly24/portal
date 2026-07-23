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
            $timeline = (new \App\Services\CustomerConversationService())->timeline(
                $active,
                includeEmails: in_array($user->role, ['admin', 'manager', 'support'], true),
            );
            // Schnellaktion Ticket-Status: das juengste noch offene Ticket.
            $activeTicket = \App\Models\Ticket::where('customer_id', $active->id)
                ->whereNotIn('status', ['resolved', 'closed'])
                ->latest()->first();
        }

        return view('admin.customer_chat', [
            'conversations' => $conversations,
            'searchResults' => $searchResults,
            'active' => $active,
            'timeline' => $timeline,
            'activeTicket' => $activeTicket,
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
        ]);
    }
}
