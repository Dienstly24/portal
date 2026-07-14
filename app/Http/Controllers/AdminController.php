<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Models\Customer;
use App\Models\Contract;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

class AdminController extends Controller
{
    /** null = alle sichtbar; sonst Array der erlaubten Kunden-IDs */
    private function visibleCustomerIds(): ?array {
        $user = auth()->user();
        if (!$user || $user->canSeeAllCustomers()) return null;
        return $user->visibleCustomerIdsWithSubstitution();
    }

    private function scopeCustomers($query) {
        $ids = $this->visibleCustomerIds();
        if ($ids !== null) $query->whereIn('customers.id', $ids);
        return $query;
    }

    /** 403, wenn der eingeloggte Mitarbeiter diesen Kunden nicht sehen darf. (Audit M1) */
    private function authorizeCustomerAccess($customerId): void {
        $ids = $this->visibleCustomerIds();
        if ($ids !== null && !in_array((string) $customerId, array_map('strval', $ids), true)) {
            abort(403, 'Kein Zugriff auf diesen Kunden.');
        }
    }

    /** 403, wenn das Ticket zu einem nicht sichtbaren Kunden gehört. (Audit M1) */
    private function authorizeTicketAccess(\App\Models\Ticket $ticket): void {
        if ($ticket->customer_id !== null) {
            $this->authorizeCustomerAccess($ticket->customer_id);
            return;
        }
        // Gast-Anfragen (Leads): nur admin/manager/support - gleiche Regel
        // wie die Anfragen-Liste (betrifft z. B. Anhang-Downloads).
        if (!in_array(auth()->user()?->role, ['admin', 'manager', 'support'], true)) {
            abort(403, 'Kein Zugriff auf Gast-Anfragen.');
        }
    }

    public function dashboard() {
        $ids = $this->visibleCustomerIds();
        return view('admin.dashboard', [
            'totalCustomers' => $ids === null ? Customer::count() : count($ids),
            'activeContracts' => Contract::where('status','active')->when($ids !== null, fn($q) => $q->whereIn('customer_id', $ids))->count(),
            // Gleiche Definition wie der Karten-Link (status=aktiv, nur Kundentickets),
            // damit die Zahl der Liste nach dem Klick entspricht.
            'openTickets' => Ticket::customerOnly()->active()->when($ids !== null, fn($q) => $q->whereIn('customer_id', $ids))->count(),
            'pendingApprovals' => \App\Models\CustomerChangeRequest::where('status','pending')->when($ids !== null, fn($q) => $q->whereIn('customer_id', $ids))->count(),
            'recentTickets' => Ticket::with('customer.user')->when($ids !== null, fn($q) => $q->whereIn('customer_id', $ids))->latest()->take(5)->get(),
            'recentApprovals' => \App\Models\CustomerChangeRequest::with('customer.user')->where('status','pending')->when($ids !== null, fn($q) => $q->whereIn('customer_id', $ids))->latest()->take(5)->get(),
            // Punkt 1: Zuletzt geöffnete Kunden strikt aufs Portfolio scopen.
            // Admin (ids === null) sieht alle; Mitarbeiter nur zugewiesene.
            'recentCustomers' => Customer::with('user')
                ->when($ids !== null, fn($q) => $q->whereIn('id', $ids))
                ->latest()->take(8)->get(),
        ]);
    }

    public function customers() {
        $employees = \App\Models\User::whereIn('role', ['employee', 'manager', 'support'])->orderBy('name')->get();
        // Aktive Verträge mitladen (nur benötigte Spalten) für die Vertrags-Icons
        // in der Liste – ohne N+1-Abfragen pro Zeile.
        $query = $this->scopeCustomers(Customer::with([
            'user',
            'betreuer',
            'contracts' => fn($q) => $q->where('status', 'active')->select('id', 'customer_id', 'type', 'status'),
        ]));
        if (request('betreuer')) {
            $query->whereHas('betreuer', fn($q) => $q->where('users.id', request('betreuer')));
        }
        // Seitenweise laden (25/Seite) – bleibt auch bei tausenden Kunden schnell.
        // withQueryString() erhält den Betreuer-Filter über die Seiten hinweg.
        $customers = $query->latest()->paginate(25)->withQueryString();
        return view('admin.customers', compact('customers', 'employees'));
    }

    public function customerShow($id) {
        $this->authorizeCustomerAccess($id);
        $customer = Customer::with(['user','contracts.vehicleDetail','contracts.energyDetail','contracts.internetDetail','contracts.switchReminders','tickets','documents','changeRequests.reviewer'])->findOrFail($id);
        // Interner Chat & Notizen (nur Staff - Zugriff bereits oben geprüft)
        $internalChat = \App\Models\InternalMessage::chat()->where('customer_id', $id)->with('sender')->orderBy('created_at')->get();
        $internalNotes = \App\Models\InternalMessage::note()->where('customer_id', $id)->with('sender')->latest()->get();
        return view('admin.customer_show', compact('customer', 'internalChat', 'internalNotes'));
    }

    public function contracts() {
        $ids = $this->visibleCustomerIds();
        $contracts = Contract::with('customer.user')->when($ids !== null, fn($q) => $q->whereIn('customer_id', $ids))->latest()->get();
        return view('admin.contracts', compact('contracts'));
    }

    public function contractNew() {
        // Vorher eine Route-Closure in web.php (verhindert route:cache) und
        // ohne Portfolio-Scoping. (Audit M8/M1)
        $customers = $this->scopeCustomers(Customer::with('user'))->get();
        return view('admin.contract_new', compact('customers'));
    }

    public function contractCreate($customerId) {
        $this->authorizeCustomerAccess($customerId);
        $customer = Customer::with('user')->findOrFail($customerId);
        return view('admin.contract_create', compact('customer'));
    }

    public function contractStore(Request $request, $customerId) {
        $this->authorizeCustomerAccess($customerId);
        $this->validateContract($request);

        $contract = Contract::create([
            'id' => Str::uuid(),
            'customer_id' => $customerId,
            // Echte Versicherungsnummer wird spaeter nachgetragen -> KEINE
            // automatische Fantasienummer mehr (Betreiber-Feedback).
            'contract_number' => $request->filled('contract_number') ? trim($request->contract_number) : null,
            'type' => $request->type,
            'type_other' => $request->type === 'andere' ? ($request->type_other ?: null) : null,
            'subtype' => $request->type === 'krankenversicherung' ? $request->subtype : null,
            'insurer' => $request->insurer,
            'status' => $request->status,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'cancellation_date' => $request->cancellation_date,
            'notes' => $request->notes,
            'added_by' => auth()->user()?->name,
        ]);

        $this->syncContractDetails($contract, $request);

        return redirect()->route('admin.customer', $customerId)->with('success', 'Vertrag erfolgreich hinzugefügt.');
    }

    public function contractEdit($id) {
        $contract = Contract::with(['vehicleDetail','energyDetail','internetDetail','customer.user'])->findOrFail($id);
        $this->authorizeCustomerAccess($contract->customer_id);
        return view('admin.contract_edit', compact('contract'));
    }

    public function contractUpdate(Request $request, $id) {
        $contract = Contract::findOrFail($id);
        $this->authorizeCustomerAccess($contract->customer_id);
        $this->validateContract($request, $contract->id);

        $contract->update([
            'contract_number' => $request->filled('contract_number') ? trim($request->contract_number) : null,
            'type' => $request->type,
            'type_other' => $request->type === 'andere' ? ($request->type_other ?: null) : null,
            'subtype' => $request->type === 'krankenversicherung' ? $request->subtype : null,
            'insurer' => $request->insurer,
            'status' => $request->status,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'cancellation_date' => $request->cancellation_date,
            'notes' => $request->notes,
        ]);

        $this->syncContractDetails($contract, $request);

        return redirect()->route('admin.customer', $contract->customer_id)->with('success', 'Vertrag aktualisiert.');
    }

    public function contractDestroy($id) {
        $contract = Contract::findOrFail($id);
        $this->authorizeCustomerAccess($contract->customer_id);
        $customerId = $contract->customer_id;

        // Dokumente bleiben in der Kundenakte erhalten - nur die
        // Vertragszuordnung wird geloest (keine FK-Cascade auf documents).
        \App\Models\Document::where('contract_id', $contract->id)->update(['contract_id' => null]);

        \App\Models\ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'contract_deleted',
            'entity_type' => 'contract',
            'entity_id' => $contract->id,
            'meta' => json_encode(['customer_id' => (string) $customerId, 'insurer' => $contract->insurer, 'type' => $contract->type], JSON_UNESCAPED_UNICODE),
        ]);

        // Detail-Datensaetze und Wechsel-Erinnerungen haengen per FK-Cascade.
        $contract->delete();

        return redirect()->route('admin.customer', $customerId)->with('success', 'Vertrag gelöscht.');
    }

    /** Gemeinsame Validierung fuer Anlegen und Bearbeiten von Vertraegen. */
    private function validateContract(Request $request, ?string $ignoreId = null): array {
        return $request->validate([
            'type' => 'required|in:' . implode(',', Contract::typeKeys()),
            // Freitext-Sparte nur bei "Sonstige" - dann aber verpflichtend.
            'type_other' => 'nullable|string|max:120|required_if:type,andere',
            // GKV/PKV-Unterscheidung: nur GKV erhält Wechsel-Erinnerungen (§175 SGB V)
            'subtype' => 'nullable|in:gkv,pkv',
            'insurer' => 'required|string|max:255',
            // Echte Versicherungsnummer, optional, aber eindeutig.
            'contract_number' => ['nullable', 'string', 'max:255', \Illuminate\Validation\Rule::unique('contracts', 'contract_number')->ignore($ignoreId)],
            'status' => 'required|in:active,pending,cancelled,expired',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'cancellation_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'energy.payment_amount' => 'nullable|numeric|min:0',
            'energy.payment_interval' => 'nullable|in:monatlich,vierteljaehrlich,halbjaehrlich,jaehrlich',
            // KFZ-Details
            'vehicle.first_registration' => 'nullable|date',
            'vehicle.sf_liability_year' => 'nullable|integer|min:1950|max:2100',
            'vehicle.sf_comprehensive_year' => 'nullable|integer|min:1950|max:2100',
            'vehicle.claims' => 'nullable|array',
            'vehicle.claims.*.month' => 'required_with:vehicle.claims|integer|min:1|max:12',
            'vehicle.claims.*.year' => 'required_with:vehicle.claims|integer|min:1990|max:2100',
            'vehicle.claims.*.type' => 'required_with:vehicle.claims|in:haftpflicht,vollkasko,teilkasko',
            // Energie: MaLo-ID hat 11 Ziffern und ist NICHT die Zählernummer
            'energy.malo_id' => ['nullable', 'regex:/^[0-9]{11}$/'],
            'energy.consumption_kwh' => 'nullable|integer|min:0',
            // Internet
            'internet.speed' => 'nullable|string|max:30',
        ]);
    }

    /**
     * Spartenspezifische Detaildatensätze anlegen/aktualisieren (Spec Teil 4/5).
     * Beim Bearbeiten mit Typwechsel werden verwaiste Detaildaten entfernt.
     */
    private function syncContractDetails(Contract $contract, Request $request): void {
        if ($contract->type !== 'kfz')      { $contract->vehicleDetail()->delete(); }
        if (!$contract->isEnergy())         { $contract->energyDetail()->delete(); }
        if ($contract->type !== 'internet') { $contract->internetDetail()->delete(); }

        if ($contract->type === 'kfz') {
            $v = $request->input('vehicle', []);
            $claims = collect($v['claims'] ?? [])->filter(fn($c) => !empty($c['year']))->values()->all();
            \App\Models\ContractVehicleDetail::updateOrCreate(
                ['contract_id' => $contract->id],
                [
                    'license_plate' => $v['license_plate'] ?? null,
                    'manufacturer' => $v['manufacturer'] ?? null,
                    'model' => $v['model'] ?? null,
                    'vehicle_type' => $v['vehicle_type'] ?? null,
                    'vin' => $v['vin'] ?? null,
                    'first_registration' => $v['first_registration'] ?? null,
                    'has_claims' => count($claims) > 0,
                    'claims' => $claims,
                    'sf_liability_class' => $v['sf_liability_class'] ?? null,
                    'sf_liability_year' => $v['sf_liability_year'] ?? null,
                    'sf_comprehensive_class' => $v['sf_comprehensive_class'] ?? null,
                    'sf_comprehensive_year' => $v['sf_comprehensive_year'] ?? null,
                ]
            );
        } elseif ($contract->isEnergy()) {
            \App\Models\ContractEnergyDetail::updateOrCreate(
                ['contract_id' => $contract->id],
                collect($request->input('energy', []))
                    ->only(['tariff','consumption_kwh','meter_number','customer_number','malo_id','meter_reading','grid_operator','metering_operator','payment_amount','payment_interval'])
                    ->map(fn($val) => $val === '' ? null : $val)
                    ->all()
            );
        } elseif ($contract->type === 'internet') {
            \App\Models\ContractInternetDetail::updateOrCreate(
                ['contract_id' => $contract->id],
                collect($request->input('internet', []))->only(['tariff','speed'])->map(fn($val) => $val === '' ? null : $val)->all()
            );
        }
    }

    // Tickets (Liste, Detail, Aktionen) liegen jetzt im TicketController.

    public function inquiries() {
        // Alle Anfragen OHNE Kundenakte (Gaeste: Website, E-Mail, Hilfe-Formular).
        // Seitenweise - die Liste waechst mit jedem Website-Lead.
        $tickets = Ticket::whereNull('customer_id')->latest()->paginate(25);
        return view('admin.inquiries', compact('tickets'));
    }

    public function createCustomer() {
        return view('admin.customer_create');
    }

    public function storeCustomer(Request $request) {
        $request->validate([
            'first_name' => 'required',
            'last_name' => 'required',
            'email' => 'required|email|unique:users',
            // Passwort ist jetzt optional: ohne Eingabe greift der
            // Startpasswort-Flow (Geburtsdatum TT.MM.JJJJ bzw. Set-Link).
            'password' => 'nullable|min:8',
            // Bankverbindung darf schon bei der Neuanlage erfasst werden.
            'iban' => 'nullable|string|max:40',
            'account_holder' => 'nullable|string|max:120',
        ]);
        $fullName = $request->first_name . ' ' . $request->last_name;
        $address = $this->buildAddress($request);
        $addressColumns = $this->addressColumns($request);
        $user = User::create([
            'name' => $fullName,
            'email' => $request->email,
            // Platzhalter - das nutzbare Passwort setzt gleich der
            // PortalAccessService (manuell/Geburtsdatum/Set-Link).
            'password' => bcrypt(\Illuminate\Support\Str::random(40)),
            'role' => 'customer',
        ]);
        $customer = Customer::create([
            'user_id' => $user->id,
            'customer_number' => app(\App\Services\CustomerNumberGenerator::class)->generate(),
            'phone' => $request->mobile ?? $request->phone,
            'mobile' => $request->mobile,
            'address' => $address,
            // Strukturierte Adressfelder (wie sie das Kundenportal liest),
            // damit im Portal keine leeren Felder erscheinen.
            'address_street' => $addressColumns['address_street'],
            'address_house_number' => $addressColumns['address_house_number'],
            'address_zip' => $addressColumns['address_zip'],
            'address_city' => $addressColumns['address_city'],
            'birth_date' => $request->birth_date,
            'gender' => in_array($request->gender, ['male','female','diverse'], true) ? $request->gender : null,
            'marital_status' => $request->marital_status ?: null,
            // Bankverbindung (verschluesselt at rest) direkt bei der Anlage.
            'iban' => $request->iban ?: null,
            'account_holder' => $request->account_holder ?: null,
            'preferred_lang' => $request->preferred_lang ?? 'de',
            'customer_type' => $request->customer_type ?? 'privat',
            'company_name' => $request->customer_type === 'firma' ? $request->company_name : null,
            'company_type' => $request->customer_type === 'firma' ? $request->company_type : null,
        ]);
        // Portal-Einladung: manuelles Passwort > Geburtsdatum-Startpasswort
        // > Passwort-Setzen-Link. KEINE Login-Mail ohne echte Adresse.
        $customer->setRelation('user', $user);
        if ($user->hasRealEmail()) {
            try {
                if ($request->filled('password')) {
                    $user->forceFill([
                        'password' => bcrypt($request->password),
                        'portal_password_set_at' => now(),
                        'invitation_sent_at' => now(),
                    ])->save();
                    \Illuminate\Support\Facades\Mail::to($user->email)->send(
                        new \App\Mail\CustomerWelcomeMail($customer, 'manual', $request->password)
                    );
                } else {
                    app(\App\Services\Portal\PortalAccessService::class)->sendInvitation($customer, auth()->id());
                }
            } catch (\Throwable $e) { \Log::warning('Welcome mail failed: ' . $e->getMessage()); }
        }
        return redirect()->route("admin.customer", $customer->id)->with("success", "Kunde erfolgreich erstellt.");
    }

    public function customerEdit($id) {
        $this->authorizeCustomerAccess($id);
        $customer = Customer::with('user')->findOrFail($id);
        $addr = $this->splitAddress($customer->address);
        $partners = \App\Models\Partner::active()->orderBy('name')->get(['id', 'name']);
        return view('admin.customer_edit', compact('customer', 'addr', 'partners'));
    }

    public function customerUpdate(Request $request, $id) {
        $this->authorizeCustomerAccess($id);
        $customer = Customer::findOrFail($id);
        $user = $customer->user;

        $request->validate([
            'first_name' => 'required',
            'last_name' => 'required',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'portal_email' => 'nullable|email|unique:users,email,' . $user->id,
            'new_password' => 'nullable|min:8',
            'health_insurance_type' => 'nullable|in:gesetzlich,privat',
            'gender' => 'nullable|in:male,female,diverse',
        ]);

        // Sensible Kundenakte-Felder: Änderungen auditieren (nur Feldnamen ins Log)
        $sensitive = ['health_insurance_number','health_insurance_company','health_insurance_type','pension_insurance_number','tax_id'];
        $changedSensitive = [];
        foreach ($sensitive as $sf) {
            if ($request->has($sf) && (string) $request->input($sf) !== (string) $customer->$sf) {
                $changedSensitive[] = $sf;
            }
        }
        if ($changedSensitive) {
            \App\Models\ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'sensitive_data_updated',
                'entity_type' => 'customer',
                'entity_id' => $customer->id,
                'meta' => json_encode(['fields' => $changedSensitive], JSON_UNESCAPED_UNICODE),
            ]);
        }

        $addressChanged = $request->filled('street') || $request->filled('plz') || $request->filled('city');
        if ($addressChanged) {
            $address = $this->buildAddress($request);
        } else {
            $address = $request->address ?? $customer->address;
        }

        $data = [
            'phone' => $request->phone,
            'mobile' => $request->mobile,
            'address' => $address,
            'address2' => $request->address2,
            'email2' => $request->email2,
            'iban' => $request->iban,
            'iban2' => $request->iban2,
            'account_holder' => $request->account_holder,
            'birth_date' => $request->birth_date ?: null,
            'marital_status' => $request->marital_status,
            'gender' => in_array($request->gender, ['male','female','diverse'], true) ? $request->gender : null,
            'preferred_lang' => $request->preferred_lang,
            'nationality' => $request->nationality,
            'occupation' => $request->occupation,
            'customer_type' => $request->customer_type,
            'company_name' => $request->company_name,
            'company_type' => $request->company_type,
            'health_insurance_type' => in_array($request->health_insurance_type, ['gesetzlich','privat'], true) ? $request->health_insurance_type : null,
            'health_insurance_company' => $request->health_insurance_company ?: null,
            'health_insurance_number' => $request->health_insurance_number ?: null,
            'pension_insurance_number' => $request->pension_insurance_number ?: null,
            'tax_id' => $request->tax_id ?: null,
            // Zuordnung zu einem Vertriebspartner (dessen Portal ihn dann sieht).
            'partner_id' => $request->partner_id ?: null,
        ];

        // Strukturierte Adressfelder (wie sie das Kundenportal liest) mit
        // schreiben, wenn im Formular eine Adresse eingegeben wurde - sonst
        // erscheinen die Felder im Portal leer.
        if ($addressChanged) {
            $data = array_merge($data, $this->addressColumns($request));
        }

        // Nur Spalten speichern, die in der Tabelle wirklich existieren
        $columns = Schema::getColumnListing('customers');
        $customer->update(array_intersect_key($data, array_flip($columns)));

        // User-Daten: Name, E-Mail, optional neues Passwort
        $userData = ['name' => trim(($request->first_name ?? '') . ' ' . ($request->last_name ?? '')) ?: ($request->name ?? $user->name)];
        $newEmail = $request->filled('portal_email') ? $request->portal_email : $request->email;
        if ($newEmail) {
            $userData['email'] = $newEmail;
        }
        if ($request->filled('new_password')) {
            $userData['password'] = bcrypt($request->new_password);
            $userData['portal_password_set_at'] = now();
        }
        $user->update($userData);

        // Neue Familienmitglieder aus dem Familie-Tab speichern
        if (is_array($request->family_name)) {
            $request->validate([
                'family_kv_status' => 'nullable|array',
                'family_kv_status.*' => 'nullable|in:,mitglied,familienversichert',
                'family_kv_start' => 'nullable|array',
                'family_kv_start.*' => 'nullable|date',
            ]);
            foreach ($request->family_name as $i => $fname) {
                if (!trim((string) $fname)) continue;
                \App\Models\CustomerFamily::create([
                    'customer_id' => $customer->id,
                    'name' => trim($fname),
                    'relation' => $request->family_relation[$i] ?? 'Kind',
                    'birth_date' => ($request->family_birth[$i] ?? null) ?: null,
                    // KV-Daten je Person (Spec Teil 3 / Final Polish Punkt 1)
                    'health_insurance_company' => ($request->family_kv_company[$i] ?? null) ?: null,
                    'health_insurance_number' => ($request->family_kv_nr[$i] ?? null) ?: null,
                    'health_insurance_status' => ($request->family_kv_status[$i] ?? null) ?: null,
                    'health_insurance_start' => ($request->family_kv_start[$i] ?? null) ?: null,
                    'steuer_nr' => ($request->family_steuer[$i] ?? null) ?: null,
                ]);
            }
        }

        return redirect()->route('admin.customer', $id)->with('success', 'Kundendaten aktualisiert.');
    }

    private function buildAddress(Request $request): ?string {
        $line1 = trim(($request->street ?? '') . ' ' . ($request->street_nr ?? ''));
        $line2 = trim(($request->plz ?? '') . ' ' . ($request->city ?? ''));
        $line3 = trim($request->country ?? '');
        $parts = array_filter([$line1, $line2, $line3], fn($p) => $p !== '');
        return $parts ? implode(', ', $parts) : null;
    }

    /**
     * Strukturierte Adressspalten aus den Formularfeldern, exakt so, wie sie
     * das Kundenportal (Profilseite) liest. So sind admin-seitig erfasste
     * Adressen im Portal sofort sichtbar und nicht leer.
     */
    private function addressColumns(Request $request): array {
        return [
            'address_street'       => $request->street ?: null,
            'address_house_number' => $request->street_nr ?: null,
            'address_zip'          => $request->plz ?: null,
            'address_city'         => $request->city ?: null,
        ];
    }

    private function splitAddress(?string $address): array {
        $parts = ['street' => '', 'street_nr' => '', 'plz' => '', 'city' => '', 'country' => ''];
        if (!$address) return $parts;

        $segments = array_map('trim', explode(',', $address));

        if (isset($segments[0])) {
            if (preg_match('/^(.*?)\s+(\d+\s*[a-zA-Z]?[\-\/]?\d*[a-zA-Z]?)$/u', $segments[0], $m)) {
                $parts['street'] = trim($m[1]);
                $parts['street_nr'] = trim($m[2]);
            } else {
                $parts['street'] = $segments[0];
            }
        }

        if (isset($segments[1])) {
            if (preg_match('/^(\d{4,5})\s+(.+)$/u', $segments[1], $m)) {
                $parts['plz'] = $m[1];
                $parts['city'] = trim($m[2]);
            } else {
                $parts['city'] = $segments[1];
            }
        }

        if (isset($segments[2])) {
            $parts['country'] = $segments[2];
        }

        return $parts;
    }

    public function storeNote(Request $request, $id) {
        $this->authorizeCustomerAccess($id);
        $request->validate(['note' => 'required']);
        \App\Models\CustomerNote::create([
            'customer_id' => $id,
            'created_by' => auth()->id(),
            'note' => $request->note,
            'type' => $request->type ?? 'note',
            'due_date' => $request->due_date ?: null,
            'is_done' => false,
        ]);
        return back()->with('success', 'Notiz hinzugefügt.');
    }

    public function noteMarkDone($id) {
        $note = \App\Models\CustomerNote::findOrFail($id);
        $this->authorizeCustomerAccess($note->customer_id);
        $note->update(['is_done' => !$note->is_done]);
        return back()->with('success', 'Status aktualisiert.');
    }

    public function storeDocument(Request $request, $id) {
        $this->authorizeCustomerAccess($id);
        $request->validate([
            'documents' => 'required|array|min:1|max:20',
            'documents.*' => 'file|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx|max:10240',
            'category' => 'nullable|in:contract,police,invoice,identity,claim,other',
            'visibility' => 'nullable|in:customer,internal',
            'color' => 'nullable|in:green,yellow,red',
            'contract_id' => 'nullable|string',
        ]);

        // Vertragszuordnung nur zulassen, wenn der Vertrag zu DIESEM Kunden gehört.
        $contractId = $request->filled('contract_id')
            ? \App\Models\Contract::where('id', $request->contract_id)->where('customer_id', $id)->value('id')
            : null;

        $created = [];
        foreach ($request->file('documents') as $file) {
            // Neue Uploads landen grundsätzlich im privaten Storage.
            $path = $file->store("customers/$id/documents", 'local');
            $doc = \App\Models\Document::create([
                'id' => \Illuminate\Support\Str::uuid(),
                'customer_id' => $id,
                'contract_id' => $contractId,
                'category' => $request->category ?? 'other',
                'color' => $request->color ?? 'green',
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'disk' => 'local',
                // Über die Sichtbarkeit entscheidet ausschließlich der Mitarbeiter.
                'visibility' => $request->visibility ?? 'customer',
                'uploaded_by' => auth()->id(),
                'file_size' => $file->getSize(),
            ]);
            $created[] = $doc;

            \App\Models\ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'document_uploaded',
                'entity_type' => 'document',
                'entity_id' => $doc->id,
                'meta' => json_encode(['customer_id' => (string) $id, 'file' => $doc->file_name, 'visibility' => $doc->visibility], JSON_UNESCAPED_UNICODE),
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'count' => count($created)]);
        }
        return back()->with('success', count($created) . ' Dokument(e) hochgeladen.');
    }

    public function storeFamily(Request $request, $id) {
        $this->authorizeCustomerAccess($id);
        $request->validate(['name' => 'required']);
        $request->validate([
            'health_insurance_status' => 'nullable|in:mitglied,familienversichert',
            'health_insurance_start' => 'nullable|date',
        ]);
        \App\Models\CustomerFamily::create([
            'customer_id' => $id,
            'name' => $request->name,
            'relation' => $request->relation ?? 'Kind',
            'birth_date' => $request->birth_date ?: null,
            'health_insurance_status' => $request->health_insurance_status,
            'health_insurance_company' => $request->health_insurance_company,
            'health_insurance_number' => $request->health_insurance_number ?: null,
            'health_insurance_start' => $request->health_insurance_start ?: null,
        ]);
        return back()->with('success', 'Familienmitglied hinzugefuegt.');
    }

    public function storeVehicle(Request $request, $id) {
        $this->authorizeCustomerAccess($id);
        $request->validate(['brand' => 'required']);
        \App\Models\CustomerVehicle::create([
            'customer_id' => $id,
            'brand' => $request->brand,
            'model' => $request->model,
            'license_plate' => $request->license_plate,
            'year' => $request->year,
            'vin' => $request->vin,
        ]);
        return back()->with('success', 'Fahrzeug hinzugefuegt.');
    }

    public function destroyFamily($id) {
        $f = \App\Models\CustomerFamily::findOrFail($id);
        $this->authorizeCustomerAccess($f->customer_id);
        $customerId = $f->customer_id;
        $f->delete();
        return redirect()->route('admin.customer.edit', $customerId)->with('success', 'Familienmitglied entfernt.');
    }

    /** Sicherer Dokument-Download (Admin) - nur mit Zugriff auf den Kunden. */
    /** Datei eines bestehenden Dokuments austauschen - setzt updated_by. (Punkt 3) */
    public function documentReplace(Request $request, $id) {
        $doc = \App\Models\Document::findOrFail($id);
        $this->authorizeCustomerAccess($doc->customer_id);
        $request->validate(['document' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx|max:10240']);

        $file = $request->file('document');
        $newPath = $file->store('customers/' . $doc->customer_id . '/documents', 'local');

        // Alte Datei entfernen (best effort), dann DB aktualisieren
        try { \Illuminate\Support\Facades\Storage::disk($doc->disk ?: 'public')->delete($doc->file_path); } catch (\Throwable $e) {}

        $doc->update([
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $newPath,
            'disk' => 'local',
            'file_size' => $file->getSize(),
            'updated_by' => auth()->id(),
        ]);

        \App\Models\ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'document_replaced',
            'entity_type' => 'document',
            'entity_id' => $doc->id,
            'meta' => json_encode(['customer_id' => (string) $doc->customer_id, 'file' => $doc->file_name], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'Dokument ersetzt.');
    }

    /**
     * Dokument-Metadaten bearbeiten: Vertragszuordnung, Kategorie, Sichtbarkeit
     * (intern/Kunde), Priorität und Anzeigename. Datei-Inhalt bleibt unberührt.
     */
    public function documentUpdate(Request $request, $id) {
        $doc = \App\Models\Document::findOrFail($id);
        $this->authorizeCustomerAccess($doc->customer_id);
        $request->validate([
            'category' => 'nullable|in:contract,police,invoice,identity,claim,other',
            'visibility' => 'nullable|in:customer,internal',
            'color' => 'nullable|in:green,yellow,red',
            'contract_id' => 'nullable|string',
            'file_name' => 'nullable|string|max:255',
        ]);

        // Vertrag muss zum selben Kunden gehören (Fremdzuordnung verhindern).
        $contractId = $request->filled('contract_id')
            ? \App\Models\Contract::where('id', $request->contract_id)->where('customer_id', $doc->customer_id)->value('id')
            : null;

        $doc->update([
            'contract_id' => $contractId,
            'category' => $request->category ?: $doc->category,
            'visibility' => $request->visibility ?: $doc->visibility,
            'color' => $request->color ?: ($doc->color ?? 'green'),
            'file_name' => $request->filled('file_name') ? $request->file_name : $doc->file_name,
            'updated_by' => auth()->id(),
        ]);

        \App\Models\ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'document_updated',
            'entity_type' => 'document',
            'entity_id' => $doc->id,
            'meta' => json_encode(['customer_id' => (string) $doc->customer_id, 'visibility' => $doc->visibility, 'contract_id' => $contractId], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'Dokument aktualisiert.');
    }

    /** Dokument löschen (Datei + Datensatz). Nur mit Zugriff auf den Kunden. */
    public function documentDestroy($id) {
        $doc = \App\Models\Document::findOrFail($id);
        $this->authorizeCustomerAccess($doc->customer_id);

        try {
            \Illuminate\Support\Facades\Storage::disk($doc->disk ?: 'public')->delete($doc->file_path);
        } catch (\Throwable $e) { /* Datei evtl. schon weg - Datensatz trotzdem entfernen */ }

        \App\Models\ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'document_deleted',
            'entity_type' => 'document',
            'entity_id' => $doc->id,
            'meta' => json_encode(['customer_id' => (string) $doc->customer_id, 'file' => $doc->file_name], JSON_UNESCAPED_UNICODE),
        ]);

        $doc->delete();

        return back()->with('success', 'Dokument gelöscht.');
    }

    public function documentDownload($id) {
        $doc = \App\Models\Document::findOrFail($id);
        $this->authorizeCustomerAccess($doc->customer_id);
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

    public function downloadAttachment($id) {
        $a = \App\Models\TicketAttachment::findOrFail($id);
        $ticket = Ticket::findOrFail($a->ticket_id);
        $this->authorizeTicketAccess($ticket);
        if (($a->disk ?? 'public') === 'local') {
            return \Illuminate\Support\Facades\Storage::disk('local')->download($a->file_path, $a->file_name);
        }
        // 404 statt 500, wenn die Datei fehlt (z. B. manuell geloescht)
        abort_unless(is_file(storage_path('app/public/' . $a->file_path)), 404);
        return response()->download(storage_path('app/public/' . $a->file_path), $a->file_name);
    }

    public function mergeForm($id) {
        $this->authorizeCustomerAccess($id);
        $customer = \App\Models\Customer::with('user')->findOrFail($id);
        $others = \App\Models\Customer::with('user')->where('id', '!=', $id)->get()
            ->sortBy(fn($c) => $c->user?->name ?? '');
        return view('admin.customer_merge', compact('customer', 'others'));
    }

    public function mergeCustomers(Request $request, $id) {
        $this->authorizeCustomerAccess($id);
        $request->validate(['duplicate_id' => 'required|different:id']);
        $this->authorizeCustomerAccess($request->duplicate_id);
        $primary = \App\Models\Customer::findOrFail($id);
        $dup = \App\Models\Customer::findOrFail($request->duplicate_id);
        if ($primary->id === $dup->id) return back()->with('success', 'Gleicher Kunde gewählt.');

        // 1) Alle abhängigen Datensätze umhängen
        foreach ([
            \App\Models\Contract::class, \App\Models\Ticket::class, \App\Models\Document::class,
            \App\Models\CustomerNote::class, \App\Models\CustomerFamily::class, \App\Models\CustomerVehicle::class,
            \App\Models\CustomerTimeline::class, \App\Models\Appointment::class, \App\Models\CustomerChangeRequest::class,
            \App\Models\CustomerAddress::class, \App\Models\CustomerContact::class, \App\Models\InternalMessage::class,
        ] as $model) {
            $model::where('customer_id', $dup->id)->update(['customer_id' => $primary->id]);
        }
        \Illuminate\Support\Facades\DB::table('employee_customers')->where('customer_id', $dup->id)->update(['customer_id' => $primary->id]);

        // 2) Fehlende Felder vom Duplikat übernehmen
        $request->validate(['gender' => 'nullable|in:male,female,diverse']);

        foreach (['phone','mobile','address','address2','iban','iban2','birth_date','marital_status','nationality','occupation','email2','company_name','company_type','gender'] as $f) {
            if (empty($primary->$f) && !empty($dup->$f)) $primary->$f = $dup->$f;
        }
        $primary->save();

        // 3) Duplikat + dessen User löschen
        $dupName = $dup->user?->name;
        $dupUser = $dup->user;
        $dup->delete();
        if ($dupUser && $dupUser->id !== $primary->user_id) $dupUser->delete();

        \App\Models\ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'customers_merged',
            'entity_type' => 'customer',
            'entity_id' => $primary->id,
            'meta' => json_encode(['merged_from' => $dupName, 'into' => $primary->user?->name]),
        ]);
        return redirect()->route('admin.customer', $primary->id)->with('success', 'Kunden erfolgreich zusammengeführt.');
    }

    public function bulkAssign(Request $request) {
        $request->validate([
            'customer_ids' => 'required|array|min:1',
            'employee_id' => 'required|exists:users,id',
            'reason' => 'required|string|max:500',
        ]);
        $employee = \App\Models\User::whereIn('role', ['employee', 'manager', 'support'])->findOrFail($request->employee_id);
        $count = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $employee, &$count) {
            foreach ($request->customer_ids as $cid) {
                $customer = Customer::find($cid);
                if (!$customer) continue;
                $previous = $customer->betreuer()->pluck('users.name')->implode(', ');
                if ($request->boolean('replace_existing')) {
                    $customer->betreuer()->sync([$employee->id]);
                } else {
                    $customer->betreuer()->syncWithoutDetaching([$employee->id]);
                }
                \App\Models\ActivityLog::create([
                    'user_id' => auth()->id(),
                    'action' => 'customer_reassigned',
                    'entity_type' => 'customer',
                    'entity_id' => $customer->id,
                    'meta' => json_encode([
                        'customer' => $customer->user?->name,
                        'from' => $previous ?: 'niemand',
                        'to' => $employee->name,
                        'reason' => $request->reason,
                        'mode' => $request->boolean('replace_existing') ? 'ersetzt' : 'hinzugefuegt',
                    ], JSON_UNESCAPED_UNICODE),
                ]);
                $count++;
            }
        });
        return back()->with('success', $count . ' Kunden wurden ' . $employee->name . ' zugewiesen.');
    }

    public function destroyCustomer($id) {
        $this->authorizeCustomerAccess($id);
        $customer = \App\Models\Customer::findOrFail($id);
        app(\App\Services\CustomerDeletionService::class)->delete($customer, auth()->id());
        return redirect()->route('admin.customers')->with('success', 'Kunde gelöscht.');
    }

    /**
     * Mehrere Kunden auf einmal löschen (nur admin, Routen-Middleware).
     * Nutzt exakt dieselbe DSGVO-Löschlogik wie die Einzellöschung.
     */
    public function bulkDestroyCustomers(\Illuminate\Http\Request $request) {
        // Auswahl kommt aus dem Formular als EIN kommagetrenntes Feld (erlaubt
        // sehr große Löschmengen ohne max_input_vars-Limit); direkte API-/Test-
        // Aufrufe dürfen weiterhin ein Array senden.
        $ids = $request->input('customer_ids', []);
        if (is_string($ids)) {
            $ids = array_filter(array_map('trim', explode(',', $ids)));
        }
        $request->merge(['customer_ids' => array_values($ids)]);

        // Bewusstes Sicherheitslimit: über die Weboberfläche dürfen höchstens
        // 30 Kunden auf einmal gelöscht werden (Schutz vor versehentlichem
        // Massenlöschen). Ein vollständiges Leeren läuft über `customers:purge`.
        $data = $request->validate([
            'customer_ids' => 'required|array|min:1|max:30',
            'customer_ids.*' => 'uuid',
        ], [
            'customer_ids.max' => 'Es können höchstens 30 Kunden auf einmal gelöscht werden.',
        ]);

        $service = app(\App\Services\CustomerDeletionService::class);
        $deleted = 0;
        foreach (\App\Models\Customer::whereIn('id', $data['customer_ids'])->get() as $customer) {
            $service->delete($customer, auth()->id());
            $deleted++;
        }

        return redirect()->route('admin.customers')
            ->with('success', $deleted . ' Kunde(n) endgültig gelöscht.');
    }

    public function customerTimeline($id) {
        $this->authorizeCustomerAccess($id);
        $customer = \App\Models\Customer::with(['user','timeline.user'])->findOrFail($id);
        return view('admin.customer_timeline', compact('customer'));
    }

    public function globalSearch(\Illuminate\Http\Request $request) {
        $q = $request->get('q', '');
        if (strlen($q) < 2) return response()->json([]);
        $vids = $this->visibleCustomerIds();
        $customers = \App\Models\Customer::with('user')
            ->when($vids !== null, fn($qq) => $qq->whereIn('customers.id', $vids))
            ->where(function($query) use ($q) {
                $query->whereHas('user', fn($u) => $u->where('name','like',"%$q%")->orWhere('email','like',"%$q%"))
                      ->orWhere('customer_number','like',"%$q%");
            })
            ->limit(5)->get()->map(fn($c) => [
                'type' => 'customer',
                'icon' => '👤',
                'title' => $c->user?->name,
                'sub' => $c->customer_number,
                'url' => route('admin.customer', $c->id),
            ]);
        $contracts = \App\Models\Contract::with('customer.user')
            ->when($vids !== null, fn($qq) => $qq->whereIn('customer_id', $vids))
            ->where(function($query) use ($q) {
                $query->where('contract_number','like',"%$q%")
                      ->orWhere('insurer','like',"%$q%");
            })
            ->limit(3)->get()->map(fn($c) => [
                'type' => 'contract',
                'icon' => '📄',
                'title' => $c->insurer,
                'sub' => $c->contract_number,
                'url' => route('admin.customer', $c->customer_id),
            ]);
        $tickets = \App\Models\Ticket::with('customer.user')
            ->when($vids !== null, fn($qq) => $qq->whereIn('customer_id', $vids))
            ->where(function($query) use ($q) {
                $query->where('subject','like',"%$q%")
                      ->orWhere('ticket_number','like',"%$q%");
            })
            ->limit(3)->get()->map(fn($t) => [
                'type' => 'ticket',
                'icon' => '💬',
                'title' => $t->subject,
                'sub' => trim(($t->ticket_number ? $t->ticket_number . ' · ' : '') . ($t->customer?->user?->name ?? '')),
                'url' => route('admin.ticket', $t->id),
            ]);
        return response()->json(array_merge(
            $customers->toArray(),
            $contracts->toArray(),
            $tickets->toArray()
        ));
    }

}
