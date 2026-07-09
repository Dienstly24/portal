<?php
namespace App\Http\Controllers;

use App\Models\InternalConversationParticipant;
use App\Models\InternalNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Einheitliches Notification Center (Final Polish Punkt 2).
 * EINE Glocke, EIN Dropdown mit allen Quellen:
 *  💬 Mentions (message_id)
 *  🔄 Kundenänderungen (change_request_id)
 *  🗨️ Ungelesene interne Chat-Unterhaltungen (aus Teilnehmer-Lesestand
 *     berechnet, keine Extra-Zeilen pro Nachricht)
 *  ℹ️ Systemmeldungen (title/body/link)
 * Jeder Eintrag: Icon, Titel, Kurzbeschreibung, Uhrzeit, Ungelesen-
 * Markierung, Link zur Aktion. Nur der Empfänger sieht seine Einträge.
 */
class InternalNotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        $stored = InternalNotification::with(['message.sender', 'message.customer.user', 'changeRequest.customer.user'])
            ->where('user_id', $user->id)
            ->latest()
            ->take(15)
            ->get()
            ->filter(function ($n) use ($user) {
                // Systemmeldungen sind immer sichtbar; kundenbezogene Einträge
                // nur, solange der Kunde (noch) im Portfolio sichtbar ist.
                if ($n->title) return true;
                $customerId = $n->message?->customer_id ?? $n->changeRequest?->customer_id;
                return $customerId && $user->canAccessCustomer($customerId);
            })
            ->map(function ($n) {
                if ($n->title) {
                    return [
                        'id' => $n->id,
                        'icon' => 'ℹ️',
                        'title' => $n->title,
                        'preview' => Str::limit($n->body ?? '', 90),
                        'time' => $n->created_at->format('d.m.Y H:i'),
                        'read' => $n->read_at !== null,
                        'url' => $n->link ?: '#',
                        'sort' => $n->created_at,
                    ];
                }
                if ($n->changeRequest) {
                    return [
                        'id' => $n->id,
                        'icon' => '🔄',
                        'title' => 'Kundenänderung: ' . $n->changeRequest->typeLabel(),
                        'preview' => ($n->changeRequest->customer?->user?->name ?? 'Kunde') . ' wartet auf Prüfung.',
                        'time' => $n->created_at->format('d.m.Y H:i'),
                        'read' => $n->read_at !== null,
                        'url' => route('admin.change_requests'),
                        'sort' => $n->created_at,
                    ];
                }
                return [
                    'id' => $n->id,
                    'icon' => '💬',
                    'title' => ($n->message->sender?->name ?? 'Unbekannt') . ' hat Sie erwähnt',
                    'preview' => ($n->message->customer?->user?->name ? $n->message->customer->user->name . ': ' : '') . Str::limit($n->message->message, 80),
                    'time' => $n->created_at->format('d.m.Y H:i'),
                    'read' => $n->read_at !== null,
                    'url' => route('admin.customer', $n->message->customer_id)
                        . ($n->message->type === 'note' ? '#tab-notizen' : '#tab-intern'),
                    'sort' => $n->created_at,
                ];
            });

        // Ungelesene interne Chat-Unterhaltungen (Punkt 7: erscheinen im Center)
        $unreadConversations = InternalConversationParticipant::with('conversation.messages')
            ->where('user_id', $user->id)
            ->whereHas('conversation', function ($q) {
                $q->whereColumn('internal_conversations.last_message_at', '>', 'internal_conversation_participants.last_read_at')
                  ->orWhereNull('internal_conversation_participants.last_read_at');
            })
            ->get()
            ->filter(fn($p) => $p->conversation && $p->conversation->last_message_at)
            ->map(function ($p) {
                $last = $p->conversation->messages->sortByDesc('created_at')->first();
                return [
                    'id' => 'conv-' . $p->conversation->id,
                    'icon' => '🗨️',
                    'title' => 'Interner Chat: ' . $p->conversation->subject,
                    'preview' => Str::limit($last?->body ?? 'Neue Nachrichten', 80),
                    'time' => $p->conversation->last_message_at->format('d.m.Y H:i'),
                    'read' => false,
                    'url' => route('admin.chat.show', $p->conversation->id),
                    'sort' => $p->conversation->last_message_at,
                ];
            });

        $items = $stored->concat($unreadConversations)
            ->sortByDesc('sort')
            ->take(20)
            ->map(fn($i) => collect($i)->except('sort'))
            ->values();

        $unread = InternalNotification::where('user_id', $user->id)->unread()->count()
            + $unreadConversations->count();

        return response()->json(['unread' => $unread, 'items' => $items]);
    }

    public function markRead($id)
    {
        // Chat-Einträge ('conv-…') werden beim Öffnen der Unterhaltung
        // über den Teilnehmer-Lesestand als gelesen markiert.
        if (str_starts_with((string) $id, 'conv-')) {
            return response()->json(['ok' => true]);
        }
        $n = InternalNotification::where('user_id', auth()->id())->findOrFail($id);
        $n->update(['read_at' => $n->read_at ?? now()]);
        return response()->json(['ok' => true]);
    }

    public function markAllRead()
    {
        InternalNotification::where('user_id', auth()->id())->unread()->update(['read_at' => now()]);
        return response()->json(['ok' => true]);
    }
}
