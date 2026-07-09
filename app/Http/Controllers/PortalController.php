<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\Contract;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\ApprovalRequest;
use Illuminate\Support\Str;

class PortalController extends Controller
{
    private function getCustomer() {
        return Customer::firstOrCreate(
            ['user_id' => auth()->id()],
            ['customer_number' => 'C-' . strtoupper(Str::random(8))]
        );
    }

    public function dashboard() {
        $customer = $this->getCustomer();
        return view('portal.dashboard', [
            'contractsCount' => Contract::where('customer_id', $customer->id)->where('status','active')->count(),
            'openTickets' => Ticket::where('customer_id', $customer->id)->whereIn('status',['open','in_progress'])->count(),
            'pendingApprovals' => ApprovalRequest::where('customer_id', $customer->id)->where('status','pending')->count(),
            'contracts' => Contract::where('customer_id', $customer->id)->latest()->take(3)->get(),
            'tickets' => Ticket::where('customer_id', $customer->id)->latest()->take(3)->get(),
        ]);
    }

    public function contracts() {
        $customer = $this->getCustomer();
        return view('portal.contracts', [
            'contracts' => Contract::where('customer_id', $customer->id)->latest()->get()
        ]);
    }

    public function tickets() {
        $customer = $this->getCustomer();
        return view('portal.tickets', [
            'tickets' => Ticket::where('customer_id', $customer->id)->latest()->get()
        ]);
    }

    public function ticketsCreate() {
        return view('portal.tickets_create');
    }

    public function ticketsStore(Request $request) {
        $request->validate([
            'type' => 'required',
            'subject' => 'required|max:255',
            'description' => 'required',
            'priority' => 'required|in:niedrig,mittel,hoch',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);
        $customer = $this->getCustomer();
        $ticket = Ticket::create([
            'id' => Str::uuid(),
            'customer_id' => $customer->id,
            'type' => $request->type,
            'priority' => $request->priority ?? 'mittel',
            'subject' => $request->subject,
            'description' => $request->description,
            'status' => 'open',
        ]);
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('tickets/' . $ticket->id, 'public');
                \App\Models\TicketAttachment::create([
                    'id' => Str::uuid(),
                    'ticket_id' => $ticket->id,
                    'uploaded_by' => auth()->id(),
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                ]);
            }
        }
        return redirect()->route('portal.tickets')->with('success', 'Anfrage erfolgreich eingereicht.');
    }

    public function ticketsShow($id) {
        $customer = $this->getCustomer();
        $ticket = Ticket::where('id', $id)->where('customer_id', $customer->id)->firstOrFail();
        $messages = TicketMessage::where('ticket_id', $id)->where('is_internal', false)->with('sender')->get();
        return view('portal.tickets_show', compact('ticket', 'messages'));
    }

    public function downloadAttachment($id) {
        $customer = $this->getCustomer();
        $a = \App\Models\TicketAttachment::findOrFail($id);
        $ticket = Ticket::where('id', $a->ticket_id)->where('customer_id', $customer->id)->firstOrFail();
        return response()->download(storage_path('app/public/' . $a->file_path), $a->file_name);
    }

    public function ticketsReply(Request $request, $id) {
        $request->validate(['body' => 'required']);
        $customer = $this->getCustomer();
        $ticket = Ticket::where('id', $id)->where('customer_id', $customer->id)->firstOrFail();
        TicketMessage::create([
            'id' => Str::uuid(),
            'ticket_id' => $ticket->id,
            'sender_id' => auth()->id(),
            'body' => $request->body,
            'is_internal' => false,
        ]);
        return back()->with('success', 'Nachricht gesendet.');
    }

    /**
     * Sicherer Dokument-Download: nur authentifizierte Kunden, nur
     * Dokumente des eigenen Kundendatensatzes (sonst 404). Funktioniert
     * für private ('local') und Bestandsdokumente ('public').
     */
    public function documentDownload($id) {
        $customer = $this->getCustomer();
        $doc = \App\Models\Document::where('customer_id', $customer->id)->where('id', $id)->firstOrFail();
        $disk = $doc->disk ?: 'public';
        abort_unless(\Illuminate\Support\Facades\Storage::disk($disk)->exists($doc->file_path), 404);
        return \Illuminate\Support\Facades\Storage::disk($disk)->download($doc->file_path, $doc->file_name);
    }

    public function documents() {
        $customer = $this->getCustomer();
        return view('portal.documents', [
            'documents' => \App\Models\Document::where('customer_id', $customer->id)->latest()->get()
        ]);
    }

    public function profile() {
        $customer = $this->getCustomer();
        return view('portal.profile', [
            'customer' => $customer,
            'pending' => ApprovalRequest::where('customer_id', $customer->id)->where('status','pending')->count(),
        ]);
    }

    public function profileUpdate(Request $request) {
        $request->validate(['gender' => 'nullable|in:male,female,diverse']);
        $customer = $this->getCustomer();

        // Geschlecht läuft über das neue Change-Request-System (type=profile)
        if ($request->filled('gender') && $request->gender !== $customer->gender) {
            $cr = \App\Models\CustomerChangeRequest::create([
                'customer_id' => $customer->id,
                'requested_by' => auth()->id(),
                'type' => 'profile',
                'old_data' => ['gender' => $customer->gender],
                'new_data' => ['gender' => $request->gender],
                'status' => 'pending',
            ]);
            foreach (\App\Models\User::whereIn('role', ['admin','manager','support'])->where('is_active', true)->get() as $recipient) {
                \App\Models\InternalNotification::create(['user_id' => $recipient->id, 'change_request_id' => $cr->id]);
            }
            \App\Models\ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'change_request_created',
                'entity_type' => 'change_request',
                'entity_id' => $cr->id,
                'meta' => json_encode(['customer_id' => (string) $customer->id, 'type' => 'profile', 'text' => 'Geschlecht geändert'], JSON_UNESCAPED_UNICODE),
            ]);
        }

        $fields = ['address','phone','iban','marital_status'];
        foreach ($fields as $field) {
            if ($request->filled($field) && $request->$field !== $customer->$field) {
                ApprovalRequest::create([
                    'id' => Str::uuid(),
                    'customer_id' => $customer->id,
                    'field_name' => $field,
                    'old_value' => $customer->$field,
                    'new_value' => $request->$field,
                    'status' => 'pending',
                ]);
            }
        }
        return back()->with('success', 'Ihre Änderungsanfrage wurde eingereicht und wird geprüft.');
    }
}
