<?php
namespace App\Http\Controllers;

use App\Models\InternalNotification;
use Illuminate\Http\Request;

/**
 * Benachrichtigungen für @Mentions im internen Chat.
 * Nur der Empfänger selbst kann seine Benachrichtigungen lesen/abhaken.
 */
class InternalNotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = InternalNotification::with(['message.sender', 'message.customer.user', 'changeRequest.customer.user'])
            ->where('user_id', auth()->id())
            ->latest()
            ->take(15)
            ->get()
            // Sichtbarkeit kann sich seit der Erwähnung geändert haben
            ->filter(function ($n) {
                $customerId = $n->message?->customer_id ?? $n->changeRequest?->customer_id;
                return $customerId && auth()->user()->canAccessCustomer($customerId);
            })
            ->values();

        return response()->json([
            'unread' => InternalNotification::where('user_id', auth()->id())->unread()->count(),
            'items' => $notifications->map(function ($n) {
                if ($n->changeRequest) {
                    return [
                        'id' => $n->id,
                        'read' => $n->read_at !== null,
                        'sender' => $n->changeRequest->customer?->user?->name ?? 'Kunde',
                        'customer' => 'Kundenänderung: ' . $n->changeRequest->typeLabel(),
                        'preview' => 'Neue Änderungsanfrage wartet auf Prüfung.',
                        'time' => $n->created_at->format('d.m.Y H:i'),
                        'url' => route('admin.change_requests'),
                    ];
                }
                return [
                    'id' => $n->id,
                    'read' => $n->read_at !== null,
                    'sender' => $n->message->sender?->name ?? 'Unbekannt',
                    'customer' => $n->message->customer?->user?->name ?? '—',
                    'preview' => \Illuminate\Support\Str::limit($n->message->message, 80),
                    'time' => $n->created_at->format('d.m.Y H:i'),
                    'url' => route('admin.customer', $n->message->customer_id)
                        . ($n->message->type === 'note' ? '#tab-notizen' : '#tab-intern'),
                ];
            }),
        ]);
    }

    public function markRead($id)
    {
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
