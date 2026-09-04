<?php

namespace App\Http\Controllers;

use App\Jobs\AnswerCustomerMessageJob;
use App\Models\AiConversation;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\CustomerMessageAttachment;
use App\Services\Ai\Assistant\AssistantSettings;
use App\Services\Ai\Assistant\ConversationResumeService;
use App\Services\CustomerMessageNotifier;
use App\Support\UploadRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
            ['customer_number' => 'C-'.strtoupper(Str::random(8))]
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

        // Kennzeichnung im Chat-Kopf nur, wenn der Assistent fuer DIESEN
        // Kunden wirklich antwortet (Assistent global an, automatische
        // Antworten an, kein Mitarbeiter hat uebernommen).
        // Es gibt hoechstens EINEN Steuerstand je Kunde (unique); fehlt er,
        // hat der Assistent noch nie geantwortet und ist damit aktiv.
        $settings = app(AssistantSettings::class);
        $conversation = AiConversation::where('customer_id', $customer->id)->first();
        // Auch eine faellige Wiederaufnahme zaehlt als "KI zustaendig"
        // (Betreiber-Vorgabe 20.08.2026): die Kennzeichnung soll dem
        // naechsten Schritt entsprechen, nicht dem Stand von gestern.
        $aiActive = $settings->enabled()
            && $settings->autoReply()
            && app(ConversationResumeService::class)
                ->isAiOnDuty($customer, $conversation);

        return view('portal.messages', compact('customer', 'messages', 'aiActive'));
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
            'messages' => $messages->map(fn ($m) => $m->toChatPayload())->values(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'body' => 'required|string|max:5000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => UploadRules::each(UploadRules::ATTACHMENT_MIMES),
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

        // KI-Kundenassistent: antwortet asynchron, damit der Kunde nicht auf
        // den KI-Dienst wartet (der Chat-Feed pollt und zeigt die Antwort von
        // selbst). Der Dienst prueft alle Schalter und Grenzen selbst - hier
        // wird nur angestossen. Das Team wurde oben bereits benachrichtigt,
        // also aendert die KI nichts am bisherigen Ablauf.
        if (app(AssistantSettings::class)->enabled()) {
            AnswerCustomerMessageJob::dispatch($message->id);
        }

        // Chat-UI sendet per fetch() und rendert die Blase selbst.
        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message->load(['sender', 'attachments'])->toChatPayload(),
            ]);
        }

        return redirect()->route('portal.messages')->with('success', __('Nachricht gesendet.'));
    }

    public function downloadAttachment($id)
    {
        $attachment = $this->findOwnAttachment($id);
        $disk = Storage::disk($attachment->disk ?: 'local');
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
        $disk = Storage::disk($attachment->disk ?: 'local');
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
