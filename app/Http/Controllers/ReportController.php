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
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ScopesCustomerAccess;

    public function index(Request $request) {
        $from = $request->get('from') ? Carbon::parse($request->get('from')) : now()->subDays(30);
        $to = $request->get('to') ? Carbon::parse($request->get('to')) : now();
        $ids = $this->visibleCustomerIds();
        $cf = function ($q) use ($ids) { return $ids === null ? $q : $q->whereIn('customer_id', $ids); };

        // Vertraege des Zeitraums nach BESTANDSGRUPPE (Contract::statusGroup()
        // als Query: currentlyActive/inProgress/historic). Frueher zaehlte hier
        // der rohe Status - ein zum Ablauf gekuendigter Vertrag erschien dann
        // als "aktiv", waehrend die Kundenakte ihn korrekt als beendet fuehrte.
        $contracts = [
            'active' => $cf(Contract::currentlyActive())->whereBetween('created_at', [$from, $to])->count(),
            'pending' => $cf(Contract::inProgress())->whereBetween('created_at', [$from, $to])->count(),
            'historic' => $cf(Contract::historic())->whereBetween('created_at', [$from, $to])->count(),
            // Roh-Status weiterhin einzeln ausgewiesen (Aufteilung der Historie).
            'cancelled' => $cf(Contract::where('status', 'cancelled'))->whereBetween('created_at', [$from, $to])->count(),
            'expired' => $cf(Contract::where('status', 'expired'))->whereBetween('created_at', [$from, $to])->count(),
            'total' => $cf(Contract::whereBetween('created_at', [$from, $to]))->count(),
            'by_type' => $cf(Contract::whereBetween('created_at', [$from, $to]))->selectRaw('type, count(*) as count')->groupBy('type')->pluck('count', 'type'),
            // Sparten-Verteilung des AKTIVEN Bestands (zeitraum-unabhaengig) -
            // beantwortet "welche Sparten laufen aktuell", ohne Historie.
            'active_by_type' => $cf(Contract::currentlyActive())->selectRaw('type, count(*) as count')->groupBy('type')->pluck('count', 'type'),
        ];

        $tickets = [
            'total' => $cf(Ticket::whereBetween('created_at', [$from, $to]))->count(),
            'open' => $cf(Ticket::where('status', 'open'))->whereBetween('created_at', [$from, $to])->count(),
            'closed' => $cf(Ticket::where('status', 'closed'))->whereBetween('created_at', [$from, $to])->count(),
            'in_progress' => $cf(Ticket::where('status', 'in_progress'))->whereBetween('created_at', [$from, $to])->count(),
            'by_type' => $cf(Ticket::whereBetween('created_at', [$from, $to]))->selectRaw('type, count(*) as count')->groupBy('type')->pluck('count', 'type'),
        ];

        $customers_stats = [
            'total' => $ids === null ? Customer::count() : count($ids),
            'new' => Customer::whereBetween('created_at', [$from, $to])->when($ids !== null, fn ($q) => $q->whereIn('customers.id', $ids))->count(),
            'privat' => Customer::where('customer_type', 'privat')->when($ids !== null, fn ($q) => $q->whereIn('customers.id', $ids))->count(),
            'firma' => Customer::where('customer_type', 'firma')->when($ids !== null, fn ($q) => $q->whereIn('customers.id', $ids))->count(),
        ];

        // Bald ablaufend / ueberfaellig: nur AKTIVE Vertraege - ein bereits
        // gekuendigter Vertrag braucht keine Ablauf-Warnung mehr.
        // Bewusst gedeckelt: bei grossem Bestand koennen in 30 Tagen sehr
        // viele Vertraege auslaufen - die Berichtsseite soll dann nicht mit
        // der Liste wachsen. Die Gesamtzahl steht daneben.
        $expiringBasis = $cf(Contract::with('customer.user'))
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', now())
            ->whereDate('end_date', '<=', now()->addDays(30))
            ->currentlyActive();
        $expiringTotal = (clone $expiringBasis)->count();
        $expiring = $expiringBasis->orderBy('end_date')->limit(50)->get();

        $warnings = $cf(Contract::with('customer.user'))
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', now())
            ->currentlyActive()
            ->count();

        return view('admin.reports', compact('contracts', 'tickets', 'customers_stats', 'expiring', 'expiringTotal', 'warnings', 'from', 'to'));
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
        $isManager = in_array(auth()->user()->role, ['admin', 'manager']);
        $provisionRows = collect();
        if ($isManager) {
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
            'insurers', 'from', 'to', 'month', 'isManager'
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
