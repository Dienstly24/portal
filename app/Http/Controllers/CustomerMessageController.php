<?php
namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\CustomerMessageAttachment;
use App\Services\CustomerMessageNotifier;
use Illuminate\Http\Request;

/**
 * Direktnachrichten aus der Beraterwelt an den Kunden (Portal-Chat).
 * Zugriff: jeder Staff-User, der den Kunden sehen darf (Portfolio-Check).
 */
class CustomerMessageController extends Controller
{
    public function store(Request $request, $customerId)
    {
        abort_unless(auth()->user()->canAccessCustomer($customerId), 403);
        $request->validate([
            'body' => 'required|string|max:5000',
            'email_mode' => 'required|in:' . implode(',', CustomerMessage::EMAIL_MODES),
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ]);
        $customer = Customer::with('user')->findOrFail($customerId);

        $message = CustomerMessage::create([
            'customer_id' => $customer->id,
            'sender_id' => auth()->id(),
            'body' => $request->body,
            'from_staff' => true,
            'email_mode' => $request->email_mode,
        ]);
        $this->storeAttachments($request, $message);

        CustomerMessageNotifier::notifyCustomer($message, $request->email_mode);

        // Kunden-Chat (Beraterwelt) sendet per fetch() und rendert selbst.
        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message->load(['sender', 'attachments', 'customer.user'])->toChatPayload(staffView: true),
            ]);
        }

        return redirect(route('admin.customer', $customer->id) . '#tab-nachrichten')
            ->with('success', 'Nachricht an den Kunden gesendet.');
    }

    public function downloadAttachment($id)
    {
        $attachment = $this->findAccessibleAttachment($id);
        $disk = \Illuminate\Support\Facades\Storage::disk($attachment->disk ?: 'local');
        abort_unless($disk->exists($attachment->file_path), 404);
        return $disk->download($attachment->file_path, $attachment->file_name);
    }

    /** Zeigt Bild-/PDF-Anhaenge direkt im Browser an (Content-Disposition: inline). */
    public function viewAttachment($id)
    {
        $attachment = $this->findAccessibleAttachment($id);
        abort_unless($attachment->isViewable(), 404);
        $disk = \Illuminate\Support\Facades\Storage::disk($attachment->disk ?: 'local');
        abort_unless($disk->exists($attachment->file_path), 404);
        return $disk->response($attachment->file_path, $attachment->file_name, [
            'Content-Type' => $attachment->mimeType(),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function findAccessibleAttachment($id): CustomerMessageAttachment
    {
        $attachment = CustomerMessageAttachment::with('message')->findOrFail($id);
        abort_unless(auth()->user()->canAccessCustomer($attachment->message->customer_id), 403);
        return $attachment;
    }

    /**
     * Anhaenge liegen unter customers/{id}/messages auf der privaten Disk -
     * das Verzeichnis wird bei Kundenloeschung komplett entfernt (DSGVO).
     */
    public static function storeAttachments(Request $request, CustomerMessage $message): void
    {
        if (!$request->hasFile('attachments')) {
            return;
        }
        foreach ($request->file('attachments') as $file) {
            CustomerMessageAttachment::create([
                'message_id' => $message->id,
                'uploaded_by' => auth()->id(),
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $file->store('customers/' . $message->customer_id . '/messages', 'local'),
                'disk' => 'local',
            ]);
        }
    }
}
