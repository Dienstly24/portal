<?php
namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\CustomerMessageAttachment;
use App\Services\CustomerMessageNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Kundenseite des Direktnachrichten-Chats (portal.dienstly24.de).
 * Hart auf den eigenen Kundendatensatz gescoped.
 */
class PortalMessageController extends Controller
{
    private function getCustomer(): Customer
    {
        return Customer::firstOrCreate(
            ['user_id' => auth()->id()],
            ['customer_number' => 'C-' . strtoupper(Str::random(8))]
        );
    }

    public function index()
    {
        $customer = $this->getCustomer();
        $messages = CustomerMessage::where('customer_id', $customer->id)
            ->with(['sender', 'attachments'])
            ->orderBy('created_at')
            ->get();

        // Beraternachrichten gelten mit dem Oeffnen der Seite als gelesen.
        CustomerMessage::where('customer_id', $customer->id)
            ->fromStaff()->unread()
            ->update(['read_at' => now()]);

        return view('portal.messages', compact('customer', 'messages'));
    }

    /**
     * JSON-Feed fuer Chat-Seite und Chat-Widget: kompletter Verlauf plus
     * Ungelesen-Zaehler. mark_read=1 markiert Beraternachrichten als
     * gelesen (der Chat ist geoeffnet und sichtbar).
     */
    public function feed(Request $request)
    {
        $customer = $this->getCustomer();

        if ($request->boolean('mark_read')) {
            CustomerMessage::where('customer_id', $customer->id)
                ->fromStaff()->unread()
                ->update(['read_at' => now()]);
        }

        $messages = CustomerMessage::where('customer_id', $customer->id)
            ->with(['sender', 'attachments'])
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'unread' => $messages->where('from_staff', true)->whereNull('read_at')->count(),
            'messages' => $messages->map(fn ($m) => $this->messagePayload($m))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'body' => 'required|string|max:5000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ]);
        $customer = $this->getCustomer();

        $message = CustomerMessage::create([
            'customer_id' => $customer->id,
            'sender_id' => auth()->id(),
            'body' => $request->body,
            'from_staff' => false,
        ]);
        CustomerMessageController::storeAttachments($request, $message);

        CustomerMessageNotifier::notifyStaffOfReply($message);

        // Chat-UI sendet per fetch() und rendert die Blase selbst.
        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $this->messagePayload($message->load(['sender', 'attachments'])),
            ]);
        }

        return redirect()->route('portal.messages')->with('success', __('Nachricht gesendet.'));
    }

    /** Einheitliche Nachricht-Struktur fuer Feed und Sende-Antwort. */
    private function messagePayload(CustomerMessage $m): array
    {
        return [
            'id' => $m->id,
            'from_staff' => $m->from_staff,
            'sender' => $m->from_staff ? ($m->sender?->name ?? 'Dienstly24 Team') : __('Sie'),
            'body' => $m->body,
            'day' => $m->created_at->isToday()
                ? __('Heute')
                : ($m->created_at->isYesterday() ? __('Gestern') : $m->created_at->format('d.m.Y')),
            'time' => $m->created_at->format('H:i'),
            'read' => $m->read_at !== null,
            'attachments' => $m->attachments->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->file_name,
                'kind' => $a->isImage() ? 'image' : ($a->isPdf() ? 'pdf' : 'file'),
                'view_url' => $a->isViewable() ? route('portal.messages.attachment.view', $a->id) : null,
                'download_url' => route('portal.messages.attachment', $a->id),
            ])->values()->all(),
        ];
    }

    public function downloadAttachment($id)
    {
        $attachment = $this->findOwnAttachment($id);
        $disk = \Illuminate\Support\Facades\Storage::disk($attachment->disk ?: 'local');
        abort_unless($disk->exists($attachment->file_path), 404);
        return $disk->download($attachment->file_path, $attachment->file_name);
    }

    /**
     * Zeigt Bild-/PDF-Anhaenge direkt im Browser an (Content-Disposition: inline),
     * damit der Kunde sie ohne Download-Zwang oeffnen kann.
     */
    public function viewAttachment($id)
    {
        $attachment = $this->findOwnAttachment($id);
        abort_unless($attachment->isViewable(), 404);
        $disk = \Illuminate\Support\Facades\Storage::disk($attachment->disk ?: 'local');
        abort_unless($disk->exists($attachment->file_path), 404);
        return $disk->response($attachment->file_path, $attachment->file_name, [
            'Content-Type' => $attachment->mimeType(),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function findOwnAttachment($id): CustomerMessageAttachment
    {
        $customer = $this->getCustomer();
        $attachment = CustomerMessageAttachment::with('message')->findOrFail($id);
        abort_unless($attachment->message->customer_id === $customer->id, 404);
        return $attachment;
    }
}
