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
            'completeness' => $customer->completeness(),
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
        $doc = \App\Models\Document::where('customer_id', $customer->id)
            ->customerVisible() // interne Dokumente sind für Kunden unsichtbar (404)
            ->where('id', $id)->firstOrFail();
        \App\Models\ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'document_viewed',
            'entity_type' => 'document',
            'entity_id' => $doc->id,
            'meta' => json_encode(['file' => $doc->file_name], JSON_UNESCAPED_UNICODE),
        ]);
        $disk = $doc->disk ?: 'public';
        abort_unless(\Illuminate\Support\Facades\Storage::disk($disk)->exists($doc->file_path), 404);
        return \Illuminate\Support\Facades\Storage::disk($disk)->download($doc->file_path, $doc->file_name);
    }

    public function documents() {
        $customer = $this->getCustomer();
        return view('portal.documents', [
            'documents' => \App\Models\Document::where('customer_id', $customer->id)->customerVisible()->latest()->get()
        ]);
    }

    /**
     * Kunde lädt selbst ein Dokument hoch (Review Punkt 7). Speicherung
     * privat; das Dokument gehört dem Kunden und ist für ihn sichtbar.
     * Admin/Manager/Support werden über das Notification Center informiert.
     */
    public function documentUpload(Request $request) {
        $customer = $this->getCustomer();

        $data = $request->validate([
            'document' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx|max:10240',
            'category' => 'required|in:contract,police,invoice,identity,claim,other',
        ]);

        $file = $request->file('document');
        $path = $file->store('customers/' . $customer->id . '/documents', 'local');

        $doc = \App\Models\Document::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'customer_id' => $customer->id,
            'category' => $data['category'],
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'disk' => 'local',
            'visibility' => 'customer',
            'uploaded_by' => auth()->id(),
            'file_size' => $file->getSize(),
        ]);

        // Benachrichtigung an Staff (Notification Center)
        foreach (\App\Models\User::whereIn('role', ['admin','manager','support'])->where('is_active', true)->get() as $recipient) {
            \App\Models\InternalNotification::create([
                'user_id' => $recipient->id,
                'title' => 'Neues Kundendokument',
                'body' => ($customer->user?->name ?? 'Ein Kunde') . ' hat „' . $doc->file_name . '" hochgeladen.',
                'link' => route('admin.customer', $customer->id) . '#tab-uebersicht',
            ]);
        }

        \App\Models\ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'document_uploaded_by_customer',
            'entity_type' => 'document',
            'entity_id' => $doc->id,
            'meta' => json_encode(['customer_id' => (string) $customer->id, 'file' => $doc->file_name], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'Ihr Dokument wurde hochgeladen.');
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
            'birth_place' => 'nullable|string|max:255',
            'marital_status' => 'nullable|in:ledig,verheiratet,geschieden,verwitwet',
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\/\s()-]{6,}$/'],
            // Strukturierte Adresse nach deutschem Standard (Review Punkt 5)
            'address_street' => 'nullable|string|max:255',
            'address_house_number' => 'nullable|string|max:10',
            'address_house_suffix' => 'nullable|string|max:10',
            'address_zip' => 'nullable|string|max:10',
            'address_city' => 'nullable|string|max:100',
            // Sensible Kundendaten (Review Punkt 6)
            'health_insurance_number' => 'nullable|string|max:50',
            'pension_insurance_number' => 'nullable|string|max:50',
            'tax_id' => 'nullable|string|max:20',
            // Bankverbindung (Review Punkt 4 - alles auf einer Seite)
            'iban' => ['nullable', 'string', 'max:34', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/'],
            'account_holder' => 'nullable|string|max:255',
        ]);

        $service = app(\App\Services\ChangeRequestService::class);
        $created = 0;

        // Persönliche Daten + strukturierte Adresse + Kundendaten:
        // EIN gebündelter Profil-Antrag für alle geänderten Felder.
        $profileFields = [
            'gender', 'birth_place', 'marital_status', 'phone',
            'address_street', 'address_house_number', 'address_house_suffix', 'address_zip', 'address_city',
            'health_insurance_number', 'pension_insurance_number', 'tax_id',
        ];
        $profileOld = $profileNew = [];
        foreach ($profileFields as $field) {
            if ($request->filled($field) && (string) $data[$field] !== (string) $customer->$field) {
                $profileOld[$field] = $customer->$field;
                $profileNew[$field] = $data[$field];
            }
        }
        if ($profileNew) {
            $service->submit($customer, 'profile', $profileOld, $profileNew, 'Profiländerung beantragt: ' . implode(', ', array_keys($profileNew)));
            $created++;
        }

        // Bankverbindung als EIGENER, unabhängiger Change Request (Review Punkt 9)
        if ($request->filled('iban') && $data['iban'] !== $customer->iban) {
            $service->submit(
                $customer,
                'bank',
                ['iban' => $customer->iban ? '••••' . substr($customer->iban, -4) : null, 'account_holder' => $customer->account_holder],
                ['iban' => $data['iban'], 'account_holder' => ($data['account_holder'] ?? null) ?: ($customer->account_holder ?? auth()->user()->name)],
                'Neue Bankverbindung beantragt'
            );
            $created++;
        }

        return back()->with('success', $created > 0
            ? 'Ihre Änderung' . ($created > 1 ? 'en wurden' : ' wurde') . ' zur Prüfung eingereicht. Jede Änderung wird einzeln bearbeitet.'
            : 'Keine Änderungen erkannt.');
    }
}
