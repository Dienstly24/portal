<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\Contract;
use App\Models\Ticket;
use App\Models\TicketMessage;
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
            'pendingApprovals' => \App\Models\CustomerChangeRequest::where('customer_id', $customer->id)->where('status','pending')->count(),
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
            'pending' => \App\Models\CustomerChangeRequest::where('customer_id', $customer->id)->where('status','pending')->count(),
        ]);
    }

    public function profileUpdate(Request $request) {
        $customer = $this->getCustomer();

        $data = $request->validate([
            'gender' => 'nullable|in:male,female,diverse',
            'salutation' => 'nullable|in:herr,frau,divers,firma',
            'address' => 'nullable|string|max:255',
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\/\s()-]{6,}$/'],
            'iban' => ['nullable', 'string', 'max:34', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/'],
            'marital_status' => 'nullable|in:ledig,verheiratet,geschieden,verwitwet',
        ]);

        $service = app(\App\Services\ChangeRequestService::class);
        $created = false;

        // Profildaten: ein gebündelter Antrag für alle geänderten Felder
        $profileOld = $profileNew = [];
        foreach (['salutation', 'gender', 'address', 'phone', 'marital_status'] as $field) {
            if ($request->filled($field) && $data[$field] !== $customer->$field) {
                $profileOld[$field] = $customer->$field;
                $profileNew[$field] = $data[$field];
            }
        }
        if ($profileNew) {
            $service->submit($customer, 'profile', $profileOld, $profileNew, 'Profiländerung beantragt: ' . implode(', ', array_keys($profileNew)));
            $created = true;
        }

        // IBAN läuft einheitlich als Bankänderung (Typ 'bank')
        if ($request->filled('iban') && $data['iban'] !== $customer->iban) {
            $service->submit(
                $customer,
                'bank',
                ['iban' => $customer->iban ? '••••' . substr($customer->iban, -4) : null, 'account_holder' => $customer->account_holder],
                ['iban' => $data['iban'], 'account_holder' => $customer->account_holder ?? auth()->user()->name],
                'Neue Bankverbindung beantragt (über Profil)'
            );
            $created = true;
        }

        return back()->with('success', $created
            ? 'Ihre Änderungen wurden zur Prüfung eingereicht.'
            : 'Keine Änderungen erkannt.');
    }
}
