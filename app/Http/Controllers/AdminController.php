<?php
namespace App\Http\Controllers;
use App\Http\Controllers\Concerns\ScopesCustomerAccess;
use App\Models\User;
use App\Models\Customer;
use App\Models\Contract;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

class AdminController extends Controller
{
    use ScopesCustomerAccess;

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

    /**
     * Dokumente im Dokumenten-Eingang (Smart Upload, noch ohne Kunde)
     * duerfen von Mitarbeitern bearbeitet werden; sobald ein Kunde
     * zugeordnet ist, gilt der normale Portfolio-Check.
     */
    private function authorizeDocumentAccess(\App\Models\Document $doc): void {
        if ($doc->customer_id !== null) {
            $this->authorizeCustomerAccess($doc->customer_id);
            return;
        }
        // Nicht zugeordnete Inbox-Dokumente: portfolio-begrenzte Mitarbeiter
        // duerfen nur die selbst hochgeladenen sehen - spiegelt
        // SmartDocumentUploadController::authorizeDocument. (Audit SEC-2/IDOR)
        $user = auth()->user();
        if (!$user?->canSeeAllCustomers() && (int) $doc->uploaded_by !== (int) $user?->id) {
            abort(403, 'Kein Zugriff auf dieses Dokument.');
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
            // Zuletzt GEOEFFNETE Kunden: pro Mitarbeiter aus customer_views,
            // nach eigenem Aufruf-Zeitstempel sortiert (nicht mehr nach Anlage-
            // datum). Zusaetzlich aufs Portfolio gescopt, damit ein alter View
            // auf einen inzwischen entzogenen Kunden nicht mehr auftaucht.
            'recentCustomers' => Customer::query()
                ->select('customers.*')
                ->join('customer_views', 'customer_views.customer_id', '=', 'customers.id')
                ->where('customer_views.user_id', auth()->id())
                ->when($ids !== null, fn($q) => $q->whereIn('customers.id', $ids))
                ->with('user')->withCount('contracts')
                ->orderByDesc('customer_views.viewed_at')
                ->take(8)->get(),
        ]);
    }

    public function customers(Request $request, \App\Services\Matching\DuplicateDetectionService $detection) {
        $employees = \App\Models\User::whereIn('role', ['employee', 'manager', 'support'])->orderBy('name')->get();
        // Hinweis-Badge: Anzahl offener Dubletten-Verdachtsfaelle (kurz gecacht).
        $dupCount = $detection->countCached($this->visibleCustomerIds());
        // Aktive Verträge mitladen (nur benötigte Spalten) für die Vertrags-Icons
        // in der Liste – ohne N+1-Abfragen pro Zeile.
        $query = $this->scopeCustomers(Customer::with([
            'user',
            'betreuer',
            'contracts' => fn($q) => $q->where('status', 'active')->select('id', 'customer_id', 'type', 'status'),
        ]));
        // Filter (E-Mail, Sparte, Portal-Status, Vertrags-Ablauf, letzter Kontakt,
        // Betreuer) + Sortierung aus den GET-Parametern anwenden.
        $this->applyCustomerFilters($query, $request);
        $this->applyCustomerSort($query, $request);
        // Seitenweise laden (25/Seite) – bleibt auch bei tausenden Kunden schnell.
        // withQueryString() erhält alle Filter/Sortierung über die Seiten hinweg.
        $customers = $query->paginate(25)->withQueryString();

        // Schnell-Kennzahlen (aufs Portfolio gescoped, OHNE die aktiven Filter):
        // beantworten "wie viele Strom-/Gas-Kunden, wie viele ohne E-Mail, wessen
        // Vertrag laeuft bald ab, wer wurde lange nicht kontaktiert" auf einen Blick
        // und dienen zugleich als klickbare Schnellfilter.
        $counts = [
            'total'      => $this->scopeCustomers(Customer::query())->count(),
            'strom'      => $this->countBySparte('strom'),
            'gas'        => $this->countBySparte('gas'),
            'kfz'        => $this->countBySparte('kfz'),
            'ohne_email' => $this->scopeCustomers(Customer::query())
                                ->whereDoesntHave('user', fn($u) => $this->scopeRealEmail($u))->count(),
            'ablauf'     => $this->scopeCustomers(Customer::query())
                                ->whereHas('contracts', fn($q) => $q->where('status', 'active')
                                    ->whereNotNull('end_date')
                                    ->whereBetween('end_date', [today(), today()->addDays(60)]))->count(),
            'kontakt'    => $this->scopeCustomers(Customer::query())
                                ->where(fn($q) => $q->whereNull('last_contact')
                                    ->orWhere('last_contact', '<', today()->subDays(180)))->count(),
        ];

        $sparten = \App\Models\Contract::TYPES;

        return view('admin.customers', compact('customers', 'employees', 'dupCount', 'counts', 'sparten'));
    }

    /** Zaehlt Kunden mit mind. einem AKTIVEN Vertrag der Sparte (portfolio-gescoped). */
    private function countBySparte(string $type): int {
        return $this->scopeCustomers(Customer::query())
            ->whereHas('contracts', fn($q) => $q->where('status', 'active')->where('type', $type))
            ->count();
    }

    /** Query-Bedingung "echte E-Mail" (kein Import-Platzhalter), analog User::hasRealEmail(). */
    private function scopeRealEmail($query) {
        return $query->whereNotNull('email')->where('email', 'not like', '%@dienstly24.internal%');
    }

    /**
     * Filter der Kundenliste aus den GET-Parametern. Alle Bedingungen sind
     * additiv (UND) und portfolio-vertraeglich (die Basis ist bereits gescoped).
     */
    private function applyCustomerFilters($query, Request $request): void {
        // Freitext-Suche ueber ALLE Kundenfelder (Name, E-Mail, Telefon,
        // Kundennummer, Vertragsnummer, Anschrift, PLZ/Ort, Kennzeichen, FIN,
        // Zaehlernummer ...). Ein Begriff + Enter zeigt alle passenden Kunden.
        if ($request->filled('q')) {
            $query->search((string) $request->q);
        }
        // Betreuer (nur admin/manager sehen den Filter, serverseitig aber
        // unschaedlich fuer Mitarbeiter, da deren Portfolio ohnehin gescoped ist).
        if ($request->filled('betreuer')) {
            $query->whereHas('betreuer', fn($q) => $q->where('users.id', $request->betreuer));
        }
        // E-Mail vorhanden / fehlt (echte Adresse, kein Import-Platzhalter).
        if ($request->email === 'mit') {
            $query->whereHas('user', fn($u) => $this->scopeRealEmail($u));
        } elseif ($request->email === 'ohne') {
            $query->whereDoesntHave('user', fn($u) => $this->scopeRealEmail($u));
        }
        // Sparte: mind. ein aktiver Vertrag dieses Typs.
        if ($request->filled('sparte')) {
            $query->whereHas('contracts', fn($q) => $q->where('status', 'active')->where('type', $request->sparte));
        }
        // Alphabet-Index: Kundenname (users.name) beginnt mit dem gewaehlten
        // Buchstaben. "XYZ" fasst die seltenen Anfangsbuchstaben X/Y/Z zusammen.
        // LIKE ist case-insensitiv (Standard-Collation), Umlaute (Ä/Ö/Ü) fallen
        // ueber die akzent-insensitive Collation auf A/O/U.
        if ($request->filled('buchstabe')) {
            $letters = $this->buchstabeToLetters((string) $request->buchstabe);
            if ($letters !== []) {
                $query->whereHas('user', function ($u) use ($letters) {
                    $u->where(function ($w) use ($letters) {
                        foreach ($letters as $l) {
                            $w->orWhere('name', 'like', $l . '%');
                        }
                    });
                });
            }
        }
        // Portal-Status (spiegelt Customer::portalStatus()).
        if ($request->filled('portal')) {
            $this->applyPortalStatusFilter($query, (string) $request->portal);
        }
        // Vertrag laeuft demnaechst ab: aktiver Vertrag mit end_date im Fenster.
        if ($request->filled('ablauf')) {
            $days = max(1, (int) $request->ablauf);
            $query->whereHas('contracts', fn($q) => $q->where('status', 'active')
                ->whereNotNull('end_date')
                ->whereBetween('end_date', [today(), today()->addDays($days)]));
        }
        // Lange nicht kontaktiert (nie oder laenger als X Tage her).
        if ($request->filled('kontakt')) {
            if ($request->kontakt === 'nie') {
                $query->whereNull('last_contact');
            } else {
                $days = max(1, (int) $request->kontakt);
                $query->where(fn($q) => $q->whereNull('last_contact')
                    ->orWhere('last_contact', '<', today()->subDays($days)));
            }
        }
    }

    /**
     * Uebersetzt einen Alphabet-Index-Schluessel in die zu treffenden
     * Anfangsbuchstaben. Einzelbuchstaben A-W bleiben unveraendert, "XYZ"
     * fasst X/Y/Z zusammen. Unbekannte Werte liefern ein leeres Array
     * (kein Filter).
     *
     * @return array<int,string>
     */
    private function buchstabeToLetters(string $key): array {
        $key = strtoupper(trim($key));
        if ($key === 'XYZ') {
            return ['X', 'Y', 'Z'];
        }
        if (strlen($key) === 1 && $key >= 'A' && $key <= 'W') {
            return [$key];
        }
        return [];
    }

    /**
     * Portal-Status als Query-Bedingung – gleiche Reihenfolge/Regeln wie
     * Customer::portalStatus(), damit Filter und Badge deckungsgleich sind.
     */
    private function applyPortalStatusFilter($query, string $key): void {
        if ($key === 'kein_account') {
            $query->whereDoesntHave('user', fn($u) => $this->scopeRealEmail($u));
            return;
        }
        // "Nicht deaktiviert" = is_active true oder (Alt-Datensatz) NULL.
        $notDeactivated = fn($u) => $u->where(fn($w) => $w->whereNull('is_active')->orWhere('is_active', true));
        $query->whereHas('user', function ($u) use ($key, $notDeactivated) {
            $this->scopeRealEmail($u);
            switch ($key) {
                case 'deaktiviert':
                    $u->where('is_active', false);
                    break;
                case 'erster_login':
                    $notDeactivated($u);
                    $u->whereNotNull('first_login_at');
                    break;
                case 'aktiviert':
                    $notDeactivated($u);
                    $u->whereNull('first_login_at')->whereNotNull('portal_password_set_at');
                    break;
                case 'einladung_gesendet':
                    $notDeactivated($u);
                    $u->whereNull('first_login_at')->whereNull('portal_password_set_at')->whereNotNull('invitation_sent_at');
                    break;
                case 'passwort_nicht_gesetzt':
                    $notDeactivated($u);
                    $u->whereNull('first_login_at')->whereNull('portal_password_set_at')->whereNull('invitation_sent_at');
                    break;
            }
        });
    }

    /** Sortierung der Kundenliste (Standard: neueste zuerst). */
    private function applyCustomerSort($query, Request $request): void {
        switch ($request->sort) {
            case 'name':
                // Kundenname liegt am User – Join nur zum Sortieren, customers.* behalten.
                $query->join('users', 'users.id', '=', 'customers.user_id')
                      ->orderBy('users.name')->select('customers.*');
                break;
            case 'name_desc':
                $query->join('users', 'users.id', '=', 'customers.user_id')
                      ->orderByDesc('users.name')->select('customers.*');
                break;
            case 'aelteste':
                $query->oldest();
                break;
            case 'kontakt':
                // Laengster ausstehender Kontakt zuerst (nie kontaktierte ganz oben).
                $query->orderByRaw('last_contact IS NULL DESC')->orderBy('last_contact', 'asc');
                break;
            default:
                $query->latest();
        }
    }

    public function customerShow($id) {
        $this->authorizeCustomerAccess($id);
        // "Zuletzt geoeffnet" pro Mitarbeiter festhalten: jeder Aufruf der Akte
        // aktualisiert den Zeitstempel, damit das Dashboard die reale Reihenfolge
        // zeigt (nur Staff - Kunden erreichen diese Route nicht).
        if (auth()->user()?->isStaff()) {
            \App\Models\CustomerView::updateOrCreate(
                ['user_id' => auth()->id(), 'customer_id' => $id],
                ['viewed_at' => now()]
            );
        }
        $customer = Customer::with(['user','contracts.vehicleDetail.claims','contracts.vehicleDetail.mileageReadings','contracts.energyDetail','contracts.internetDetail','contracts.switchReminders','tickets','documents','changeRequests.reviewer'])->findOrFail($id);
        // Interner Chat & Notizen (nur Staff - Zugriff bereits oben geprüft)
        $internalChat = \App\Models\InternalMessage::chat()->where('customer_id', $id)->with('sender')->orderBy('created_at')->get();
        $internalNotes = \App\Models\InternalMessage::note()->where('customer_id', $id)->with('sender')->latest()->get();
        // Direktnachrichten (Portal-Chat): Kundenantworten gelten mit dem
        // Oeffnen der Akte als vom Team gelesen.
        $customerMessages = \App\Models\CustomerMessage::where('customer_id', $id)
            ->with(['sender', 'attachments'])->orderBy('created_at')->get();
        \App\Models\CustomerMessage::where('customer_id', $id)->fromCustomer()->unread()->update(['read_at' => now()]);
        // "Verwandte Kunden": andere Akten mit gemeinsamen Merkmalen (Telefon,
        // Anschrift, E-Mail, IBAN ...) - Beziehungshinweis in der Kundenakte.
        $relations = app(\App\Services\Matching\DuplicateDetectionService::class)
            ->relationsFor($customer, $this->visibleCustomerIds(), 12);
        return view('admin.customer_show', compact('customer', 'internalChat', 'internalNotes', 'customerMessages', 'relations'));
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
            'subtype' => Contract::normalizeSubtype($request->type, $request->subtype),
            'insurer' => $request->insurer,
            'status' => $request->status,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'cancellation_date' => $request->cancellation_date,
            'notes' => $request->notes,
            'premium_amount' => $request->filled('premium_amount') ? $request->premium_amount : null,
            'premium_interval' => in_array($request->premium_interval, Contract::premiumIntervalKeys(), true) ? $request->premium_interval : 'monthly',
            'added_by' => auth()->user()?->name,
        ]);

        $this->syncContractDetails($contract, $request);

        return redirect()->route('admin.customer', $customerId)->with('success', 'Vertrag erfolgreich hinzugefügt.');
    }

    public function contractEdit($id) {
        $contract = Contract::with(['vehicleDetail.claims','vehicleDetail.mileageReadings','vehicleDetail.sfHistory','energyDetail','internetDetail','customer.user'])->findOrFail($id);
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
            'subtype' => Contract::normalizeSubtype($request->type, $request->subtype),
            'insurer' => $request->insurer,
            'status' => $request->status,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'cancellation_date' => $request->cancellation_date,
            'notes' => $request->notes,
            'premium_amount' => $request->filled('premium_amount') ? $request->premium_amount : null,
            'premium_interval' => in_array($request->premium_interval, Contract::premiumIntervalKeys(), true) ? $request->premium_interval : 'monthly',
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
            // Untergruppe je Sparte: GKV/PKV (Wechsel-Erinnerung, §175 SGB V)
            // bzw. Art der Krankenzusatz (ambulant/Zahn/Ausland).
            'subtype' => 'nullable|in:' . implode(',', Contract::subtypeKeys()),
            'insurer' => 'required|string|max:255',
            // Echte Versicherungsnummer, optional, aber eindeutig.
            'contract_number' => ['nullable', 'string', 'max:255', \Illuminate\Validation\Rule::unique('contracts', 'contract_number')->ignore($ignoreId)],
            'status' => 'required|in:active,pending,cancelled,expired',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'cancellation_date' => 'nullable|date',
            'notes' => 'nullable|string',
            // Beitrag + Zahlweise (was zahlt der Kunde, in welchem Rhythmus).
            'premium_amount' => 'nullable|numeric|min:0|max:9999999.99',
            'premium_interval' => 'nullable|in:' . implode(',', Contract::premiumIntervalKeys()),
            'energy.payment_amount' => 'nullable|numeric|min:0',
            'energy.payment_interval' => 'nullable|in:monatlich,vierteljaehrlich,halbjaehrlich,jaehrlich',
            // Energie: MaLo-ID hat 11 Ziffern und ist NICHT die Zählernummer
            'energy.malo_id' => ['nullable', 'regex:/^[0-9]{11}$/'],
            'energy.consumption_kwh' => 'nullable|integer|min:0',
            // Vorversorger (bisheriger Lieferant beim Wechsel).
            'energy.previous_provider' => 'nullable|string|max:150',
            'energy.previous_customer_number' => 'nullable|string|max:60',
            // Internet
            'internet.speed' => 'nullable|string|max:30',
            // ---- E-Scooter (schlankes Fahrzeug-Detail, eigener Namensraum) ----
            'escooter.license_plate' => 'nullable|string|max:20',
            'escooter.manufacturer' => 'nullable|string|max:255',
            'escooter.model' => 'nullable|string|max:255',
            'escooter.vin' => 'nullable|string|max:30',
            'escooter.has_teilkasko' => 'nullable|boolean',
            // ---- KFZ (Redesign 17.07.2026): alle Kataloge kommen aus dem Model ----
            'vehicle.vehicle_type' => 'nullable|in:' . implode(',', array_keys(\App\Models\ContractVehicleDetail::VEHICLE_TYPES)),
            'vehicle.license_plate' => 'nullable|string|max:20',
            'vehicle.manufacturer' => 'nullable|string|max:255',
            'vehicle.model' => 'nullable|string|max:255',
            'vehicle.vin' => 'nullable|string|max:30',
            'vehicle.hsn' => ['nullable', 'regex:/^[0-9]{4}$/'],
            'vehicle.tsn' => ['nullable', 'regex:/^[A-Za-z0-9]{1,10}$/'],
            'vehicle.first_registration' => 'nullable|date',
            'vehicle.acquisition_date' => 'nullable|date',
            'vehicle.vehicle_condition' => 'nullable|in:' . implode(',', array_keys(\App\Models\ContractVehicleDetail::CONDITIONS)),
            'vehicle.power_kw' => 'nullable|integer|min:1|max:2000',
            'vehicle.fuel_type' => 'nullable|in:' . implode(',', array_keys(\App\Models\ContractVehicleDetail::FUEL_TYPES)),
            'vehicle.transmission' => 'nullable|in:' . implode(',', array_keys(\App\Models\ContractVehicleDetail::TRANSMISSIONS)),
            'vehicle.color' => 'nullable|string|max:40',
            // Deckung: Haftpflicht ist immer enthalten; Vollkasko setzt Teilkasko voraus (wird im Sync erzwungen).
            'vehicle.has_teilkasko' => 'nullable|boolean',
            'vehicle.teilkasko_deductible' => 'nullable|integer|in:' . implode(',', \App\Models\ContractVehicleDetail::TK_DEDUCTIBLES),
            'vehicle.has_vollkasko' => 'nullable|boolean',
            'vehicle.vollkasko_deductible' => 'nullable|integer|in:' . implode(',', \App\Models\ContractVehicleDetail::VK_DEDUCTIBLES),
            'vehicle.extras' => 'nullable|array',
            'vehicle.extras.*' => 'in:' . implode(',', array_keys(\App\Models\ContractVehicleDetail::EXTRAS)),
            'vehicle.driver_groups' => 'nullable|array',
            'vehicle.driver_groups.*' => 'in:' . implode(',', array_keys(\App\Models\ContractVehicleDetail::DRIVER_GROUPS)),
            'vehicle.additional_drivers' => 'nullable|array',
            'vehicle.additional_drivers.*.name' => 'nullable|string|max:120',
            'vehicle.additional_drivers.*.birth_date' => 'nullable|date',
            'vehicle.additional_drivers.*.license_date' => 'nullable|date',
            'vehicle.holder_type' => 'nullable|in:' . implode(',', array_keys(\App\Models\ContractVehicleDetail::HOLDER_TYPES)),
            'vehicle.holder_name' => 'nullable|string|max:255',
            'vehicle.ownership_type' => 'nullable|in:' . implode(',', array_keys(\App\Models\ContractVehicleDetail::OWNERSHIP_TYPES)),
            // Nutzung / Kilometer
            'vehicle.initial_mileage' => 'nullable|integer|min:0|max:5000000',
            'vehicle.current_mileage' => 'nullable|integer|min:0|max:5000000',
            'vehicle.current_mileage_date' => 'nullable|date',
            // Buttons decken die Standardwerte ab; "custom" schaltet das
            // Freifeld fuer Sonderfaelle (8.000, 18.500, 22.500 km ...) frei.
            'vehicle.annual_mileage' => 'nullable|in:custom,' . implode(',', \App\Models\ContractVehicleDetail::ANNUAL_MILEAGE_OPTIONS),
            'vehicle.annual_mileage_custom' => 'nullable|integer|min:1000|max:150000|required_if:vehicle.annual_mileage,custom',

            // Vorversicherung (bisheriger Kfz-Versicherer beim Wechsel).
            'vehicle.previous_insurer' => 'nullable|string|max:120',
            'vehicle.previous_insurance_since' => 'nullable|string|max:60',
            'vehicle.previous_insurance_terminated_by_insurer' => 'nullable|in:0,1',
            // SF-Einstufung (Haftpflicht / Vollkasko getrennt)
            'vehicle.sf_liability_class' => 'nullable|in:' . implode(',', \App\Models\ContractVehicleDetail::sfClassKeys()),
            'vehicle.sf_liability_valid_from' => 'nullable|date',
            'vehicle.sf_liability_type' => 'nullable|in:' . implode(',', array_keys(\App\Models\ContractVehicleDetail::SF_TYPES)),
            'vehicle.sf_liability_special_reason' => 'nullable|in:' . implode(',', array_keys(\App\Models\ContractVehicleDetail::SF_SPECIAL_REASONS)),
            'vehicle.sf_liability_real_class' => 'nullable|in:' . implode(',', \App\Models\ContractVehicleDetail::sfClassKeys()),
            'vehicle.sf_comprehensive_class' => 'nullable|in:' . implode(',', \App\Models\ContractVehicleDetail::sfClassKeys()),
            'vehicle.sf_comprehensive_valid_from' => 'nullable|date',
            'vehicle.sf_comprehensive_type' => 'nullable|in:' . implode(',', array_keys(\App\Models\ContractVehicleDetail::SF_TYPES)),
            'vehicle.sf_comprehensive_special_reason' => 'nullable|in:' . implode(',', array_keys(\App\Models\ContractVehicleDetail::SF_SPECIAL_REASONS)),
            'vehicle.sf_comprehensive_real_class' => 'nullable|in:' . implode(',', \App\Models\ContractVehicleDetail::sfClassKeys()),
            // Schaeden (strukturierte Zeilen, eigene Tabelle)
            'vehicle.claim_rows' => 'nullable|array',
            'vehicle.claim_rows.*.claim_date' => 'nullable|date',
            'vehicle.claim_rows.*.claim_type' => 'nullable|in:' . implode(',', array_keys(\App\Models\VehicleClaim::TYPES)),
            'vehicle.claim_rows.*.damage_amount' => 'nullable|numeric|min:0|max:99999999',
            'vehicle.claim_rows.*.status' => 'nullable|in:' . implode(',', array_keys(\App\Models\VehicleClaim::STATUSES)),
            'vehicle.claim_rows.*.insurer' => 'nullable|string|max:255',
            'vehicle.claim_rows.*.notes' => 'nullable|string|max:2000',
        ], [
            'vehicle.annual_mileage_custom.required_if' => 'Bitte die eigene Jahresfahrleistung in km angeben.',
        ]);
    }

    /**
     * Spartenspezifische Detaildatensätze anlegen/aktualisieren (Spec Teil 4/5).
     * Beim Bearbeiten mit Typwechsel werden verwaiste Detaildaten entfernt.
     */
    private function syncContractDetails(Contract $contract, Request $request): void {
        // KFZ und E-Scooter teilen sich die Fahrzeugtabelle - beide behalten
        // ihr vehicleDetail (sonst wuerde ein Speichern das automatisch aus dem
        // Dokument angelegte E-Scooter-Detail loeschen).
        if (!in_array($contract->type, ['kfz', 'escooter'], true)) { $contract->vehicleDetail()->delete(); }
        if (!$contract->isEnergy())         { $contract->energyDetail()->delete(); }
        if ($contract->type !== 'internet') { $contract->internetDetail()->delete(); }

        if ($contract->type === 'kfz') {
            $this->syncVehicleDetail($contract, $request->input('vehicle', []));
        } elseif ($contract->type === 'escooter') {
            $this->syncEscooterDetail($contract, $request->input('escooter', []));
        } elseif ($contract->isEnergy()) {
            \App\Models\ContractEnergyDetail::updateOrCreate(
                ['contract_id' => $contract->id],
                collect($request->input('energy', []))
                    ->only(['tariff','consumption_kwh','meter_number','customer_number','malo_id','meter_reading','grid_operator','metering_operator','payment_amount','payment_interval','previous_provider','previous_customer_number'])
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

    /**
     * KFZ-Detail speichern (Redesign 17.07.2026). Erzwingt die Deckungs-
     * Hierarchie (Vollkasko nur mit Teilkasko), filtert Kataloge, pflegt
     * Schaeden als eigene Tabelle, legt km-Ablesungen an und schreibt den
     * SF-Verlauf fort statt ihn zu ueberschreiben.
     */
    private function syncVehicleDetail(Contract $contract, array $v): void {
        $blank = fn($key) => isset($v[$key]) && $v[$key] !== '' ? $v[$key] : null;

        // Deckung: Haftpflicht ist Pflicht (immer enthalten). Vollkasko ohne
        // Teilkasko ist fachlich unmoeglich -> wird hier hart abgeraeumt.
        $hasTk = !empty($v['has_teilkasko']);
        $hasVk = $hasTk && !empty($v['has_vollkasko']);

        // Kataloge serverseitig filtern (Whitelist, Reihenfolge des Katalogs).
        $extras = array_values(array_intersect(array_keys(\App\Models\ContractVehicleDetail::EXTRAS), (array) ($v['extras'] ?? [])));
        $driverGroups = array_values(array_intersect(array_keys(\App\Models\ContractVehicleDetail::DRIVER_GROUPS), (array) ($v['driver_groups'] ?? [])));
        $additionalDrivers = in_array('weitere_fahrer', $driverGroups, true)
            ? collect($v['additional_drivers'] ?? [])
                ->filter(fn($drv) => !empty($drv['name']))
                ->map(fn($drv) => [
                    'name' => trim((string) $drv['name']),
                    'birth_date' => $drv['birth_date'] ?? null,
                    'license_date' => $drv['license_date'] ?? null,
                ])->values()->all()
            : [];

        // SF: Art faellt auf "tatsaechlich" zurueck; Sondereinstufungs-Felder
        // (Grund + tatsaechliche Klasse) nur bei Sondereinstufung speichern.
        $sf = function (string $prefix) use ($v, $blank) {
            $class = $blank($prefix . '_class');
            $type = $class ? ($blank($prefix . '_type') ?: 'tatsaechlich') : null;
            return [
                $prefix . '_class' => $class,
                $prefix . '_valid_from' => $class ? $blank($prefix . '_valid_from') : null,
                $prefix . '_type' => $type,
                $prefix . '_special_reason' => $type === 'sondereinstufung' ? $blank($prefix . '_special_reason') : null,
                $prefix . '_real_class' => $type === 'sondereinstufung' ? $blank($prefix . '_real_class') : null,
            ];
        };
        $sfLiability = $sf('sf_liability');
        $sfComprehensive = $hasVk ? $sf('sf_comprehensive') : [
            'sf_comprehensive_class' => null, 'sf_comprehensive_valid_from' => null,
            'sf_comprehensive_type' => null, 'sf_comprehensive_special_reason' => null,
            'sf_comprehensive_real_class' => null,
        ];

        $detail = \App\Models\ContractVehicleDetail::updateOrCreate(
            ['contract_id' => $contract->id],
            array_merge([
                'vehicle_type' => $blank('vehicle_type'),
                'license_plate' => $blank('license_plate'),
                'manufacturer' => $blank('manufacturer'),
                'model' => $blank('model'),
                'vin' => $blank('vin'),
                'hsn' => $blank('hsn'),
                'tsn' => $blank('tsn') ? strtoupper($v['tsn']) : null,
                'first_registration' => $blank('first_registration'),
                'acquisition_date' => $blank('acquisition_date'),
                'vehicle_condition' => $blank('vehicle_condition'),
                'power_kw' => $blank('power_kw'),
                'fuel_type' => $blank('fuel_type'),
                'transmission' => $blank('transmission'),
                'color' => $blank('color'),
                'has_teilkasko' => $hasTk,
                'teilkasko_deductible' => $hasTk ? $blank('teilkasko_deductible') : null,
                'has_vollkasko' => $hasVk,
                'vollkasko_deductible' => $hasVk ? $blank('vollkasko_deductible') : null,
                'extras' => $extras,
                'driver_groups' => $driverGroups,
                'additional_drivers' => $additionalDrivers,
                'holder_type' => $blank('holder_type'),
                'holder_name' => ($blank('holder_type') === 'abweichender_halter') ? $blank('holder_name') : null,
                'ownership_type' => $blank('ownership_type'),
                'initial_mileage' => $blank('initial_mileage'),
                // "custom" = Freifeld-Wert (Sonderfaelle wie 18.500 km/Jahr).
                'annual_mileage' => $blank('annual_mileage') === 'custom' ? $blank('annual_mileage_custom') : $blank('annual_mileage'),
                // Vorversicherung: leerer Radio ("") = unbekannt (null).
                'previous_insurer' => $blank('previous_insurer'),
                'previous_insurance_since' => $blank('previous_insurance_since'),
                'previous_insurance_terminated_by_insurer' => $blank('previous_insurance_terminated_by_insurer') === null
                    ? null : ($v['previous_insurance_terminated_by_insurer'] === '1'),
            ], $sfLiability, $sfComprehensive)
        );

        // Schaeden: eingereichte Zeilen ersetzen den Bestand vollstaendig
        // (das Formular zeigt immer alle Schaeden inkl. Loeschen-Knopf).
        $detail->claims()->delete();
        foreach ((array) ($v['claim_rows'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $hasContent = collect(['claim_date', 'claim_type', 'damage_amount', 'insurer', 'notes'])
                ->contains(fn($key) => isset($row[$key]) && $row[$key] !== '');
            if (!$hasContent) continue;
            $detail->claims()->create([
                'claim_date' => $row['claim_date'] ?? null,
                'claim_type' => ($row['claim_type'] ?? '') !== '' ? $row['claim_type'] : null,
                'damage_amount' => ($row['damage_amount'] ?? '') !== '' ? $row['damage_amount'] : null,
                'status' => ($row['status'] ?? '') !== '' ? $row['status'] : null,
                'insurer' => ($row['insurer'] ?? '') !== '' ? $row['insurer'] : null,
                'notes' => ($row['notes'] ?? '') !== '' ? $row['notes'] : null,
            ]);
        }

        // Aktueller Kilometerstand: nur bei neuem Wert eine Ablesung anlegen -
        // die Historie bleibt vollstaendig erhalten.
        if ($blank('current_mileage') !== null) {
            $mileage = (int) $v['current_mileage'];
            $date = $blank('current_mileage_date') ?: now()->toDateString();
            $latest = $detail->mileageReadings()->first();
            if (!$latest || (int) $latest->mileage !== $mileage || $latest->reading_date->toDateString() !== $date) {
                $detail->mileageReadings()->create([
                    'mileage' => $mileage,
                    'reading_date' => $date,
                    'source' => 'staff',
                    'created_by' => auth()->user()?->name,
                ]);
            }
        }

        // SF-Verlauf fortschreiben (Teilkasko hat keine SF-Klasse).
        $this->syncSfHistory($detail, 'haftpflicht', $sfLiability['sf_liability_class'], $sfLiability['sf_liability_valid_from']);
        $this->syncSfHistory($detail, 'vollkasko', $sfComprehensive['sf_comprehensive_class'], $sfComprehensive['sf_comprehensive_valid_from']);
    }

    /**
     * E-Scooter-Detail speichern: schlanker als KFZ - nur Kennzeichen,
     * Hersteller/Modell und Fahrgestellnummer sowie die Deckung. E-Scooter
     * haben nur Haftpflicht oder Teilkasko (nie Vollkasko), keine SF-Klasse,
     * keine Kilometer und keine Selbstbeteiligungsstufen. Nutzt dieselbe
     * Fahrzeugtabelle wie KFZ (Fahrzeugtyp = escooter).
     */
    private function syncEscooterDetail(Contract $contract, array $v): void {
        $blank = fn($key) => isset($v[$key]) && $v[$key] !== '' ? $v[$key] : null;

        \App\Models\ContractVehicleDetail::updateOrCreate(
            ['contract_id' => $contract->id],
            [
                'vehicle_type' => 'escooter',
                'license_plate' => $blank('license_plate') ? mb_strtoupper($v['license_plate']) : null,
                'manufacturer' => $blank('manufacturer'),
                'model' => $blank('model'),
                'vin' => $blank('vin') ? strtoupper($v['vin']) : null,
                'has_teilkasko' => !empty($v['has_teilkasko']),
                'teilkasko_deductible' => null,
                'has_vollkasko' => false,
                'vollkasko_deductible' => null,
            ]
        );
    }

    /**
     * SF-Verlauf je Sparte: Klassenwechsel schliesst den offenen Eintrag
     * (gueltig bis = Vortag der neuen Einstufung) und legt einen neuen an.
     * Gleiche Klasse mit korrigiertem Datum aktualisiert nur das gueltig-ab.
     */
    private function syncSfHistory(\App\Models\ContractVehicleDetail $detail, string $branch, ?string $class, ?string $validFrom): void {
        $open = $detail->sfHistory()->where('branch', $branch)->whereNull('valid_until')->orderByDesc('created_at')->first();

        if (!$class) {
            if ($open) $open->update(['valid_until' => now()->toDateString()]);
            return;
        }
        if ($open && $open->sf_class === $class) {
            $openFrom = $open->valid_from?->toDateString();
            if ($openFrom !== $validFrom) $open->update(['valid_from' => $validFrom]);
            return;
        }
        if ($open) {
            $open->update(['valid_until' => $validFrom
                ? \Carbon\Carbon::parse($validFrom)->subDay()->toDateString()
                : now()->toDateString()]);
        }
        $detail->sfHistory()->create(['branch' => $branch, 'sf_class' => $class, 'valid_from' => $validFrom, 'valid_until' => null]);
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

    /**
     * Validierung fuer die beiden Telefonfelder: eine eindeutige deutsche
     * Mobilnummer gehoert ins Feld "Mobil", eine Festnetznummer ins Feld
     * "Telefon". Nur klare Verwechslungen werden abgewiesen (internationale
     * Nummern bleiben erlaubt); die Meldung sagt genau, wohin die Nummer gehoert.
     *
     * @return array<string,mixed>
     */
    private function phoneFieldRules(): array
    {
        return [
            'mobile' => ['nullable', 'string', 'max:40', function ($attr, $value, $fail) {
                if ($value && \App\Support\GermanPhone::isLandline($value)) {
                    $fail('Das sieht nach einer Festnetznummer aus – bitte ins Feld „Telefon" eintragen.');
                }
            }],
            'phone' => ['nullable', 'string', 'max:40', function ($attr, $value, $fail) {
                if ($value && \App\Support\GermanPhone::isMobile($value)) {
                    $fail('Das sieht nach einer Mobilnummer aus – bitte ins Feld „Mobil" eintragen.');
                }
            }],
        ];
    }

    public function storeCustomer(Request $request) {
        $request->validate([
            'first_name' => 'required',
            'last_name' => 'required',
            // E-Mail optional: liegt keine echte Adresse vor, bleibt das Feld
            // leer (kein Dummy) - der Mitarbeiter traegt sie spaeter nach.
            'email' => 'nullable|email|unique:users',
            // Passwort ist jetzt optional: ohne Eingabe greift der
            // Startpasswort-Flow (Geburtsdatum TT.MM.JJJJ bzw. Set-Link).
            'password' => 'nullable|min:8',
            // Bankverbindung darf schon bei der Neuanlage erfasst werden.
            'iban' => 'nullable|string|max:40',
            'account_holder' => 'nullable|string|max:120',
        ] + $this->phoneFieldRules());
        $fullName = $request->first_name . ' ' . $request->last_name;
        $address = $this->buildAddress($request);
        $addressColumns = $this->addressColumns($request);
        $user = User::create([
            'name' => $fullName,
            'email' => $request->email ?: null,
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
            } catch (\Throwable $e) {
                \Log::warning('Welcome mail failed: ' . $e->getMessage());
                // Seit dem synchronen Versand landen Fehler HIER statt
                // still in failed_jobs - dem Mitarbeiter anzeigen, sonst
                // wartet der Kunde vergeblich auf seine Zugangsdaten.
                session()->flash('error', 'Die Willkommens-Mail konnte NICHT versendet werden. Bitte in der Kundenakte "Einladung erneut senden" nutzen.');
            }
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
        ] + $this->phoneFieldRules());

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
        // E-Mail: eingetragene echte Adresse uebernehmen; ist das Feld leer,
        // bleibt/wird die E-Mail LEER (NULL) - kein Dummy. So laesst sich auch
        // eine alte Platzhalter-Adresse durch Leeren sauber entfernen.
        $newEmail = $request->filled('portal_email') ? $request->portal_email : $request->email;
        $userData['email'] = ($newEmail !== null && $newEmail !== '') ? $newEmail : null;
        if ($request->filled('new_password')) {
            $userData['password'] = bcrypt($request->new_password);
            $userData['portal_password_set_at'] = now();
        }
        // Zustand VOR dem Speichern merken: hatte der Kunde bisher eine echte
        // (nutzbare) E-Mail? Nur so laesst sich "E-Mail neu nachgetragen" erkennen.
        $hadRealEmail = $user->hasRealEmail();
        $user->update($userData);

        // Automatische Portal-Einladung, sobald eine echte E-Mail NEU nachgetragen
        // wird (analog zur Neuanlage in storeCustomer). So muss der Mitarbeiter die
        // Einladung nicht mehr separat ausloesen – sie geht direkt an den Kunden.
        $invited = false;
        if (!$hadRealEmail && $user->hasRealEmail()) {
            try {
                $customer->setRelation('user', $user);
                if ($request->filled('new_password')) {
                    // Passwort wurde in diesem Schritt gesetzt -> manuelle Willkommens-
                    // Mail mit dem Zugangspasswort (kein Ueberschreiben durch Set-Link).
                    $user->forceFill(['invitation_sent_at' => now()])->save();
                    \Illuminate\Support\Facades\Mail::to($user->email)->send(
                        new \App\Mail\CustomerWelcomeMail($customer, 'manual', $request->new_password)
                    );
                    $invited = true;
                } elseif ($user->invitation_sent_at === null
                    && $user->portal_password_set_at === null
                    && $user->first_login_at === null) {
                    // Noch kein Portal-Zugang angestossen -> Standard-Einladung
                    // (Startpasswort = Geburtsdatum bzw. Passwort-Setzen-Link).
                    app(\App\Services\Portal\PortalAccessService::class)->sendInvitation($customer, auth()->id());
                    $invited = true;
                }
            } catch (\Throwable $e) {
                \Log::warning('Auto-Einladung nach E-Mail-Nachtrag fehlgeschlagen: ' . $e->getMessage());
                session()->flash('error', 'Die Portal-Einladung konnte NICHT versendet werden. Bitte in der Kundenakte "Einladung erneut senden" nutzen.');
            }
        }

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
                    // In das VERSCHLUESSELTE Feld schreiben, nicht mehr in die
                    // Klartext-Spalte steuer_nr (Audit DB-2 / DSGVO).
                    'tax_id' => ($request->family_steuer[$i] ?? null) ?: null,
                ]);
            }
        }

        $msg = $invited
            ? 'Kundendaten aktualisiert. Einladung zum Portal wurde an ' . $user->email . ' gesendet.'
            : 'Kundendaten aktualisiert.';
        return redirect()->route('admin.customer', $id)->with('success', $msg);
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
        $this->authorizeDocumentAccess($doc);
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
        $this->authorizeDocumentAccess($doc);
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
        $this->authorizeDocumentAccess($doc);

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

    public function documentDownload(\Illuminate\Http\Request $request, $id) {
        $doc = \App\Models\Document::findOrFail($id);
        $this->authorizeDocumentAccess($doc);
        \App\Models\ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'document_viewed',
            'entity_type' => 'document',
            'entity_id' => $doc->id,
            'meta' => json_encode(['file' => $doc->file_name], JSON_UNESCAPED_UNICODE),
        ]);
        $disk = $doc->disk ?: 'public';
        abort_unless(\Illuminate\Support\Facades\Storage::disk($disk)->exists($doc->file_path), 404);
        // ?view=1 -> im Browser anzeigen (Vorschau, z.B. "Anzeigen"-Button im
        // Dokumenten-Eingang); sonst herunterladen.
        return $request->boolean('view')
            ? \Illuminate\Support\Facades\Storage::disk($disk)->response($doc->file_path, $doc->file_name)
            : \Illuminate\Support\Facades\Storage::disk($disk)->download($doc->file_path, $doc->file_name);
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

    /**
     * Systemweiter Dubletten-Abgleich: listet Verdachtspaare (Name,
     * Geburtsdatum, E-Mail, Adresse, Telefon) zur manuellen Pruefung.
     * Respektiert die Portfolio-Sicht der Mitarbeiter (nur eigene Kunden).
     */
    /** Signal-Text -> Filterkategorie (Schnellfilter auf der Dubletten-Seite). */
    private const SIGNAL_CATEGORIES = [
        'Gleicher Name' => 'name',
        'Sehr aehnlicher Name' => 'name',
        'Gleiche Anschrift' => 'address',
        'Gleiche E-Mail-Adresse' => 'email',
        'Gleiche Telefonnummer' => 'phone',
        'Gleiche Bankverbindung (IBAN)' => 'iban',
        'Gleiche Vertragsnummer' => 'contract',
        'Gleiches Geburtsdatum' => 'birthdate',
    ];

    public function duplicates(\App\Services\Matching\DuplicateDetectionService $detection) {
        $result = $detection->scan($this->visibleCustomerIds());
        $autoMin = \App\Services\Matching\DuplicateDetectionService::AUTO_MERGE_MIN_SCORE;

        // Jedes Paar mit Filterkategorien versehen + Kategorie-Zaehler fuer die
        // Schnellfilter-Buttons (Namen / Adressen / E-Mails / Telefon / IBAN ...).
        $counts = ['name' => 0, 'address' => 0, 'email' => 0, 'phone' => 0, 'iban' => 0, 'contract' => 0, 'birthdate' => 0];
        $pairs = array_map(function ($p) use (&$counts) {
            $cats = [];
            foreach ($p['signals'] as $s) {
                if (isset(self::SIGNAL_CATEGORIES[$s])) {
                    $cats[self::SIGNAL_CATEGORIES[$s]] = true;
                }
            }
            $p['categories'] = array_keys($cats);
            foreach ($p['categories'] as $c) {
                $counts[$c]++;
            }
            return $p;
        }, $result['pairs']);

        $strongCount = count(array_filter($pairs, fn ($p) => $p['score'] >= $autoMin));

        return view('admin.customer_duplicates', [
            'pairs'       => $pairs,
            'scanned'     => $result['scanned'],
            'capped'      => $result['capped'],
            'autoMin'     => $autoMin,
            'strongCount' => $strongCount,
            'catCounts'   => $counts,
            'relationCount' => \App\Models\CustomerRelationship::count(),
        ]);
    }

    /**
     * Markiert ein Paar als "kein Duplikat" -> es verschwindet aus der
     * Dubletten-Liste und erscheint stattdessen als Beziehung unter
     * "Verwandte Kunden". Reversibel (Beziehung entfernen).
     */
    public function dismissDuplicate(Request $request) {
        $data = $request->validate([
            'customer_a' => 'required|string',
            'customer_b' => 'required|string|different:customer_a',
            'note'       => 'nullable|string|max:255',
        ]);
        $this->authorizeCustomerAccess($data['customer_a']);
        $this->authorizeCustomerAccess($data['customer_b']);
        \App\Models\Customer::findOrFail($data['customer_a']);
        \App\Models\Customer::findOrFail($data['customer_b']);

        [$a, $b] = \App\Models\CustomerRelationship::pairKey($data['customer_a'], $data['customer_b']);
        \App\Models\CustomerRelationship::firstOrCreate(
            ['customer_a_id' => $a, 'customer_b_id' => $b],
            ['type' => 'not_duplicate', 'note' => $data['note'] ?? null, 'created_by' => auth()->id()]
        );
        app(\App\Services\Matching\DuplicateDetectionService::class)->forgetCount();

        return back()->with('success', 'Als „kein Duplikat" markiert – das Paar erscheint jetzt unter „Verwandte Kunden".');
    }

    /**
     * Sammel-Aktion: mehrere ausgewaehlte Paare auf einmal als "kein Duplikat"
     * markieren (schnelles Aufraeumen, z. B. alle Adress-Treffer eines
     * Haushalts). Reihenfolge-unabhaengig, dedupliziert.
     */
    public function dismissBulk(Request $request) {
        $data = $request->validate(['pairs' => 'required|array|min:1|max:500', 'pairs.*' => 'string']);
        [$edges, $ids] = $this->pairsToEdges($data['pairs']);
        if ($ids === []) {
            return back()->with('error', 'Keine gültige Auswahl.');
        }
        foreach ($ids as $id) {
            $this->authorizeCustomerAccess($id);
        }
        $existing = \App\Models\Customer::whereIn('id', $ids)->pluck('id')->map(fn ($i) => (string) $i)->all();

        $marked = 0;
        foreach ($edges as [$a, $b]) {
            if (!in_array($a, $existing, true) || !in_array($b, $existing, true)) {
                continue;
            }
            [$x, $y] = \App\Models\CustomerRelationship::pairKey($a, $b);
            $rel = \App\Models\CustomerRelationship::firstOrCreate(
                ['customer_a_id' => $x, 'customer_b_id' => $y],
                ['type' => 'not_duplicate', 'created_by' => auth()->id()]
            );
            if ($rel->wasRecentlyCreated) {
                $marked++;
            }
        }
        app(\App\Services\Matching\DuplicateDetectionService::class)->forgetCount();

        return redirect()->route('admin.customers.duplicates')
            ->with('success', $marked . ' Paar(e) als „kein Duplikat" markiert – jetzt unter „Verwandte Kunden".');
    }

    /**
     * "Verwandte Kunden": alle als Beziehung markierten Paare (kein Duplikat).
     * Nur Paare, deren BEIDE Kunden im Portfolio des Mitarbeiters liegen.
     */
    public function relationships(\App\Services\Matching\DuplicateDetectionService $detection) {
        $ids = $this->visibleCustomerIds();
        $query = \App\Models\CustomerRelationship::with(['customerA.user', 'customerB.user', 'customerA.contracts:id,customer_id,contract_number', 'customerB.contracts:id,customer_id,contract_number'])
            ->latest();
        if ($ids !== null) {
            $query->whereIn('customer_a_id', $ids)->whereIn('customer_b_id', $ids);
        }
        $relations = $query->limit(500)->get()
            ->filter(fn ($r) => $r->customerA && $r->customerB)
            ->map(function ($r) use ($detection) {
                $r->signals = $detection->pairSignals($r->customerA, $r->customerB);
                return $r;
            })->values();

        return view('admin.customer_relationships', ['relations' => $relations]);
    }

    /** Beziehung entfernen -> Paar kann wieder als moegliche Dublette erscheinen. */
    public function relationshipDelete($id) {
        $rel = \App\Models\CustomerRelationship::findOrFail($id);
        $this->authorizeCustomerAccess($rel->customer_a_id);
        $this->authorizeCustomerAccess($rel->customer_b_id);
        $rel->delete();
        app(\App\Services\Matching\DuplicateDetectionService::class)->forgetCount();

        return back()->with('success', 'Beziehung entfernt – das Paar kann wieder als mögliche Dublette erscheinen.');
    }

    /** Deckel gegen versehentliche Massen-Merges pro manueller Aktion. */
    private const MANUAL_MERGE_CAP = 100;

    /** Deckel je Ein-Klick-Auto-Merge-Lauf (Rest per erneutem Klick). */
    private const AUTO_MERGE_CAP = 200;

    /**
     * Sammel-Zusammenfuehrung der VOM NUTZER AUSGEWAEHLTEN Dubletten-Paare.
     * Ueberlappende Paare (z. B. fuenf Datensaetze derselben Person) werden
     * ueber eine Union-Find-Gruppierung zu EINEM Cluster zusammengefasst und
     * in den jeweils aeltesten Datensatz vereint.
     */
    public function duplicatesMerge(Request $request, \App\Services\Matching\CustomerMergeService $merge) {
        $data = $request->validate([
            'pairs'   => 'required|array|min:1|max:500',
            'pairs.*' => 'string',
        ]);

        [$edges, $ids] = $this->pairsToEdges($data['pairs']);
        if ($ids === []) {
            return back()->with('error', 'Keine gültige Auswahl.');
        }
        foreach ($ids as $id) {
            $this->authorizeCustomerAccess($id);
        }

        $clusters = $this->clusterPairs($edges, $ids);
        $toRemove = array_sum(array_map(fn ($c) => max(0, count($c) - 1), $clusters));
        if ($toRemove > self::MANUAL_MERGE_CAP) {
            return back()->with('error', 'Zu viele auf einmal: höchstens ' . self::MANUAL_MERGE_CAP . ' Zusammenführungen pro Aktion. Bitte Auswahl verkleinern oder „Alle sicheren zusammenführen" nutzen.');
        }

        $res = $this->mergeClusters($clusters, $merge);
        return redirect()->route('admin.customers.duplicates')->with('success', $this->mergeSummary($res, false));
    }

    /**
     * Ein-Klick-Zusammenfuehrung ALLER "sicheren" Treffer (Score >=
     * AUTO_MERGE_MIN_SCORE, Betreiber-Vorgabe 40 %). Schwaechere Treffer
     * (z. B. nur gleicher Name) bleiben bewusst der manuellen Pruefung
     * vorbehalten. Aus Zeitgruenden pro Lauf gedeckelt - der Hinweis fordert
     * bei Bedarf zum erneuten Klick auf, bis alles bereinigt ist.
     */
    public function duplicatesMergeAll(\App\Services\Matching\DuplicateDetectionService $detection, \App\Services\Matching\CustomerMergeService $merge) {
        $min = \App\Services\Matching\DuplicateDetectionService::AUTO_MERGE_MIN_SCORE;

        // Frischer Scan (nie auf veraltete Seiten-Daten verlassen).
        $result = $detection->scan($this->visibleCustomerIds());
        $strong = array_values(array_filter($result['pairs'], fn ($p) => $p['score'] >= $min));

        if ($strong === []) {
            return redirect()->route('admin.customers.duplicates')
                ->with('success', "Keine sicheren Treffer (>= {$min} %) zum automatischen Zusammenführen gefunden.");
        }

        $edges = [];
        $ids = [];
        foreach ($strong as $p) {
            $a = (string) $p['primary']->id;
            $b = (string) $p['duplicate']->id;
            $edges[] = [$a, $b];
            $ids[$a] = true;
            $ids[$b] = true;
        }
        $ids = array_keys($ids);
        foreach ($ids as $id) {
            $this->authorizeCustomerAccess($id);
        }

        // Cluster bilden, dann pro Lauf deckeln (Rest beim naechsten Klick).
        $clusters = $this->clusterPairs($edges, $ids);
        $limited = [];
        $removals = 0;
        foreach ($clusters as $cluster) {
            $need = count($cluster) - 1;
            if ($removals + $need > self::AUTO_MERGE_CAP) {
                continue;
            }
            $limited[] = $cluster;
            $removals += $need;
        }

        $res = $this->mergeClusters($limited, $merge);
        $more = count($limited) < count($clusters);
        $message = "{$res['merged']} sichere Zusammenführung(en) (>= {$min} %) durchgeführt.";
        if ($more) {
            $message .= ' Es waren mehr vorhanden – bitte erneut klicken, um die restlichen zu bereinigen.';
        }
        if ($res['skipped'] > 0) {
            $message .= " {$res['skipped']} übersprungen.";
        }
        return redirect()->route('admin.customers.duplicates')->with('success', $message);
    }

    /**
     * Paar-Strings ("primaryId|dupId") in Kantenliste + eindeutige ID-Liste.
     * @return array{0: array<int, array{0:string,1:string}>, 1: array<int, string>}
     */
    private function pairsToEdges(array $pairs): array {
        $edges = [];
        $ids = [];
        foreach ($pairs as $pair) {
            $parts = explode('|', (string) $pair, 2);
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '' || $parts[0] === $parts[1]) {
                continue;
            }
            $edges[] = $parts;
            $ids[$parts[0]] = true;
            $ids[$parts[1]] = true;
        }
        return [$edges, array_keys($ids)];
    }

    /**
     * Union-Find: verbundene Paare zu Clustern gruppieren (ueberlappende
     * Paare derselben Person werden zu einem Cluster).
     * @return array<int, array<int, string>>
     */
    private function clusterPairs(array $edges, array $ids): array {
        $parent = [];
        foreach ($ids as $id) {
            $parent[$id] = $id;
        }
        $find = function ($x) use (&$parent) {
            $root = $x;
            while ($parent[$root] !== $root) {
                $root = $parent[$root];
            }
            while ($parent[$x] !== $root) {
                [$parent[$x], $x] = [$root, $parent[$x]];
            }
            return $root;
        };
        foreach ($edges as [$a, $b]) {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$ra] = $rb;
            }
        }
        $clusters = [];
        foreach ($ids as $id) {
            $clusters[$find($id)][] = $id;
        }
        return array_values($clusters);
    }

    /**
     * Jeden Cluster in den aeltesten Datensatz vereinen (verlustfrei ueber
     * CustomerMergeService). Bereits geloeschte/fehlende IDs werden
     * uebersprungen statt abzubrechen.
     * @return array{merged: int, skipped: int}
     */
    private function mergeClusters(array $clusters, \App\Services\Matching\CustomerMergeService $merge): array {
        $allIds = array_merge([], ...array_map('array_values', $clusters));
        $customers = \App\Models\Customer::with('user')->whereIn('id', $allIds)->get()->keyBy('id');

        $merged = 0;
        $skipped = 0;
        foreach ($clusters as $members) {
            $present = array_values(array_filter($members, fn ($id) => $customers->has($id)));
            if (count($present) < 2) {
                continue;
            }
            usort($present, fn ($x, $y) => $customers[$x]->created_at <=> $customers[$y]->created_at);
            $primaryId = array_shift($present);
            foreach ($present as $dupId) {
                $primary = \App\Models\Customer::with('user')->find($primaryId);
                $dup = \App\Models\Customer::with('user')->find($dupId);
                if (!$primary || !$dup || (string) $primary->id === (string) $dup->id) {
                    $skipped++;
                    continue;
                }
                try {
                    $merge->merge($primary, $dup, auth()->id());
                    $merged++;
                } catch (\Throwable $e) {
                    $skipped++;
                }
            }
        }
        return ['merged' => $merged, 'skipped' => $skipped];
    }

    private function mergeSummary(array $res, bool $auto): string {
        $message = "{$res['merged']} Zusammenführung(en) durchgeführt.";
        if ($res['skipped'] > 0) {
            $message .= " {$res['skipped']} übersprungen (bereits zusammengeführt oder nicht zulässig).";
        }
        return $message;
    }

    public function mergeForm($id, \App\Services\Matching\CustomerMergeService $merge, \App\Services\Matching\CustomerMatchingService $matcher) {
        $this->authorizeCustomerAccess($id);
        $customer = \App\Models\Customer::with(['user', 'addresses'])->findOrFail($id);
        $others = $this->scopeCustomers(\App\Models\Customer::with('user')->where('id', '!=', $id))->get()
            ->sortBy(fn($c) => $c->user?->name ?? '');

        // Vorauswahl bestimmen: entweder explizit aus der Dubletten-Pruefung
        // (?duplicate=) oder - falls nicht - automatisch der wahrscheinlichste
        // Treffer fuer genau diesen Kunden. So schlaegt das System das Duplikat
        // aktiv vor, statt nur eine leere Auswahlliste zu zeigen.
        $suggested = null;
        $preview = [];
        if ($dupId = request('duplicate')) {
            $suggested = $others->firstWhere('id', $dupId);
        } else {
            $match = $matcher->matchExisting($customer);
            if ($match->hasMatch() && $match->score >= \App\Services\Matching\DuplicateDetectionService::DEFAULT_THRESHOLD) {
                $suggested = $others->firstWhere('id', (string) $match->customer->id);
            }
        }
        if ($suggested) {
            $preview = $merge->preview($suggested);
        }

        return view('admin.customer_merge', compact('customer', 'others', 'suggested', 'preview'));
    }

    public function mergeCustomers(Request $request, $id, \App\Services\Matching\CustomerMergeService $merge) {
        $this->authorizeCustomerAccess($id);
        $request->validate(['duplicate_id' => 'required|different:id']);
        $this->authorizeCustomerAccess($request->duplicate_id);
        $primary = \App\Models\Customer::with('user')->findOrFail($id);
        $dup = \App\Models\Customer::with('user')->findOrFail($request->duplicate_id);
        if ((string) $primary->id === (string) $dup->id) return back()->with('success', 'Gleicher Kunde gewählt.');

        $moved = $merge->merge($primary, $dup, auth()->id());

        $summary = collect($moved)->sum();
        return redirect()->route('admin.customer', $primary->id)
            ->with('success', "Kunden erfolgreich zusammengeführt. {$summary} verknüpfte Datensätze wurden übertragen, nichts wurde gelöscht.");
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
            ->search($q)
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
