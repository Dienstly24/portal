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

        return redirect()->route('portal.messages')->with('success', __('Nachricht gesendet.'));
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
