<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerChangeRequest;
use App\Models\CustomerContact;
use App\Models\CustomerFamily;
use App\Models\InternalNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Self-Service im Kundenportal: Familie, Adressen, Kontakte, Bank,
 * Vertragsmeldung, Änderungsanfragen.
 *
 * Sicherheitsprinzip: Der Kunde ändert NIE direkt Daten. Jede Aktion
 * erzeugt ausschließlich einen CustomerChangeRequest (pending), der
 * von Mitarbeitern geprüft wird. Alle Lese-/Schreibzugriffe sind hart
 * auf den eigenen Customer-Datensatz gescoped.
 */
class SelfServiceController extends Controller
{
    private function getCustomer(): Customer
    {
        return Customer::firstOrCreate(
            ['user_id' => auth()->id()],
            ['customer_number' => 'C-' . strtoupper(Str::random(8))]
        );
    }

    // ------------------------------------------------------------------
    // Familie
    // ------------------------------------------------------------------

    public function family()
    {
        $customer = $this->getCustomer();
        return view('portal.family', [
            'customer' => $customer,
            'members' => CustomerFamily::where('customer_id', $customer->id)->orderBy('created_at')->get(),
            'requests' => $customer->changeRequests()->where('type', 'family')->latest()->get(),
        ]);
    }

    public function familyStore(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'relation' => 'required|in:ehepartner,kind,andere',
            'birth_date' => 'nullable|date|before_or_equal:today',
            'gender' => 'nullable|in:male,female',
            'birth_place' => 'nullable|string|max:255',
            'health_insurance_number' => 'nullable|string|max:50',
            'pension_insurance_number' => 'nullable|string|max:50',
            'tax_id' => 'nullable|string|max:20',
        ]);

        $this->createRequest('family', null, $data, 'Neues Familienmitglied beantragt: ' . $data['name']);

        return back()->with('success', 'Ihr Familienmitglied wurde zur Prüfung eingereicht.');
    }

    public function familyChange(Request $request, $id)
    {
        $customer = $this->getCustomer();
        $member = CustomerFamily::where('customer_id', $customer->id)->where('id', $id)->firstOrFail();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'relation' => 'required|in:ehepartner,kind,andere',
            'birth_date' => 'nullable|date|before_or_equal:today',
            'gender' => 'nullable|in:male,female',
            'birth_place' => 'nullable|string|max:255',
            'health_insurance_number' => 'nullable|string|max:50',
            'pension_insurance_number' => 'nullable|string|max:50',
            'tax_id' => 'nullable|string|max:20',
        ]);

        $this->createRequest(
            'family',
            ['id' => $member->id, 'name' => $member->name, 'relation' => $member->relation, 'birth_date' => $member->birth_date],
            ['id' => $member->id] + $data,
            'Änderung Familienmitglied beantragt: ' . $member->name
        );

        return back()->with('success', 'Ihre Änderung wurde zur Prüfung eingereicht.');
    }

    /**
     * Löschung eines Familienmitglieds beantragen. Wird – wie jede andere
     * Änderung – erst nach Prüfung und Freigabe durch einen Mitarbeiter
     * wirksam (Vier-Augen-Prinzip).
     */
    public function familyDelete($id)
    {
        $customer = $this->getCustomer();
        $member = CustomerFamily::where('customer_id', $customer->id)->where('id', $id)->firstOrFail();

        $this->createRequest(
            'family',
            ['id' => $member->id, 'name' => $member->name, 'relation' => $member->relation, 'birth_date' => $member->birth_date],
            ['id' => $member->id, 'delete' => true, 'name' => $member->name],
            'Löschung Familienmitglied beantragt: ' . $member->name
        );

        return back()->with('success', 'Ihr Löschantrag wurde zur Prüfung eingereicht. Das Familienmitglied wird nach Freigabe entfernt.');
    }

    // ------------------------------------------------------------------
    // Adressen
    // ------------------------------------------------------------------

    public function addresses()
    {
        $customer = $this->getCustomer();
        return view('portal.addresses', [
            'customer' => $customer,
            'addresses' => $customer->addresses()->orderBy('created_at')->get(),
            'requests' => $customer->changeRequests()->where('type', 'address')->latest()->get(),
        ]);
    }

    public function addressStore(Request $request)
    {
        $data = $this->validateAddress($request);
        $this->createRequest('address', null, $data, 'Neue Adresse beantragt (' . (CustomerAddress::TYPES[$data['type']] ?? $data['type']) . ')');
        return back()->with('success', 'Ihre Adresse wurde zur Prüfung eingereicht.');
    }

    public function addressChange(Request $request, $id)
    {
        $customer = $this->getCustomer();
        $address = CustomerAddress::where('customer_id', $customer->id)->where('id', $id)->firstOrFail();

        $data = $this->validateAddress($request);
        $this->createRequest(
            'address',
            ['id' => $address->id, 'type' => $address->type, 'street' => $address->street, 'zip' => $address->zip, 'city' => $address->city, 'country' => $address->country],
            ['id' => $address->id] + $data,
            'Adressänderung beantragt'
        );
        return back()->with('success', 'Ihre Adressänderung wurde zur Prüfung eingereicht.');
    }

    private function validateAddress(Request $request): array
    {
        return $request->validate([
            'type' => 'required|in:main,billing,postal,other',
            'street' => 'required|string|max:255',
            'zip' => 'required|string|max:10',
            'city' => 'required|string|max:100',
            'country' => 'nullable|string|max:100',
        ]);
    }

    // ------------------------------------------------------------------
    // Kontaktinformationen (mehrere E-Mails / Telefonnummern)
    // ------------------------------------------------------------------

    public function contacts()
    {
        $customer = $this->getCustomer();
        return view('portal.contacts', [
            'customer' => $customer,
            'contacts' => $customer->contacts()->orderBy('type')->orderBy('created_at')->get(),
            'requests' => $customer->changeRequests()->whereIn('type', ['email', 'phone'])->latest()->get(),
        ]);
    }

    public function contactStore(Request $request)
    {
        $data = $this->validateContact($request);
        $label = $data['type'] === 'email' ? 'E-Mail-Adresse' : 'Telefonnummer';
        $this->createRequest($data['type'], null, ['label' => $data['label'], 'value' => $data['value']], 'Neue ' . $label . ' beantragt');
        return back()->with('success', 'Ihre Kontaktinformation wurde zur Prüfung eingereicht.');
    }

    public function contactChange(Request $request, $id)
    {
        $customer = $this->getCustomer();
        $contact = CustomerContact::where('customer_id', $customer->id)->where('id', $id)->firstOrFail();

        $data = $this->validateContact($request, $contact->type);
        $this->createRequest(
            $contact->type,
            ['id' => $contact->id, 'label' => $contact->label, 'value' => $contact->value],
            ['id' => $contact->id, 'label' => $data['label'], 'value' => $data['value']],
            'Änderung Kontaktinformation beantragt'
        );
        return back()->with('success', 'Ihre Änderung wurde zur Prüfung eingereicht.');
    }

    private function validateContact(Request $request, ?string $forcedType = null): array
    {
        $type = $forcedType ?: $request->input('type');
        $rules = [
            'label' => 'required|in:privat,geschaeftlich,sonstige',
            'value' => $type === 'email'
                ? 'required|email|max:255'
                : ['required', 'string', 'max:30', 'regex:/^[0-9+\/\s()-]{6,}$/'],
        ];
        if (!$forcedType) {
            $rules['type'] = 'required|in:email,phone';
        }
        $data = $request->validate($rules);
        $data['type'] = $type;
        return $data;
    }

    // ------------------------------------------------------------------
    // Bankverbindung
    // ------------------------------------------------------------------

    public function bank()
    {
        $customer = $this->getCustomer();
        return view('portal.bank', [
            'customer' => $customer,
            'requests' => $customer->changeRequests()->where('type', 'bank')->latest()->get(),
        ]);
    }

    public function bankStore(Request $request)
    {
        $data = $request->validate([
            'iban' => ['required', 'string', 'max:34', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/'],
            'account_holder' => 'required|string|max:255',
        ]);

        $customer = $this->getCustomer();
        $this->createRequest(
            'bank',
            [
                'iban' => $customer->iban ? '••••' . substr($customer->iban, -4) : null,
                'account_holder' => $customer->account_holder,
            ],
            $data,
            'Neue Bankverbindung beantragt'
        );

        return back()->with('success', 'Ihre neue Bankverbindung wurde zur Prüfung eingereicht. Die Änderung wird erst nach Freigabe wirksam.');
    }

    // ------------------------------------------------------------------
    // Vertrag melden (inkl. Dokument-Upload)
    // ------------------------------------------------------------------

    public function contractReport(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:kfz,krankenversicherung,haftpflicht,rechtsschutz,hausrat,escooter,leben,unfall,internet,strom,gas,andere',
            'insurer' => 'required|string|max:255',
            'contract_number' => 'nullable|string|max:100',
            'document' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $customer = $this->getCustomer();

        $payload = [
            'type' => $data['type'],
            'insurer' => $data['insurer'],
            'contract_number' => $data['contract_number'] ?? null,
        ];

        if ($request->hasFile('document')) {
            $file = $request->file('document');
            // Private Disk (storage/app/private) - niemals per URL erreichbar,
            // Zugriff nur über die autorisierten Download-Controller.
            $payload['document_path'] = $file->store('contract_documents/' . $customer->id, 'local');
            $payload['document_disk'] = 'local';
            $payload['document_name'] = $file->getClientOriginalName();
        }

        $this->createRequest('contract', null, $payload, 'Neuer Vertrag gemeldet: ' . $data['insurer']);

        return back()->with('success', 'Ihr Vertrag wurde gemeldet und wird von uns geprüft.');
    }

    /**
     * Aenderung an einem BESTEHENDEN Vertrag beantragen. Wie jede andere
     * Self-Service-Aktion wird die Aenderung erst nach Pruefung und Freigabe
     * durch einen Mitarbeiter wirksam (Vier-Augen-Prinzip). Der Zugriff ist
     * hart auf den eigenen Customer-Datensatz gescoped.
     */
    public function contractChange(Request $request, $id)
    {
        $customer = $this->getCustomer();
        $contract = Contract::where('customer_id', $customer->id)->where('id', $id)->firstOrFail();

        $data = $request->validate([
            'type' => 'required|in:' . implode(',', Contract::typeKeys()),
            'insurer' => 'required|string|max:255',
            'contract_number' => 'nullable|string|max:100',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'cancellation_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $this->createRequest(
            'contract',
            [
                'id' => $contract->id,
                'type' => $contract->type,
                'insurer' => $contract->insurer,
                'contract_number' => $contract->contract_number,
                'start_date' => $contract->start_date,
                'end_date' => $contract->end_date,
                'cancellation_date' => $contract->cancellation_date,
                'notes' => $contract->notes,
            ],
            ['id' => $contract->id] + $data,
            'Vertragsänderung beantragt: ' . $contract->insurer
        );

        return back()->with('success', 'Ihre Vertragsänderung wurde zur Prüfung eingereicht. Sie wird erst nach Freigabe durch unser Team wirksam.');
    }

    // ------------------------------------------------------------------
    // Änderungsanfragen (eigene Übersicht)
    // ------------------------------------------------------------------

    public function changeRequests()
    {
        $customer = $this->getCustomer();
        return view('portal.change_requests', [
            'requests' => $customer->changeRequests()->with('reviewer')->latest()->get(),
        ]);
    }

    // ------------------------------------------------------------------
    // Gemeinsame Logik: Request anlegen + Staff benachrichtigen + Audit
    // ------------------------------------------------------------------

    private function createRequest(string $type, ?array $oldData, array $newData, string $auditText): CustomerChangeRequest
    {
        return app(\App\Services\ChangeRequestService::class)
            ->submit($this->getCustomer(), $type, $oldData, $newData, $auditText);
    }
}