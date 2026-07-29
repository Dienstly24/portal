<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ChangeRequestDocument;
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
 *
 * Sensible Änderungen (Bankverbindung, Anschrift) verlangen zusätzlich
 * einen NACHWEIS (Kontonachweis, Meldebescheinigung, Ausweis) und die
 * Angabe, AB WANN die Änderung gilt - ohne Beleg wird der Antrag gar
 * nicht angenommen (Betreiber-Vorgabe 29.07.2026).
 */
class SelfServiceController extends Controller
{
    /** Zulässige Nachweis-Dateien (wie beim Dokumenten-Upload). */
    private const PROOF_RULES = ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'];

    private function getCustomer(): Customer
    {
        return Customer::firstOrCreate(
            ['user_id' => auth()->id()],
            ['customer_number' => 'C-' . strtoupper(Str::random(8))]
        );
    }

    /**
     * Hochgeladene Nachweise als "Art => Datei" einsammeln. Die Art
     * bestimmt, wie der Nachweis in der Review-Liste beschriftet wird.
     *
     * @return array<string, \Illuminate\Http\UploadedFile>
     */
    private function collectProofs(Request $request): array
    {
        $files = [];
        foreach (['bank_proof', 'meldebescheinigung', 'id_front', 'id_back'] as $field) {
            if ($request->hasFile($field)) {
                $files[$field] = $request->file($field);
            }
        }
        // Formulare mit Art-Auswahl ("Was laden Sie hoch?")
        if ($request->hasFile('proof')) {
            $kind = $request->input('proof_kind');
            $files[array_key_exists($kind, ChangeRequestDocument::KINDS) ? $kind : 'other'] = $request->file('proof');
        }
        if ($request->hasFile('proof_back')) {
            $files['id_back'] = $request->file('proof_back');
        }
        return $files;
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
        $this->createRequest(
            'address',
            null,
            $data,
            'Neue Adresse beantragt (' . (CustomerAddress::TYPES[$data['type']] ?? $data['type']) . ')',
            $this->collectProofs($request),
            $request->input('effective_from'),
        );
        return back()->with('success', 'Ihre Adresse wurde mit Nachweis zur Prüfung eingereicht.');
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
            'Adressänderung beantragt',
            $this->collectProofs($request),
            $request->input('effective_from'),
        );
        return back()->with('success', 'Ihre Adressänderung wurde mit Nachweis zur Prüfung eingereicht.');
    }

    /**
     * Adressdaten + Pflicht-Nachweis. Ohne Beleg (Meldebescheinigung,
     * Ausweis mit neuer Anschrift, Mietvertrag) nehmen wir eine
     * Adressänderung nicht an - sie steuert Post, Policen und Beiträge.
     */
    private function validateAddress(Request $request): array
    {
        $data = $request->validate([
            'type' => 'required|in:main,billing,postal,other',
            'street' => 'required|string|max:255',
            'zip' => 'required|string|max:10',
            'city' => 'required|string|max:100',
            'country' => 'nullable|string|max:100',
            'effective_from' => 'nullable|date|after_or_equal:-5 years',
            'proof' => array_merge(['required'], self::PROOF_RULES),
            'proof_kind' => 'nullable|in:meldebescheinigung,id_front,other',
            'proof_back' => array_merge(['nullable'], self::PROOF_RULES),
        ], [
            'proof.required' => 'Bitte laden Sie einen Nachweis Ihrer neuen Anschrift hoch (Meldebescheinigung oder Ausweis).',
            'proof.mimes' => 'Erlaubt sind PDF-Dateien und Fotos (JPG, PNG, WEBP).',
            'proof.max' => 'Die Datei darf höchstens 10 MB groß sein.',
        ]);

        // Nur die Adressfelder gehören in den Antrag - Nachweis und
        // "gültig ab" werden getrennt am Antrag gespeichert.
        return array_intersect_key($data, array_flip(['type', 'street', 'zip', 'city', 'country']));
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

    /**
     * Neue Bankverbindung - nur MIT Kontonachweis. Die Bankverbindung
     * steuert den Geldfluss; ohne Beleg (Bankkarte, Kontoauszug) nehmen
     * wir die Änderung nicht an. Zusätzlich erfasst der Kunde, ab wann
     * die neue Verbindung genutzt werden soll.
     */
    public function bankStore(Request $request)
    {
        $data = $request->validate([
            'iban' => ['required', 'string', 'max:34', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/'],
            'account_holder' => 'required|string|max:255',
            'effective_from' => 'nullable|date|after_or_equal:-5 years',
            'bank_proof' => array_merge(['required'], self::PROOF_RULES),
            'id_front' => array_merge(['nullable'], self::PROOF_RULES),
            'id_back' => array_merge(['nullable'], self::PROOF_RULES),
        ], [
            'bank_proof.required' => 'Bitte laden Sie einen Kontonachweis hoch (Foto der Bankkarte oder Kontoauszug mit sichtbarer IBAN).',
            'bank_proof.mimes' => 'Erlaubt sind PDF-Dateien und Fotos (JPG, PNG, WEBP).',
            'bank_proof.max' => 'Die Datei darf höchstens 10 MB groß sein.',
        ]);

        $customer = $this->getCustomer();
        $this->createRequest(
            'bank',
            [
                'iban' => $customer->iban ? '••••' . substr($customer->iban, -4) : null,
                'account_holder' => $customer->account_holder,
            ],
            ['iban' => $data['iban'], 'account_holder' => $data['account_holder']],
            'Neue Bankverbindung beantragt',
            $this->collectProofs($request),
            $data['effective_from'] ?? null,
        );

        return back()->with('success', 'Ihre neue Bankverbindung wurde mit Nachweis zur Prüfung eingereicht. Die Änderung wird erst nach Freigabe wirksam.');
    }

    // ------------------------------------------------------------------
    // Vertrag melden (inkl. Dokument-Upload)
    // ------------------------------------------------------------------

    public function contractReport(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:kfz,schutzbrief,krankenversicherung,haftpflicht,rechtsschutz,hausrat,escooter,leben,unfall,internet,strom,gas,andere',
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
            'requests' => $customer->changeRequests()->with('reviewer')->withCount('documents')->latest()->get(),
        ]);
    }

    // ------------------------------------------------------------------
    // Gemeinsame Logik: Request anlegen + Staff benachrichtigen + Audit
    // ------------------------------------------------------------------

    private function createRequest(
        string $type,
        ?array $oldData,
        array $newData,
        string $auditText,
        array $proofFiles = [],
        ?string $effectiveFrom = null,
    ): CustomerChangeRequest {
        return app(\App\Services\ChangeRequestService::class)->submit(
            $this->getCustomer(), $type, $oldData, $newData, $auditText,
            requestedBy: null, proofFiles: $proofFiles, effectiveFrom: $effectiveFrom,
        );
    }
}