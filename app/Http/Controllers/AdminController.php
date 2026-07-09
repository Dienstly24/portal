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
        }
    }

    public function dashboard() {
        $ids = $this->visibleCustomerIds();
        return view('admin.dashboard', [
            'totalCustomers' => $ids === null ? Customer::count() : count($ids),
            'activeContracts' => Contract::where('status','active')->when($ids !== null, fn($q) => $q->whereIn('customer_id', $ids))->count(),
            'openTickets' => Ticket::whereIn('status',['open','in_progress'])->when($ids !== null, fn($q) => $q->whereIn('customer_id', $ids))->count(),
            'pendingApprovals' => \App\Models\CustomerChangeRequest::where('status','pending')->when($ids !== null, fn($q) => $q->whereIn('customer_id', $ids))->count(),
            'recentTickets' => Ticket::with('customer.user')->when($ids !== null, fn($q) => $q->whereIn('customer_id', $ids))->latest()->take(5)->get(),
            'recentApprovals' => \App\Models\CustomerChangeRequest::with('customer.user')->where('status','pending')->when($ids !== null, fn($q) => $q->whereIn('customer_id', $ids))->latest()->take(5)->get(),
        ]);
    }

    public function customers() {
        $employees = \App\Models\User::whereIn('role', ['employee', 'manager', 'support'])->orderBy('name')->get();
        $query = $this->scopeCustomers(Customer::with(['user', 'betreuer']));
        if (request('betreuer')) {
            $query->whereHas('betreuer', fn($q) => $q->where('users.id', request('betreuer')));
        }
        $customers = $query->latest()->get();
        return view('admin.customers', compact('customers', 'employees'));
    }

    public function customerShow($id) {
        $this->authorizeCustomerAccess($id);
        $customer = Customer::with(['user','contracts.vehicleDetail','contracts.energyDetail','contracts.internetDetail','tickets','documents','changeRequests.reviewer'])->findOrFail($id);
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
        $request->validate([
            'type' => 'required',
            'insurer' => 'required',
            'status' => 'required',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
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

        $contract = Contract::create([
            'id' => Str::uuid(),
            'customer_id' => $customerId,
            'contract_number' => $request->contract_number ?: ('V-' . strtoupper(Str::random(8))),
            'type' => $request->type,
            'insurer' => $request->insurer,
            'status' => $request->status,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'notes' => $request->notes,
        ]);

        // Spartenspezifische Detaildatensätze (Spec Teil 4/5)
        if ($request->type === 'kfz' && $request->filled('vehicle')) {
            $v = $request->input('vehicle');
            $claims = collect($v['claims'] ?? [])->filter(fn($c) => !empty($c['year']))->values()->all();
            \App\Models\ContractVehicleDetail::create([
                'contract_id' => $contract->id,
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
            ]);
        } elseif ($request->type === 'strom_gas' && $request->filled('energy')) {
            \App\Models\ContractEnergyDetail::create(
                ['contract_id' => $contract->id] + collect($request->input('energy'))
                    ->only(['tariff','consumption_kwh','meter_number','malo_id','meter_reading','grid_operator','metering_operator'])
                    ->all()
            );
        } elseif ($request->type === 'internet' && $request->filled('internet')) {
            \App\Models\ContractInternetDetail::create(
                ['contract_id' => $contract->id] + collect($request->input('internet'))->only(['tariff','speed'])->all()
            );
        }

        return redirect()->route('admin.customer', $customerId)->with('success', 'Vertrag erfolgreich hinzugefügt.');
    }

    public function tickets() {
        $ids = $this->visibleCustomerIds();
        $tickets = Ticket::with('customer.user')->where('source', 'portal')->when($ids !== null, fn($q) => $q->whereIn('customer_id', $ids))->latest()->get();
        return view('admin.tickets', compact('tickets'));
    }

    public function inquiries() {
        $tickets = Ticket::whereIn('source', ['website', 'email'])->latest()->get();
        return view('admin.inquiries', compact('tickets'));
    }

    public function ticketShow($id) {
        $ticket = Ticket::with(['customer.user','messages.sender'])->findOrFail($id);
        $this->authorizeTicketAccess($ticket);
        return view('admin.ticket_show', compact('ticket'));
    }

    public function ticketReply(Request $request, $id) {
        $request->validate(['body' => 'required', 'status' => 'required|in:open,in_progress,waiting,closed']);
        $ticket = Ticket::findOrFail($id);
        $this->authorizeTicketAccess($ticket);
        \App\Models\TicketMessage::create([
            'id' => Str::uuid(),
            'ticket_id' => $ticket->id,
            'sender_id' => auth()->id(),
            'body' => $request->body,
            'is_internal' => $request->has('is_internal'),
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
        $ticket->update(['status' => $request->status]);
        if (!$request->has('is_internal')) {
            $ticket->load('customer.user');
            $email = $ticket->customer?->user?->email;
            if ($email && !str_contains($email, '@dienstly24.internal')) {
                try {
                    \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\TicketReplyMail($ticket, $request->body));
                } catch (\Throwable $e) { \Log::warning('Ticket reply mail failed: ' . $e->getMessage()); }
            }
        }
        return back()->with('success', 'Antwort gesendet.');
    }

    public function createCustomer() {
        return view('admin.customer_create');
    }

    public function storeCustomer(Request $request) {
        $request->validate([
            'first_name' => 'required',
            'last_name' => 'required',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8',
        ]);
        $fullName = $request->first_name . ' ' . $request->last_name;
        $address = $this->buildAddress($request);
        $user = User::create([
            'name' => $fullName,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role' => 'customer',
        ]);
        $customer = Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(Str::random(8)),
            'phone' => $request->mobile ?? $request->phone,
            'address' => $address,
            'birth_date' => $request->birth_date,
            'preferred_lang' => $request->preferred_lang ?? 'de',
            'customer_type' => $request->customer_type ?? 'privat',
            'company_name' => $request->customer_type === 'firma' ? $request->company_name : null,
            'company_type' => $request->customer_type === 'firma' ? $request->company_type : null,
        ]);
        if ($request->email && !str_contains($request->email, '@dienstly24.internal')) {
            try {
                \Illuminate\Support\Facades\Mail::to($request->email)->send(new \App\Mail\CustomerWelcomeMail(
                    $fullName, $request->email, $request->password, $request->preferred_lang ?? 'de'
                ));
            } catch (\Throwable $e) { \Log::warning('Welcome mail failed: ' . $e->getMessage()); }
        }
        return redirect()->route("admin.customer", $customer->id)->with("success", "Kunde erfolgreich erstellt.");
    }

    public function customerEdit($id) {
        $this->authorizeCustomerAccess($id);
        $customer = Customer::with('user')->findOrFail($id);
        $addr = $this->splitAddress($customer->address);
        return view('admin.customer_edit', compact('customer', 'addr'));
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
        ]);

        if ($request->filled('street') || $request->filled('plz') || $request->filled('city')) {
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
            'birth_date' => $request->birth_date ?: null,
            'marital_status' => $request->marital_status,
            'gender' => in_array($request->gender, ['male','female','diverse'], true) ? $request->gender : null,
            'salutation' => in_array($request->salutation, ['herr','frau','divers','firma'], true) ? $request->salutation : null,
            'preferred_lang' => $request->preferred_lang,
            'nationality' => $request->nationality,
            'occupation' => $request->occupation,
            'customer_type' => $request->customer_type,
            'company_name' => $request->company_name,
            'company_type' => $request->company_type,
        ];

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
        }
        $user->update($userData);

        // Neue Familienmitglieder aus dem Familie-Tab speichern
        if (is_array($request->family_name)) {
            $famCols = Schema::getColumnListing((new \App\Models\CustomerFamily)->getTable());
            $kvCol = null; $taxCol = null;
            foreach ($famCols as $c) {
                if (!$kvCol && str_contains($c, 'kv')) $kvCol = $c;
                if (!$taxCol && (str_contains($c, 'steuer') || str_contains($c, 'tax'))) $taxCol = $c;
            }
            foreach ($request->family_name as $i => $fname) {
                if (!trim((string) $fname)) continue;
                $row = [
                    'customer_id' => $customer->id,
                    'name' => trim($fname),
                    'relation' => $request->family_relation[$i] ?? 'Kind',
                    'birth_date' => ($request->family_birth[$i] ?? null) ?: null,
                ];
                if ($kvCol) $row[$kvCol] = $request->family_kv_nr[$i] ?? null;
                if ($taxCol) $row[$taxCol] = $request->family_steuer[$i] ?? null;
                $row['geschlecht'] = $request->family_geschlecht[$i] ?? null;
                \App\Models\CustomerFamily::forceCreate(array_intersect_key($row, array_flip($famCols)));
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
        $request->validate(['document' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx|max:10240']);
        $file = $request->file('document');
        $path = $file->store("customers/$id/documents", 'public');
        \App\Models\Document::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'customer_id' => $id,
            'category' => $request->category ?? 'other',
            'color' => $request->color ?? 'green',
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
        ]);
        return back()->with('success', 'Dokument hochgeladen.');
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
    public function documentDownload($id) {
        $doc = \App\Models\Document::findOrFail($id);
        $this->authorizeCustomerAccess($doc->customer_id);
        $disk = $doc->disk ?: 'public';
        abort_unless(\Illuminate\Support\Facades\Storage::disk($disk)->exists($doc->file_path), 404);
        return \Illuminate\Support\Facades\Storage::disk($disk)->download($doc->file_path, $doc->file_name);
    }

    public function downloadAttachment($id) {
        $a = \App\Models\TicketAttachment::findOrFail($id);
        $ticket = Ticket::findOrFail($a->ticket_id);
        $this->authorizeTicketAccess($ticket);
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
        $request->validate(['gender' => 'nullable|in:male,female,diverse', 'salutation' => 'nullable|in:herr,frau,divers,firma']);
        // Sensible Kundenakte-Felder: Änderungen auditieren (Spec Teil 3)
        $sensitive = ['health_insurance_number','health_insurance_company','health_insurance_type','pension_insurance_number','tax_id'];
        $changedSensitive = [];
        foreach ($sensitive as $sf) {
            if ($request->has($sf) && (string) $request->input($sf) !== (string) $customer->$sf) {
                $changedSensitive[] = $sf;
                $customer->$sf = $request->input($sf) ?: null;
            }
        }
        if ($changedSensitive) {
            \App\Models\ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'sensitive_data_updated',
                'entity_type' => 'customer',
                'entity_id' => $customer->id,
                // bewusst NUR Feldnamen, niemals Werte, ins Log
                'meta' => json_encode(['fields' => $changedSensitive], JSON_UNESCAPED_UNICODE),
            ]);
        }

        foreach (['phone','mobile','address','address2','iban','iban2','birth_date','marital_status','nationality','occupation','email2','company_name','company_type','gender','salutation'] as $f) {
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
        $user = $customer->user;
        $customer->delete();
        if ($user) {
            $user->delete();
        }
        return redirect()->route('admin.customers')->with('success', 'Kunde gelöscht.');
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
            ->where('subject','like',"%$q%")
            ->limit(3)->get()->map(fn($t) => [
                'type' => 'ticket',
                'icon' => '💬',
                'title' => $t->subject,
                'sub' => $t->customer?->user?->name,
                'url' => route('admin.ticket', $t->id),
            ]);
        return response()->json(array_merge(
            $customers->toArray(),
            $contracts->toArray(),
            $tickets->toArray()
        ));
    }

}
