<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesCustomerAccess;
use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\Provision;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Provision\ContractProvisionService;
use App\Services\Reporting\AnalyticsFilters;
use App\Services\Reporting\BerichtsListe;
use App\Services\Reporting\DashboardAnalyticsService;
use App\Support\Bundesland;
use App\Support\LocalTime;
use App\Support\XlsxWriter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use League\Csv\EscapeFormula;
use League\Csv\Writer;

class ReportController extends Controller
{
    use ScopesCustomerAccess;

    /** Obergrenze der Monatsuebersicht (siehe monat()). */
    private const MONAT_MAX_ZEILEN = 3000;

    /**
     * Auswertungs-Dashboard "Berichte & Analysen" (Betreiber-Auftrag
     * 06.09.2026).
     *
     * DER CONTROLLER RECHNET NICHT MEHR. Vorher standen hier rund 20
     * Einzelabfragen mit je eigener Auslegung von "aktiv", "neu" und
     * "Zeitraum" - genau die Streuung, an der eine Kennzahlenseite
     * unglaubwuerdig wird, sobald zwei Kacheln dasselbe verschieden
     * zaehlen. Die Definitionen stehen jetzt einmal in
     * App\Services\Reporting\DashboardAnalyticsService, die Auswahl
     * einmal in AnalyticsFilters. Hier bleibt: Filter lesen, Dienst
     * fragen, Sicht ausliefern.
     *
     * PORTFOLIO-SCOPE wie ueberall in der Beraterwelt: der Dienst bekommt
     * die sichtbaren Kunden-IDs und schraenkt JEDE Abfrage darauf ein -
     * ein Mitarbeiter sieht in der Auswertung nie mehr als in der
     * Kundenliste.
     */
    public function index(Request $request) {
        $f = AnalyticsFilters::ausRequest($request);
        $ids = $this->visibleCustomerIds();

        $daten = (new DashboardAnalyticsService($ids))->auswerten($f);

        // Ziel je Bundesland fuer den Klick auf die Karte. Bewusst
        // serverseitig gebaut: die Adressen bleiben damit dieselben wie
        // in der Rangliste "Top Regionen" - ein zweiter, im Browser
        // zusammengesetzter Link koennte davon abweichen.
        $karteLinks = collect(Bundesland::kuerzel())
            ->mapWithKeys(fn ($k) => [$k => route('admin.reports', $f->alsQuery(['bundesland' => $k]))])
            ->all();

        // Vorgaenge: bleiben im Bericht, sind aber keine Vertragskennzahl -
        // deshalb eine eigene, kleine Karte statt einer weiteren KPI-Kachel.
        $cf = fn ($q) => $ids === null ? $q : $q->whereIn('customer_id', $ids);
        $ticketStatus = [
            'open' => ['Offen', 'var(--status-info)'],
            'in_progress' => ['In Bearbeitung', 'var(--status-warning)'],
            'resolved' => ['Gelöst', 'var(--emerald)'],
            'closed' => ['Geschlossen', 'var(--ink-soft)'],
        ];
        $ticketZahlen = $cf(Ticket::whereBetween('created_at', [$f->von, $f->bis]))
            ->selectRaw('status, count(*) as anzahl')->groupBy('status')->pluck('anzahl', 'status');
        $ticketGesamt = (int) $ticketZahlen->sum();
        $zeilen = collect($ticketStatus)->map(fn ($cfg, $key) => [
            'label' => $cfg[0],
            'wert' => (int) ($ticketZahlen[$key] ?? 0),
            'anteil' => $ticketGesamt > 0 ? round(((int) ($ticketZahlen[$key] ?? 0)) / $ticketGesamt * 100, 1) : 0.0,
            'farbe' => $cfg[1],
        ])->values();
        // F3 (Befund 25.11.2026): "9 Vorgaenge insgesamt", die Status ergaben
        // 8. Ein Vorgang mit einem Status ausserhalb der vier (oder ohne)
        // fiel still heraus. Jetzt steht der Rest als eigene Zeile da - die
        // Teile ergeben immer das Ganze.
        $rest = $ticketGesamt - (int) $zeilen->sum('wert');
        if ($rest > 0) {
            $zeilen->push([
                'label' => 'Anderer / kein Status',
                'wert' => $rest,
                'anteil' => round($rest / $ticketGesamt * 100, 1),
                'farbe' => 'var(--status-danger)',
            ]);
        }
        $tickets = ['gesamt' => $ticketGesamt, 'zeilen' => $zeilen->all()];

        // Handlungsliste: bald ablaufende Vertraege. Bewusst gedeckelt -
        // bei grossem Bestand soll die Seite nicht mit der Liste wachsen;
        // die Gesamtzahl steht daneben (Lehre "Grosse Listen", 20.08.2026).
        $expiringBasis = $cf(Contract::with('customer.user'))
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', now())
            ->whereDate('end_date', '<=', now()->addDays(30))
            ->currentlyActive()
            // Nur Eigenbestand (28.09.2026): Ablauf eines Fremdvertrags ist
            // Sache seines Betreuers - bei uns hoechstens Uebernahmepotenzial.
            ->ownPortfolio();
        $expiringTotal = (clone $expiringBasis)->count();
        $expiring = $expiringBasis->orderBy('end_date')->limit(50)->get();

        $warnings = $cf(Contract::query())
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', now())
            ->currentlyActive()
            ->ownPortfolio()
            ->count();

        $auswahl = $this->filterAuswahl($ids);

        return view('admin.reports', compact('f', 'daten', 'karteLinks', 'tickets', 'expiring', 'expiringTotal', 'warnings', 'auswahl'));
    }

    /**
     * Auswahllisten der Filterleiste: Gesellschaften aus dem EIGENEN Bestand
     * des sichtbaren Portfolios (keine fremde Gesellschaft als Option, die
     * nur in einem nicht sichtbaren Vertrag steht) und die Werber - die
     * gespeicherte Zuordnung, nach der auch Provisionen gebucht werden.
     *
     * @param  array<int,mixed>|null  $ids
     */
    private function filterAuswahl(?array $ids): array
    {
        $gesellschaften = Contract::query()->ownPortfolio()
            ->when($ids !== null, fn ($q) => $q->whereIn('contracts.customer_id', $ids))
            ->whereNotNull('contracts.insurer')->where('contracts.insurer', '!=', '')
            ->selectRaw('distinct trim(contracts.insurer) as name')
            ->orderBy('name')->limit(300)->pluck('name')->filter()->values()->all();

        $werber = User::whereIn('role', ['admin', 'manager', 'support', 'employee'])->orderBy('name')->get(['id', 'name'])
            ->map(fn ($u) => ['schluessel' => 'u:'.$u->id, 'name' => $u->name])
            ->concat(Partner::orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['schluessel' => 'p:'.$p->id, 'name' => $p->name.' (Partner)']))
            ->values()->all();

        return ['gesellschaften' => $gesellschaften, 'werber' => $werber];
    }

    /**
     * DETAILLISTE hinter einer Zahl der Berichtsseite (Berichte 2.0,
     * 25.11.2026). Dieselbe Filterauswahl wie die Seite, dazu ?liste=...
     * - die Abfrage ist dieselbe, mit der die Kachel zaehlt.
     *
     * Portfolio-Scope wie auf der Uebersicht: ein Mitarbeiter sieht hier nie
     * einen Kunden, den er in der Kundenliste nicht sieht.
     */
    public function liste(Request $request)
    {
        $liste = (string) $request->get('liste');
        abort_unless(array_key_exists($liste, DashboardAnalyticsService::LISTEN), 404);

        $f = AnalyticsFilters::ausRequest($request);
        $dienst = new DashboardAnalyticsService($this->visibleCustomerIds());
        $bericht = new BerichtsListe($dienst, $f, $liste);

        $suche = $request->filled('q') ? mb_substr((string) $request->get('q'), 0, 100) : null;
        $sortierung = (string) $request->get('sort');
        $richtung = $request->get('dir') === 'asc' ? 'asc' : 'desc';

        $format = $request->get('format');
        if (in_array($format, ['csv', 'xlsx'], true)) {
            // Wie der Kunden-Export (/admin/export): nur Verwaltung. Ansehen
            // darf jede Rolle ihr Portfolio - eine Datei mit Kundenlisten
            // verlaesst dagegen das System.
            abort_unless(in_array(auth()->user()->role, ['admin', 'manager'], true), 403);

            return $this->listeExport($bericht, $f, $format, $suche, $sortierung, $richtung);
        }

        $gesamt = $bericht->gesamt();
        $seite = $bericht->seite($suche, $sortierung, $richtung);
        $info = DashboardAnalyticsService::LISTEN[$liste];

        $auswahl = $this->filterAuswahl($this->visibleCustomerIds());
        $darfExport = in_array(auth()->user()->role, ['admin', 'manager'], true);

        return view('admin.reports_liste', compact('f', 'bericht', 'liste', 'info', 'gesamt', 'seite', 'suche', 'sortierung', 'richtung', 'auswahl', 'darfExport'));
    }

    /**
     * MONATSUEBERSICHT (Betreiber-Auftrag 26.11.2026): "wir wollen jeden
     * Monat wissen, wie viele Kunden wir hatten, aus welcher Sparte - und
     * WELCHE Kunden".
     *
     * Die Seite rechnet nicht selbst: Vertraege, Kunden und Neukunden kommen
     * aus DashboardAnalyticsService::liste() - denselben Abfragen, mit denen
     * die Kacheln der Berichtsseite zaehlen und die Detaillisten anzeigen.
     * "Abschluss im November" bedeutet hier also genau dasselbe wie dort
     * (Unterschrift -> Antrag -> Beginn -> Anlage). Eigener Bestand, kein
     * Fremdvertrag; Portfolio-Scope wie ueberall.
     *
     * Gruppiert wird in PHP nach der Sparte. Ein Monat hat Dutzende bis
     * wenige Hundert Abschluesse - zur Sicherheit wird bei MAX_ZEILEN
     * gedeckelt und die Seite sagt das ausdruecklich (eine still gekuerzte
     * Liste liesse den Monat kleiner aussehen, als er war).
     */
    public function monat(Request $request)
    {
        $monatWert = (string) $request->get('monat', '');
        if (! preg_match('/^(\d{4})-(\d{2})$/', $monatWert, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
            $monatWert = now()->format('Y-m');
        }
        $monat = Carbon::createFromFormat('Y-m-d', $monatWert.'-01')->startOfMonth();
        $vormonat = $monat->copy()->subMonthNoOverflow();

        $filterFuer = fn (Carbon $m) => AnalyticsFilters::ausRequest(new Request([
            'zeitraum' => 'monatswahl', 'monat' => $m->format('Y-m'),
        ]));
        $f = $filterFuer($monat);
        $fVor = $filterFuer($vormonat);
        $dienst = new DashboardAnalyticsService($this->visibleCustomerIds());

        $vertraegeGesamt = $dienst->liste('vertraege_neu', $f)->count();
        $vertraege = $dienst->liste('vertraege_neu', $f)
            ->select('contracts.*')
            ->selectRaw(DashboardAnalyticsService::abschlussSql().' as abschluss_datum')
            ->with('customer.user')
            ->orderBy('contracts.type')
            ->limit(self::MONAT_MAX_ZEILEN)
            ->get();
        $gekuerzt = $vertraegeGesamt > $vertraege->count();

        $sparten = [];
        foreach ($vertraege->groupBy('type') as $typ => $gruppe) {
            $cfg = Contract::TYPES[$typ] ?? ['label' => (string) $typ, 'icon' => '📄'];
            $sparten[] = [
                'typ' => (string) $typ,
                'label' => $cfg['label'],
                'icon' => $cfg['icon'],
                'vertraege' => $gruppe->count(),
                'kunden' => $gruppe->pluck('customer_id')->unique()->count(),
                'antraege' => $gruppe->where('stage', Contract::STAGE_ANTRAG)->count(),
                'jahresbeitrag' => round($gruppe->sum(fn (Contract $c) => $c->yearlyPremium()), 2),
                'zeilen' => $gruppe->sortBy(fn (Contract $c) => mb_strtolower($c->customer?->user?->name ?? 'zzz'))->values(),
            ];
        }
        // Groesste Sparte zuerst - die Frage ist "woher kam das Geschaeft".
        usort($sparten, fn ($a, $b) => [$b['vertraege'], $a['label']] <=> [$a['vertraege'], $b['label']]);

        $zahlen = [
            'vertraege' => $vertraegeGesamt,
            'kunden' => $dienst->liste('kunden_abschluss', $f)->count(),
            'neukunden' => $dienst->liste('neukunden', $f)->count(),
            'neukunden_ohne_vertrag' => $dienst->liste('neukunden_ohne_vertrag', $f)->count(),
            'jahresbeitrag' => round($vertraege->sum(fn (Contract $c) => $c->yearlyPremium()), 2),
            'antraege' => $vertraege->where('stage', Contract::STAGE_ANTRAG)->count(),
            'vor_vertraege' => $dienst->liste('vertraege_neu', $fVor)->count(),
            'vor_kunden' => $dienst->liste('kunden_abschluss', $fVor)->count(),
            'vor_neukunden' => $dienst->liste('neukunden', $fVor)->count(),
        ];

        $darfExport = in_array(auth()->user()->role, ['admin', 'manager'], true);
        $istAktuell = $monat->isSameMonth(now());

        return view('admin.reports_monat', compact(
            'monat', 'vormonat', 'f', 'sparten', 'zahlen', 'gekuerzt', 'darfExport', 'istAktuell'
        ));
    }

    /**
     * Export der VOLLSTAENDIGEN Liste (nicht nur der Seite). Excel ueber den
     * eigenen XlsxWriter (Textzellen als inlineStr - nie als Formel), CSV
     * Excel-tauglich: BOM, Semikolon, Formelschutz. Jeder Export steht im
     * ActivityLog (DSGVO: wer hat wann welche Kundenliste gezogen) - mit
     * Liste, Filter und Zeilenzahl, ohne einen einzigen Namen.
     */
    private function listeExport(BerichtsListe $bericht, AnalyticsFilters $f, string $format, ?string $suche, string $sortierung, string $richtung)
    {
        $zeilen = [];
        $bericht->abfrage($suche, $sortierung, $richtung)->chunk(500, function ($modelle) use ($bericht, &$zeilen) {
            foreach ($bericht->zeilen($modelle) as $z) {
                $zeilen[] = $bericht->exportZeile($z);
            }
        });

        $titel = DashboardAnalyticsService::LISTEN[$bericht->liste]['titel'];
        $dateiname = 'dienstly24-'.str_replace('_', '-', $bericht->liste).'-'.$f->von->format('Y-m-d').'-bis-'.$f->bis->format('Y-m-d');

        ActivityLog::record('report_list_export', 'report', null, [
            'liste' => $bericht->liste,
            'format' => $format,
            'zeilen' => count($zeilen),
            'filter' => $f->alsQuery(),
        ]);

        $kopf = [
            [$titel.' · '.$f->zeitraumText()],
            ['Filter: '.(collect($f->chips())->map(fn ($c) => $c['label'].' = '.$c['wert'])->implode(', ') ?: 'keine')
                .' · Erstellt '.LocalTime::for(now())?->format('d.m.Y H:i').' von '.(auth()->user()->name ?? '')],
            [''],
            $bericht->exportKopf(),
        ];

        if ($format === 'xlsx' && XlsxWriter::available()) {
            return response(XlsxWriter::create(mb_substr($titel, 0, 31), array_merge($kopf, $zeilen)), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$dateiname.'.xlsx"',
            ]);
        }

        $csv = Writer::createFromString('');
        $csv->setDelimiter(';');
        $csv->addFormatter(new EscapeFormula);
        $csv->insertAll(array_map(fn ($zeile) => array_map(
            fn ($w) => is_float($w) ? number_format($w, 2, ',', '') : $w, $zeile
        ), array_merge($kopf, $zeilen)));

        return response("\xEF\xBB\xBF".$csv->toString(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$dateiname.'.csv"',
        ]);
    }

    /**
     * Interaktiver Neukunden-Bericht (Betreiber-Vorgabe 25.07.2026):
     * Wer wurde in diesem Monat angelegt, von WEM angelegt, von WEM geworben
     * (Mitarbeiter/Partner), bei welcher Gesellschaft laeuft der Vertrag und
     * von wann bis wann. Jede Zeile fuehrt in die Kundenakte; admin/manager
     * setzen Werber + Mitarbeiter-Sichtbarkeit direkt aus der Liste.
     */
    public function newCustomers(Request $request) {
        // Zeitraum: Standard = aktueller Monat, per ?monat=YYYY-MM blaetterbar,
        // alternativ freier Zeitraum ueber ?from/?to.
        if ($request->filled('from') || $request->filled('to')) {
            $from = Carbon::parse($request->get('from', now()->startOfMonth()))->startOfDay();
            $to = Carbon::parse($request->get('to', now()))->endOfDay();
            $month = null;
        } else {
            try {
                $month = Carbon::createFromFormat('Y-m', $request->get('monat', now()->format('Y-m')))->startOfMonth();
            } catch (\Throwable) {
                $month = now()->startOfMonth();
            }
            $from = $month->copy()->startOfMonth();
            $to = $month->copy()->endOfMonth();
        }

        $ids = $this->visibleCustomerIds();

        $base = Customer::query()
            ->whereBetween('customers.created_at', [$from, $to])
            ->when($ids !== null, fn ($q) => $q->whereIn('customers.id', $ids));

        // ---- Filter ----
        $filtered = (clone $base);
        if ($request->filled('q')) {
            $filtered->search($request->q);
        }
        if ($request->filled('werber')) {
            $w = $request->werber;
            if ($w === 'keiner') {
                $filtered->whereNull('acquired_by')->whereNull('acquired_by_partner_id');
            } elseif (str_starts_with($w, 'u:')) {
                $filtered->where('acquired_by', (int) substr($w, 2));
            } elseif (str_starts_with($w, 'p:')) {
                $filtered->where('acquired_by_partner_id', substr($w, 2));
            }
        }
        if ($request->filled('angelegt_von')) {
            $request->angelegt_von === 'system'
                ? $filtered->whereNull('created_by')
                : $filtered->where('created_by', (int) $request->angelegt_von);
        }
        if ($request->filled('gesellschaft')) {
            $filtered->whereHas('contracts', fn ($q) => $q->where('insurer', $request->gesellschaft));
        }
        if ($request->filled('sparte')) {
            $filtered->whereHas('contracts', fn ($q) => $q->where('type', $request->sparte));
        }
        if ($request->filled('vertrag')) {
            $request->vertrag === 'ohne'
                ? $filtered->whereDoesntHave('contracts')
                : $filtered->whereHas('contracts');
        }

        $customers = $filtered
            ->with(['user', 'creator', 'acquirer', 'acquirerPartner', 'betreuer',
                'contracts' => fn ($q) => $q->orderByRaw('start_date IS NULL, start_date')])
            ->withCount('contracts')
            ->orderByDesc('customers.created_at')
            ->paginate(50)->withQueryString();

        // ---- Kennzahlen + "Wer hat wie viele gebracht" (auf den ganzen
        // Zeitraum bezogen, unabhaengig von den Listen-Filtern) ----
        $all = (clone $base)->with(['acquirer', 'acquirerPartner', 'contracts'])->get();
        $stats = [
            'total' => $all->count(),
            'with_contract' => $all->filter(fn ($c) => $c->contracts->isNotEmpty())->count(),
            'contracts' => $all->sum(fn ($c) => $c->contracts->count()),
            'without_werber' => $all->filter(fn ($c) => ! $c->acquired_by && ! $c->acquired_by_partner_id)->count(),
        ];

        // Leaderboard: Schluessel 'u:{id}' / 'p:{uuid}' / '' (ohne Werber)
        $leaderboard = $all
            ->groupBy(fn ($c) => $c->acquirerKey() ?? '')
            ->map(function ($group, $key) {
                $first = $group->first();
                return [
                    'key' => $key,
                    'label' => $key === '' ? 'Ohne Werber' : ($first->acquirerLabel() ?? 'Unbekannt'),
                    'kind' => $key === '' ? null : (str_starts_with($key, 'u:') ? 'mitarbeiter' : 'partner'),
                    'customers' => $group->count(),
                    'contracts' => $group->sum(fn ($c) => $c->contracts->count()),
                    'yearly_premium' => round($group->sum(fn ($c) => $c->contracts->sum(fn ($v) => $v->yearlyPremium())), 2),
                ];
            })
            ->sortByDesc('customers')->values();

        // ---- Provisions-Vorschau je Werber (nur Verwaltung) ----
        // Werber-Pflege bleibt Sache der Verwaltung ($isManager); die
        // BETRAEGE nur mit dem Recht `provisionen-verwalten`.
        $isManager = in_array(auth()->user()->role, ['admin', 'manager']);
        $darfProvisionen = Gate::allows('provisionen-verwalten');
        $provisionRows = collect();
        if ($darfProvisionen) {
            $userRates = User::whereIn('id', $all->pluck('acquired_by')->filter()->unique())->get()->keyBy('id');
            $partnerRates = Partner::whereIn('id', $all->pluck('acquired_by_partner_id')->filter()->unique())->get()->keyBy('id');
            // Bereits gebuchte Provisionen fuer die NEUKUNDEN dieses Zeitraums.
            // Bewusst ueber die Kunden-IDs statt ueber period_from/period_to:
            // die AUTOMATISCHEN Neuvertrag-Buchungen (Contract::created-Hook)
            // tragen keine Periode. Der fruehere Datumsfilter sah sie daher NIE
            // (period_from/to = NULL) und meldete "0 bereits erfasst" - die
            // Ein-Klick-Erfassung buchte dann on top der Automatik ein zweites
            // Mal (Doppelvverguetung, Audit PROV-1). Jetzt zaehlt jede nicht
            // stornierte Buchung dieser Kunden (Automatik + evtl. manuell).
            $existing = Provision::whereIn('customer_id', $all->pluck('id'))
                ->where('status', '!=', 'storniert')
                ->get();
            $provisionRows = $leaderboard
                ->filter(fn ($row) => $row['key'] !== '')
                ->map(function ($row) use ($userRates, $partnerRates, $existing) {
                    $isUser = str_starts_with($row['key'], 'u:');
                    $id = substr($row['key'], 2);
                    $rate = $isUser ? $userRates->get((int) $id) : $partnerRates->get($id);
                    $fixed = (float) ($rate?->provision_fixed ?? 0);
                    $percent = (float) ($rate?->provision_percent ?? 0);
                    $suggested = round($fixed * $row['contracts'] + $percent / 100 * $row['yearly_premium'], 2);
                    $already = $existing
                        ->filter(fn ($p) => $isUser ? $p->user_id === (int) $id : $p->partner_id === $id)
                        ->sum('amount');
                    return $row + [
                        'fixed' => $fixed, 'percent' => $percent,
                        'suggested' => $suggested, 'already' => round((float) $already, 2),
                        'has_rate' => $rate && ($rate->provision_fixed !== null || $rate->provision_percent !== null),
                    ];
                })->values();
        }

        $employees = User::whereIn('role', ['admin', 'manager', 'support', 'employee'])->orderBy('name')->get();
        $partners = Partner::orderBy('name')->get();
        $insurers = Contract::whereHas('customer', fn ($q) => $q->whereBetween('customers.created_at', [$from, $to]))
            ->whereNotNull('insurer')->where('insurer', '!=', '')
            ->distinct()->orderBy('insurer')->pluck('insurer');

        return view('admin.reports_neukunden', compact(
            'customers', 'stats', 'leaderboard', 'provisionRows', 'employees', 'partners',
            'insurers', 'from', 'to', 'month', 'isManager', 'darfProvisionen'
        ));
    }

    /**
     * Werber eines Kunden setzen (admin/manager): 'u:{id}' = Mitarbeiter,
     * 'p:{uuid}' = Partner, 'keiner' = entfernen. Beide Felder bleiben
     * exklusiv; jede Aenderung wird im Aktivitaetslog festgehalten.
     */
    public function setAcquirer(Request $request, $id) {
        $customer = Customer::findOrFail($id);
        $request->validate(['werber' => 'required|string|max:60']);
        $w = $request->werber;

        $values = ['acquired_by' => null, 'acquired_by_partner_id' => null];
        if (str_starts_with($w, 'u:')) {
            $employee = User::whereIn('role', ['admin', 'manager', 'support', 'employee'])
                ->findOrFail((int) substr($w, 2));
            $values['acquired_by'] = $employee->id;
            $label = $employee->name;
        } elseif (str_starts_with($w, 'p:')) {
            $partner = Partner::findOrFail(substr($w, 2));
            $values['acquired_by_partner_id'] = $partner->id;
            $label = $partner->name;
        } elseif ($w === 'keiner') {
            $label = null;
        } else {
            return back()->with('error', 'Ungueltiger Werber.');
        }

        $customer->update($values);
        ActivityLog::record('customer_acquirer_set', 'customer', $customer->id, [
            'werber' => $label, 'key' => $w === 'keiner' ? null : $w,
        ]);

        // Provisions-Management: Werber nachtraeglich gesetzt (z.B. nach einem
        // Import) -> offene Vertraege des Kunden automatisch verguenten.
        // Bereits gebuchte Vertraege bleiben unveraendert (Idempotenz im Service).
        $booked = 0;
        if ($w !== 'keiner') {
            $booked = app(ContractProvisionService::class)
                ->createForCustomerContracts($customer->fresh());
        }

        return back()->with('success', $label
            ? "Werber gesetzt: {$label}.".($booked > 0 ? " {$booked} Provision(en) automatisch gebucht." : '')
            : 'Werber entfernt.');
    }

    /**
     * Sichtbarkeit eines Kunden fuer Mitarbeiter steuern (admin/manager):
     * synchronisiert die Betreuer-Zuweisung (employee_customers). Nur dort
     * eingetragene Mitarbeiter sehen den Kunden (Kollegen mit "alle Kunden
     * sehen" sowie admin/manager sehen ihn immer).
     */
    public function setVisibility(Request $request, $id) {
        $customer = Customer::findOrFail($id);
        $request->validate(['sichtbar' => 'nullable|array', 'sichtbar.*' => 'integer']);

        $employeeIds = User::whereIn('role', ['admin', 'manager', 'support', 'employee'])
            ->whereIn('id', $request->input('sichtbar', []))
            ->pluck('id')->all();
        $customer->betreuer()->sync($employeeIds);

        $names = User::whereIn('id', $employeeIds)->pluck('name')->implode(', ');
        ActivityLog::record('customer_visibility_set', 'customer', $customer->id, [
            'sichtbar_fuer' => $names !== '' ? $names : null,
        ]);

        return back()->with('success', $names !== ''
            ? "Sichtbar fuer: {$names}."
            : 'Kunde ist keinem Mitarbeiter mehr zugewiesen.');
    }
}
