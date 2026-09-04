<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesCustomerAccess;
use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerChangeRequest;
use App\Models\CustomerFamily;
use App\Models\CustomerMessage;
use App\Models\CustomerNote;
use App\Models\CustomerVehicle;
use App\Models\CustomerView;
use App\Models\InternalMessage;
use App\Models\Partner;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CustomerConversationService;
use App\Services\CustomerDeletionService;
use App\Services\CustomerNumberGenerator;
use App\Services\Family\FamilyRelationService;
use App\Services\Matching\DuplicateDetectionService;
use App\Services\Portal\PortalAccessService;
use App\Support\GermanPhone;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    use ScopesCustomerAccess;





    public function dashboard() {
        $ids = $this->visibleCustomerIds();
        return view('admin.dashboard', [
            'totalCustomers' => $ids === null ? Customer::count() : count($ids),
            // AKTIVE Vertraege = Contract::currentlyActive() (eine Quelle):
            // gekuendigte, abgelaufene und beendete Vertraege zaehlen nie mit,
            // auch wenn der gespeicherte Status noch auf "active" steht.
            'activeContracts' => Contract::currentlyActive()->when($ids !== null, fn ($q) => $q->whereIn('customer_id', $ids))->count(),
            // Gleiche Definition wie der Karten-Link (status=aktiv, nur Kundentickets),
            // damit die Zahl der Liste nach dem Klick entspricht.
            'openTickets' => Ticket::customerOnly()->active()->when($ids !== null, fn ($q) => $q->whereIn('customer_id', $ids))->count(),
            'pendingApprovals' => CustomerChangeRequest::where('status', 'pending')->when($ids !== null, fn ($q) => $q->whereIn('customer_id', $ids))->count(),
            'recentTickets' => Ticket::with('customer.user')->when($ids !== null, fn ($q) => $q->whereIn('customer_id', $ids))->latest()->take(5)->get(),
            'recentApprovals' => CustomerChangeRequest::with('customer.user')->where('status', 'pending')->when($ids !== null, fn ($q) => $q->whereIn('customer_id', $ids))->latest()->take(5)->get(),
            // Zuletzt GEOEFFNETE Kunden: pro Mitarbeiter aus customer_views,
            // nach eigenem Aufruf-Zeitstempel sortiert (nicht mehr nach Anlage-
            // datum). Zusaetzlich aufs Portfolio gescopt, damit ein alter View
            // auf einen inzwischen entzogenen Kunden nicht mehr auftaucht.
            'recentCustomers' => Customer::query()
                ->select('customers.*')
                ->join('customer_views', 'customer_views.customer_id', '=', 'customers.id')
                ->where('customer_views.user_id', auth()->id())
                ->when($ids !== null, fn ($q) => $q->whereIn('customers.id', $ids))
                // Zaehler auf der Kundenkarte: AKTIVE Vertraege (gleiche
                // Definition wie ueberall), nicht alle je erfassten.
                ->with('user')
                ->withCount(['contracts as active_contracts_count' => fn ($q) => $q->currentlyActive()])
                ->orderByDesc('customer_views.viewed_at')
                ->take(8)->get(),
        ]);
    }

    public function customers(Request $request, DuplicateDetectionService $detection) {
        $employees = User::whereIn('role', ['employee', 'manager', 'support'])->orderBy('name')->get();
        // Hinweis-Badge: Anzahl offener Dubletten-Verdachtsfaelle (kurz gecacht).
        $dupCount = $detection->countCached($this->visibleCustomerIds());
        // Aktive Verträge mitladen (nur benötigte Spalten) für die Vertrags-Icons
        // in der Liste – ohne N+1-Abfragen pro Zeile. currentlyActive() liest
        // dabei cancellation_date/end_date/type mit, weil die Regel diese
        // Spalten braucht (SELECT ohne sie wuerde die Bedingung leer laufen).
        $query = $this->scopeCustomers(Customer::with([
            'user',
            'betreuer',
            'contracts' => fn ($q) => $q->currentlyActive()
                ->select('id', 'customer_id', 'type', 'status', 'start_date', 'end_date', 'cancellation_date'),
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
            'total' => $this->scopeCustomers(Customer::query())->count(),
            'strom' => $this->countBySparte('strom'),
            'gas' => $this->countBySparte('gas'),
            'kfz' => $this->countBySparte('kfz'),
            'ohne_email' => $this->scopeCustomers(Customer::query())
                ->whereDoesntHave('user', fn ($u) => $this->scopeRealEmail($u))->count(),
            'ablauf' => $this->scopeCustomers(Customer::query())
                ->whereHas('contracts', fn ($q) => $q->currentlyActive()
                    ->whereNotNull('end_date')
                    ->whereBetween('end_date', [today(), today()->addDays(60)]))->count(),
            'kontakt' => $this->scopeCustomers(Customer::query())
                ->where(fn ($q) => $q->whereNull('last_contact')
                    ->orWhere('last_contact', '<', today()->subDays(180)))->count(),
        ];

        $sparten = Contract::TYPES;

        return view('admin.customers', compact('customers', 'employees', 'dupCount', 'counts', 'sparten'));
    }

    /** Zaehlt Kunden mit mind. einem AKTIVEN Vertrag der Sparte (portfolio-gescoped). */
    private function countBySparte(string $type): int {
        return $this->scopeCustomers(Customer::query())
            ->whereHas('contracts', fn ($q) => $q->currentlyActive()->where('type', $type))
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
            // "ohne" = noch keinem Mitarbeiter zugewiesen (offene Kunden finden
            // und direkt in der Liste zuweisen).
            if ($request->betreuer === 'ohne') {
                $query->whereDoesntHave('betreuer');
            } else {
                $query->whereHas('betreuer', fn ($q) => $q->where('users.id', $request->betreuer));
            }
        }
        // E-Mail vorhanden / fehlt (echte Adresse, kein Import-Platzhalter).
        if ($request->email === 'mit') {
            $query->whereHas('user', fn ($u) => $this->scopeRealEmail($u));
        } elseif ($request->email === 'ohne') {
            $query->whereDoesntHave('user', fn ($u) => $this->scopeRealEmail($u));
        }
        // Sparte: mind. ein aktiver Vertrag dieses Typs (gleiche Definition wie
        // die Sparten-Kennzahl und die Vertrags-Icons der Liste).
        if ($request->filled('sparte')) {
            $query->whereHas('contracts', fn ($q) => $q->currentlyActive()->where('type', $request->sparte));
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
                            $w->orWhere('name', 'like', $l.'%');
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
            $query->whereHas('contracts', fn ($q) => $q->currentlyActive()
                ->whereNotNull('end_date')
                ->whereBetween('end_date', [today(), today()->addDays($days)]));
        }
        // Lange nicht kontaktiert (nie oder laenger als X Tage her).
        if ($request->filled('kontakt')) {
            if ($request->kontakt === 'nie') {
                $query->whereNull('last_contact');
            } else {
                $days = max(1, (int) $request->kontakt);
                $query->where(fn ($q) => $q->whereNull('last_contact')
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
            $query->whereDoesntHave('user', fn ($u) => $this->scopeRealEmail($u));
            return;
        }
        // "Nicht deaktiviert" = is_active true oder (Alt-Datensatz) NULL.
        $notDeactivated = fn ($u) => $u->where(fn ($w) => $w->whereNull('is_active')->orWhere('is_active', true));
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
            CustomerView::updateOrCreate(
                ['user_id' => auth()->id(), 'customer_id' => $id],
                ['viewed_at' => now()]
            );
        }
        $customer = Customer::with(['user', 'contracts.vehicleDetail.claims', 'contracts.vehicleDetail.mileageReadings', 'contracts.energyDetail.meterReadings', 'contracts.internetDetail', 'contracts.switchReminders', 'tickets', 'documents', 'family', 'changeRequests.reviewer'])->findOrFail($id);
        // Interner Chat & Notizen (nur Staff - Zugriff bereits oben geprüft)
        $internalChat = InternalMessage::chat()->where('customer_id', $id)->with('sender')->orderBy('created_at')->orderBy('id')->get();
        $internalNotes = InternalMessage::note()->where('customer_id', $id)->with('sender')->latest()->get();
        // Direktnachrichten (Portal-Chat): Kundenantworten gelten mit dem
        // Oeffnen der Akte als vom Team gelesen.
        $customerMessages = CustomerMessage::where('customer_id', $id)
            ->with(['sender', 'attachments'])->orderBy('created_at')->orderBy('id')->get();
        CustomerMessage::where('customer_id', $id)->fromCustomer()->unread()->update(['read_at' => now()]);
        // "Verwandte Kunden": andere Akten mit gemeinsamen Merkmalen (Telefon,
        // Anschrift, E-Mail, IBAN ...) - Beziehungshinweis in der Kundenakte.
        $relations = app(DuplicateDetectionService::class)
            ->relationsFor($customer, $this->visibleCustomerIds(), 12);
        // Omnichannel: komplette Kommunikation (alle Kanaele) als Timeline
        // im Tab "Kommunikation" (gleiches Partial wie die Kundenkommunikation).
        $conversationTimeline = (new CustomerConversationService)->timeline(
            $customer,
            includeEmails: in_array(auth()->user()->role, ['admin', 'manager', 'support'], true),
        );
        // Familienstruktur: verknuepfte KUNDENAKTEN (Ehepartner, Kinder,
        // Eltern) mit Rolle, Alter und Abhaengigkeit. Bewusst getrennt von
        // $customer->family - dort stehen Personen OHNE eigene Akte.
        $familie = app(FamilyRelationService::class)->overview($customer);

        return view('admin.customer_show', compact('customer', 'internalChat', 'internalNotes', 'customerMessages', 'relations', 'conversationTimeline', 'familie'));
    }



    /**
     * Sofort-Suche der Kundenauswahl (JSON) - genutzt vom Vertragsformular
     * und vom Zusammenfuehren-Formular.
     *
     * Portfolio-Scoping wie ueberall; die Suche selbst kommt aus
     * Customer::scopeSearch (Name, Kundennummer, Telefon, E-Mail, Anschrift)
     * und ist damit deutlich treffsicherer als der frueher rein auf den
     * Namen begrenzte Browser-Filter.
     *
     * `exclude` blendet einen Kunden aus - beim Zusammenfuehren darf der
     * Hauptkunde nicht als sein eigenes Duplikat waehlbar sein.
     */
    public function customerSearch(Request $request) {
        $q = trim((string) $request->query('q', ''));
        $exclude = (string) $request->query('exclude', '');

        $basis = $this->scopeCustomers(Customer::with('user'))
            ->when($exclude !== '', fn ($query) => $query->where('customers.id', '!=', $exclude));
        $customers = $q === ''
            ? $basis->latest()->take(8)->get()
            : $basis->search($q)->take(8)->get();

        return response()->json([
            'customers' => $customers->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->user?->name ?? '—',
                'email' => $c->user?->hasRealEmail() ? $c->user->email : null,
                'number' => $c->customer_number,
            ])->values(),
        ]);
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
                if ($value && GermanPhone::isLandline($value)) {
                    $fail('Das sieht nach einer Festnetznummer aus – bitte ins Feld „Telefon" eintragen.');
                }
            }],
            'phone' => ['nullable', 'string', 'max:40', function ($attr, $value, $fail) {
                if ($value && GermanPhone::isMobile($value)) {
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
            // Passwort ist optional: ohne Eingabe greift der Startpasswort-
            // Flow (Geburtsdatum TT.MM.JJJJ bzw. Einladungs-Link). Regel aus
            // der EINEN Quelle - vorher galt hier 'min:8', waehrend Portal
            // und Reset laengst mehr verlangten.
            'password' => ['nullable', PasswordPolicy::customer()],
            // Bankverbindung darf schon bei der Neuanlage erfasst werden.
            'iban' => 'nullable|string|max:40',
            'account_holder' => 'nullable|string|max:120',
            'bic' => 'nullable|string|max:20',
        ] + $this->phoneFieldRules());
        $fullName = $request->first_name.' '.$request->last_name;
        $address = $this->buildAddress($request);
        $addressColumns = $this->addressColumns($request);

        // Werber (Neukunden-Bericht/Provision): 'u:{id}' = Mitarbeiter,
        // 'p:{uuid}' = Partner. Nur Verwaltung darf ihn bei der Anlage setzen.
        $werber = in_array(auth()->user()->role, ['admin', 'manager'])
            ? Customer::resolveWerberKey($request->werber)
            : ['acquired_by' => null, 'acquired_by_partner_id' => null];

        $user = User::create([
            'name' => $fullName,
            'email' => $request->email ?: null,
            // Platzhalter - das nutzbare Passwort setzt gleich der
            // PortalAccessService (manuell/Geburtsdatum/Set-Link).
            'password' => bcrypt(Str::random(40)),
            'role' => 'customer',
        ]);
        $customer = Customer::create([
            'user_id' => $user->id,
            'customer_number' => app(CustomerNumberGenerator::class)->generate(),
            // Herkunft + Werber fuer den Neukunden-Bericht (created_by setzt
            // das Customer-Modell automatisch auf den angemeldeten Mitarbeiter).
            'source' => 'manual',
            'acquired_by' => $werber['acquired_by'],
            'acquired_by_partner_id' => $werber['acquired_by_partner_id'],
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
            'gender' => in_array($request->gender, ['male', 'female', 'diverse'], true) ? $request->gender : null,
            'marital_status' => $request->marital_status ?: null,
            // Bankverbindung (verschluesselt at rest) direkt bei der Anlage.
            'iban' => $request->iban ?: null,
            'account_holder' => $request->account_holder ?: null,
            'bic' => $request->bic ?: null,
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
                    // Von der Verwaltung vergebenes Passwort: gilt als
                    // system-vergeben -> beim ersten Login Pflichtwechsel.
                    // Es wird BEWUSST NICHT per E-Mail verschickt (Betreiber-
                    // Vorgabe 18.08.2026): ein Klartext-Passwort in einem
                    // Postfach bleibt dort fuer immer, samt Backups. Der
                    // Mitarbeiter nennt es dem Kunden persoenlich.
                    $user->forceFill([
                        'password' => bcrypt($request->password),
                        'portal_password_set_at' => now(),
                        'must_change_password' => true,
                    ])->save();
                    session()->flash('warning', 'Passwort gesetzt. Aus Sicherheitsgruenden wird es NICHT per E-Mail verschickt - '
                        .'bitte teilen Sie es dem Kunden persoenlich mit. Beim ersten Login muss er ein eigenes Passwort festlegen. '
                        .'Alternativ koennen Sie in der Kundenakte eine Einladung senden.');
                } else {
                    $mode = app(PortalAccessService::class)->sendInvitation($customer, auth()->id());
                    if ($mode === 'setlink') {
                        // Ohne Geburtsdatum gibt es KEIN Startpasswort - nur einen
                        // zeitlich begrenzten Link. Ohne Hinweis wirkt die Einladung
                        // erfolgreich, der Kunde kann aber oft nie aktivieren
                        // (Betreiber-Meldung 07.08.2026).
                        session()->flash('warning', 'Kein Geburtsdatum hinterlegt: Die Einladung enthaelt statt des Startpassworts nur einen zeitlich begrenzten Passwort-Link. Bitte Geburtsdatum ergaenzen und die Einladung erneut senden.');
                    }
                }
            } catch (\Throwable $e) {
                \Log::warning('Welcome mail failed: '.$e->getMessage());
                // Seit dem synchronen Versand landen Fehler HIER statt
                // still in failed_jobs - dem Mitarbeiter anzeigen, sonst
                // wartet der Kunde vergeblich auf seine Zugangsdaten.
                session()->flash('error', 'Die Willkommens-Mail konnte NICHT versendet werden. Bitte in der Kundenakte "Einladung erneut senden" nutzen.');
            }
        }
        return redirect()->route('admin.customer', $customer->id)->with('success', 'Kunde erfolgreich erstellt.');
    }

    public function customerEdit($id) {
        $this->authorizeCustomerAccess($id);
        $customer = Customer::with('user')->findOrFail($id);
        $addr = $this->splitAddress($customer->address);
        $partners = Partner::active()->orderBy('name')->get(['id', 'name']);
        return view('admin.customer_edit', compact('customer', 'addr', 'partners'));
    }

    public function customerUpdate(Request $request, $id) {
        $this->authorizeCustomerAccess($id);
        $customer = Customer::findOrFail($id);
        $user = $customer->user;

        $request->validate([
            'first_name' => 'required',
            'last_name' => 'required',
            'email' => 'nullable|email|unique:users,email,'.$user->id,
            'portal_email' => 'nullable|email|unique:users,email,'.$user->id,
            'new_password' => ['nullable', PasswordPolicy::customer()],
            'health_insurance_type' => 'nullable|in:gesetzlich,privat',
            'gender' => 'nullable|in:male,female,diverse',
            'bic' => 'nullable|string|max:20',
            // Pflichtfelder im Bearbeiten-Formular (HTML "required" erzwingt die
            // Eingabe im Browser). Serverseitig "sometimes|required": ist das
            // Feld Teil des Submits, darf es nicht leer sein - Teil-Updates ohne
            // diese Schluessel (z. B. reine Partner-/E-Mail-Zuordnung) bleiben moeglich.
            'birth_place' => 'sometimes|required|string|max:255',
            'nationality' => 'sometimes|required|string|max:100',
        ] + $this->phoneFieldRules());

        // Sensible Kundenakte-Felder: Änderungen auditieren (nur Feldnamen ins Log)
        $sensitive = ['health_insurance_number', 'health_insurance_company', 'health_insurance_type', 'pension_insurance_number', 'tax_id'];
        $changedSensitive = [];
        foreach ($sensitive as $sf) {
            if ($request->has($sf) && (string) $request->input($sf) !== (string) $customer->$sf) {
                $changedSensitive[] = $sf;
            }
        }
        if ($changedSensitive) {
            ActivityLog::create([
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
            'bic' => $request->bic ?: null,
            'birth_date' => $request->birth_date ?: null,
            'marital_status' => $request->marital_status,
            'gender' => in_array($request->gender, ['male', 'female', 'diverse'], true) ? $request->gender : null,
            'preferred_lang' => $request->preferred_lang,
            'nationality' => $request->nationality,
            'birth_place' => $request->birth_place,
            'occupation' => $request->occupation,
            'employer_name' => $request->employer_name,
            'employer_address' => $request->employer_address,
            'customer_type' => $request->customer_type,
            'company_name' => $request->company_name,
            'company_type' => $request->company_type,
            'health_insurance_type' => in_array($request->health_insurance_type, ['gesetzlich', 'privat'], true) ? $request->health_insurance_type : null,
            'health_insurance_company' => $request->health_insurance_company ?: null,
            'health_insurance_number' => $request->health_insurance_number ?: null,
            'pension_insurance_number' => $request->pension_insurance_number ?: null,
            'tax_id' => $request->tax_id ?: null,
            // Zuordnung zu einem Vertriebspartner (dessen Portal ihn dann sieht) -
            // steuert eine Datensicht (Partner-Portal), daher darf sie NUR die
            // Verwaltung setzen (analog zum Werber/acquired_by). Fuer uebrige
            // Rollen bleibt der Bestand unveraendert - kein stiller Datenabfluss.
            'partner_id' => in_array(auth()->user()->role, ['admin', 'manager'], true)
                ? ($request->partner_id ?: null)
                : $customer->partner_id,
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
        $userData = ['name' => trim(($request->first_name ?? '').' '.($request->last_name ?? '')) ?: ($request->name ?? $user->name)];
        // E-Mail: eingetragene echte Adresse uebernehmen; ist das Feld leer,
        // bleibt/wird die E-Mail LEER (NULL) - kein Dummy. So laesst sich auch
        // eine alte Platzhalter-Adresse durch Leeren sauber entfernen.
        $newEmail = $request->filled('portal_email') ? $request->portal_email : $request->email;
        $userData['email'] = ($newEmail !== null && $newEmail !== '') ? $newEmail : null;
        if ($request->filled('new_password')) {
            $userData['password'] = bcrypt($request->new_password);
        }
        // Zustand VOR dem Speichern merken: hatte der Kunde bisher eine echte
        // (nutzbare) E-Mail? Nur so laesst sich "E-Mail neu nachgetragen" erkennen.
        $hadRealEmail = $user->hasRealEmail();
        $user->update($userData);

        // Portal-Status-Spalten stehen bewusst NICHT in User::$fillable (sie
        // gehoeren dem System, nicht dem Formular) - update() wuerde sie still
        // verwerfen. Deshalb forceFill, wie an allen anderen Stellen auch
        // (gleiche Falle wie seinerzeit bei is_active).
        if ($request->filled('new_password')) {
            $user->forceFill([
                'portal_password_set_at' => now(),
                // Von der Verwaltung vergeben = system-vergeben: beim ersten
                // Login ist ein eigenes Passwort faellig. Hier gesetzt und
                // nicht im Einladungs-Block weiter unten, weil der nur bei
                // NEU hinzugekommener E-Mail-Adresse ueberhaupt laeuft.
                'must_change_password' => true,
            ])->save();
        }

        // Automatische Portal-Einladung, sobald eine echte E-Mail NEU nachgetragen
        // wird (analog zur Neuanlage in storeCustomer). So muss der Mitarbeiter die
        // Einladung nicht mehr separat ausloesen – sie geht direkt an den Kunden.
        $invited = false;
        if (! $hadRealEmail && $user->hasRealEmail()) {
            try {
                $customer->setRelation('user', $user);
                if ($request->filled('new_password')) {
                    // Passwort wurde in diesem Schritt gesetzt: gilt als
                    // system-vergeben (Pflichtwechsel beim ersten Login) und
                    // wird NICHT per E-Mail verschickt - siehe storeCustomer.
                    session()->flash('warning', 'Passwort gesetzt. Aus Sicherheitsgruenden wird es NICHT per E-Mail verschickt - '
                        .'bitte teilen Sie es dem Kunden persoenlich mit. Beim ersten Login muss er ein eigenes Passwort festlegen.');
                } elseif ($user->invitation_sent_at === null
                    && $user->portal_password_set_at === null
                    && $user->first_login_at === null) {
                    // Noch kein Portal-Zugang angestossen -> Standard-Einladung
                    // (Startpasswort = Geburtsdatum bzw. Passwort-Setzen-Link).
                    $mode = app(PortalAccessService::class)->sendInvitation($customer, auth()->id());
                    if ($mode === 'setlink') {
                        session()->flash('warning', 'Kein Geburtsdatum hinterlegt: Die Einladung enthaelt statt des Startpassworts nur einen zeitlich begrenzten Passwort-Link. Bitte Geburtsdatum ergaenzen und die Einladung erneut senden.');
                    }
                    $invited = true;
                }
            } catch (\Throwable $e) {
                \Log::warning('Auto-Einladung nach E-Mail-Nachtrag fehlgeschlagen: '.$e->getMessage());
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
                if (! trim((string) $fname)) continue;
                $fgender = $request->family_geschlecht[$i] ?? null;
                CustomerFamily::create([
                    'customer_id' => $customer->id,
                    'name' => trim($fname),
                    'relation' => $request->family_relation[$i] ?? 'Kind',
                    'birth_date' => ($request->family_birth[$i] ?? null) ?: null,
                    // Geschlecht (male|female) - wurde bislang aus dem Formular
                    // nicht uebernommen und ging verloren.
                    'gender' => in_array($fgender, array_keys(CustomerFamily::GENDERS), true) ? $fgender : null,
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
            ? 'Kundendaten aktualisiert. Einladung zum Portal wurde an '.$user->email.' gesendet.'
            : 'Kundendaten aktualisiert.';
        return redirect()->route('admin.customer', $id)->with('success', $msg);
    }

    private function buildAddress(Request $request): ?string {
        $line1 = trim(($request->street ?? '').' '.($request->street_nr ?? ''));
        $line2 = trim(($request->plz ?? '').' '.($request->city ?? ''));
        $line3 = trim($request->country ?? '');
        $parts = array_filter([$line1, $line2, $line3], fn ($p) => $p !== '');
        return $parts ? implode(', ', $parts) : null;
    }

    /**
     * Strukturierte Adressspalten aus den Formularfeldern, exakt so, wie sie
     * das Kundenportal (Profilseite) liest. So sind admin-seitig erfasste
     * Adressen im Portal sofort sichtbar und nicht leer.
     */
    private function addressColumns(Request $request): array {
        return [
            'address_street' => $request->street ?: null,
            'address_house_number' => $request->street_nr ?: null,
            'address_zip' => $request->plz ?: null,
            'address_city' => $request->city ?: null,
        ];
    }

    private function splitAddress(?string $address): array {
        $parts = ['street' => '', 'street_nr' => '', 'plz' => '', 'city' => '', 'country' => ''];
        if (! $address) return $parts;

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
        CustomerNote::create([
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
        $note = CustomerNote::findOrFail($id);
        $this->authorizeCustomerAccess($note->customer_id);
        $note->update(['is_done' => ! $note->is_done]);
        return back()->with('success', 'Status aktualisiert.');
    }


    public function storeFamily(Request $request, $id) {
        $this->authorizeCustomerAccess($id);
        $request->validate(['name' => 'required']);
        $request->validate([
            'health_insurance_status' => 'nullable|in:mitglied,familienversichert',
            'health_insurance_start' => 'nullable|date',
        ]);
        CustomerFamily::create([
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
        CustomerVehicle::create([
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
        $f = CustomerFamily::findOrFail($id);
        $this->authorizeCustomerAccess($f->customer_id);
        $customerId = $f->customer_id;
        $f->delete();
        return redirect()->route('admin.customer.edit', $customerId)->with('success', 'Familienmitglied entfernt.');
    }







    /**
    public function bulkAssign(Request $request) {
        $request->validate([
            'customer_ids' => 'required|array|min:1',
            'employee_id' => 'required|exists:users,id',
            'reason' => 'required|string|max:500',
        ]);
        $employee = User::whereIn('role', ['employee', 'manager', 'support'])->findOrFail($request->employee_id);
        $count = 0;
        DB::transaction(function () use ($request, $employee, &$count) {
            foreach ($request->customer_ids as $cid) {
                $customer = Customer::find($cid);
                if (! $customer) continue;
                $previous = $customer->betreuer()->pluck('users.name')->implode(', ');
                if ($request->boolean('replace_existing')) {
                    $customer->betreuer()->sync([$employee->id]);
                } else {
                    $customer->betreuer()->syncWithoutDetaching([$employee->id]);
                }
                ActivityLog::create([
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
        return back()->with('success', $count.' Kunden wurden '.$employee->name.' zugewiesen.');
    }

    /**
     * Betreuer eines einzelnen Kunden direkt aus der Kundenliste setzen
     * (Popover in der Betreuer-Spalte). Die Auswahl ersetzt die bisherige
     * Zuweisung vollstaendig (Mehrfachauswahl moeglich, leere Auswahl = kein
     * Betreuer). Bereits zugewiesene Nutzer, die im Popover gar nicht zur
     * Auswahl stehen (z. B. ein Admin ueber die Sichtbarkeit im
     * Neukunden-Bericht), bleiben erhalten - es geht nichts still verloren.
     */
    public function setBetreuer(Request $request, $id) {
        $this->authorizeCustomerAccess($id);
        $request->validate([
            'betreuer' => 'nullable|array',
            'betreuer.*' => 'integer',
        ]);
        $customer = Customer::with(['user', 'betreuer'])->findOrFail($id);

        // Auswahlbare Mitarbeiter = exakt die Liste im Popover.
        $selectable = User::whereIn('role', ['employee', 'manager', 'support'])
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
        $chosen = User::whereIn('id', $request->input('betreuer', []))
            ->whereIn('id', $selectable)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $keep = $customer->betreuer->pluck('id')->map(fn ($i) => (int) $i)
            ->reject(fn ($i) => in_array($i, $selectable, true))->all();

        $previous = $customer->betreuer->pluck('name')->implode(', ');
        $customer->betreuer()->sync(array_values(array_unique(array_merge($chosen, $keep))));

        $names = User::whereIn('id', $chosen)->orderBy('name')->pluck('name')->implode(', ');
        ActivityLog::record('customer_reassigned', 'customer', $customer->id, [
            'customer' => $customer->user?->name,
            'from' => $previous !== '' ? $previous : 'niemand',
            'to' => $names !== '' ? $names : 'niemand',
            'mode' => 'ersetzt',
            'quelle' => 'Kundenliste',
        ]);

        return back()->with('success', $names !== ''
            ? 'Betreuer gesetzt: '.$names.'.'
            : 'Betreuer entfernt - der Kunde ist jetzt offen.');
    }

    public function destroyCustomer($id) {
        $this->authorizeCustomerAccess($id);
        $customer = Customer::findOrFail($id);
        app(CustomerDeletionService::class)->delete($customer, auth()->id());
        return redirect()->route('admin.customers')->with('success', 'Kunde gelöscht.');
    }

    /**
     * Mehrere Kunden auf einmal löschen (nur admin, Routen-Middleware).
     * Nutzt exakt dieselbe DSGVO-Löschlogik wie die Einzellöschung.
     */
    public function bulkDestroyCustomers(Request $request) {
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

        $service = app(CustomerDeletionService::class);
        $deleted = 0;
        foreach (Customer::with('user')->whereIn('id', $data['customer_ids'])->get() as $customer) {
            $service->delete($customer, auth()->id());
            $deleted++;
        }

        return redirect()->route('admin.customers')
            ->with('success', $deleted.' Kunde(n) endgültig gelöscht.');
    }

    public function customerTimeline($id) {
        $this->authorizeCustomerAccess($id);
        $customer = Customer::with(['user', 'timeline.user'])->findOrFail($id);
        return view('admin.customer_timeline', compact('customer'));
    }

    public function globalSearch(Request $request) {
        $q = $request->get('q', '');
        if (strlen($q) < 2) return response()->json([]);
        $vids = $this->visibleCustomerIds();
        $customers = Customer::with('user')
            ->when($vids !== null, fn ($qq) => $qq->whereIn('customers.id', $vids))
            ->search($q)
            ->limit(5)->get()->map(fn ($c) => [
                'type' => 'customer',
                'icon' => '👤',
                'title' => $c->user?->name,
                'sub' => $c->customer_number,
                'url' => route('admin.customer', $c->id),
            ]);
        $contracts = Contract::with('customer.user')
            ->when($vids !== null, fn ($qq) => $qq->whereIn('customer_id', $vids))
            ->where(function ($query) use ($q) {
                $query->where('contract_number', 'like', "%$q%")
                    ->orWhere('reference_number', 'like', "%$q%")
                    ->orWhere('internal_contract_number', 'like', "%$q%")
                      // Vermittler-ID aus der Abrechnung: oft die einzige
                      // Nummer, die bei einer Rueckfrage vorliegt.
                    ->orWhere('vermittler_id', 'like', "%$q%")
                    ->orWhere('insurer', 'like', "%$q%");
            })
            ->limit(3)->get()->map(fn ($c) => [
                'type' => 'contract',
                'icon' => '📄',
                'title' => $c->insurer,
                'sub' => $c->contract_number,
                'url' => route('admin.customer', $c->customer_id),
            ]);
        $tickets = Ticket::with('customer.user')
            ->when($vids !== null, fn ($qq) => $qq->whereIn('customer_id', $vids))
            ->where(function ($query) use ($q) {
                $query->where('subject', 'like', "%$q%")
                    ->orWhere('ticket_number', 'like', "%$q%");
            })
            ->limit(3)->get()->map(fn ($t) => [
                'type' => 'ticket',
                'icon' => '💬',
                'title' => $t->subject,
                'sub' => trim(($t->ticket_number ? $t->ticket_number.' · ' : '').($t->customer?->user?->name ?? '')),
                'url' => route('admin.ticket', $t->id),
            ]);
        return response()->json(array_merge(
            $customers->toArray(),
            $contracts->toArray(),
            $tickets->toArray()
        ));
    }

}
