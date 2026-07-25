<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\Provision;
use App\Models\ProvisionAuditLog;
use App\Models\ProvisionRate;
use App\Models\User;
use App\Support\XlsxWriter;
use Illuminate\Http\Request;
use League\Csv\EscapeFormula;
use League\Csv\Writer;

/**
 * Vermittler-Provisionen (AUSGANG): Verguetungen an Mitarbeiter und
 * Vertriebspartner. Provisions-Management (25.07.2026): Buchungen entstehen
 * AUTOMATISCH bei Vertragsanlage (ContractProvisionService) und durchlaufen
 * offen -> freigegeben -> ausgezahlt (HITL). Dazu: Sparten-Saetze je
 * Empfaenger, Monatsbericht mit Excel-/PDF-Export, Performance-Dashboard,
 * Betrags-Anpassungen nur mit Grund + lueckenlosem Audit-Log.
 *
 * Zugriff NUR admin/manager (Routen-Middleware role:admin,manager) -
 * Mitarbeiter und Partner sehen weder Betraege noch Berichte (interner
 * Verwaltungsprozess, keine Benachrichtigungen an Empfaenger).
 */
class ProvisionController extends Controller
{
    public function index(Request $request) {
        $query = Provision::with(['user', 'partner', 'customer.user', 'contract', 'creator', 'approver', 'payer'])
            ->orderByDesc('created_at');

        $this->applyFilters($query, $request);
        if ($request->filled('status') && isset(Provision::STATUSES[$request->status])) {
            $query->where('status', $request->status);
        }

        $provisions = $query->paginate(50)->withQueryString();

        // Kennzahlen folgen den aktiven Filtern (ohne Status-Filter), damit
        // Zeitraum-/Empfaenger-Auswertungen stimmen.
        $totalsBase = Provision::query();
        $this->applyFilters($totalsBase, $request);
        $totals = [
            'offen' => (float) (clone $totalsBase)->where('status', 'offen')->sum('amount'),
            'freigegeben' => (float) (clone $totalsBase)->where('status', 'freigegeben')->sum('amount'),
            'ausgezahlt' => (float) (clone $totalsBase)->where('status', 'ausgezahlt')->sum('amount'),
            'abzuege' => (float) (clone $totalsBase)->where('status', '!=', 'storniert')->where('amount', '<', 0)->sum('amount'),
        ];

        $employees = User::whereIn('role', ['admin', 'manager', 'support', 'employee'])->orderBy('name')->get();
        $partners = Partner::orderBy('name')->get();
        $insurers = Provision::whereNotNull('insurer')->where('insurer', '!=', '')
            ->distinct()->orderBy('insurer')->pluck('insurer');

        return view('admin.provisions', compact('provisions', 'totals', 'employees', 'partners', 'insurers'));
    }

    /** Gemeinsame Listen-/Kennzahlen-Filter (Empfaenger, Sparte, Zeitraum ...). */
    private function applyFilters($query, Request $request): void {
        if ($request->filled('empfaenger')) {
            $e = $request->empfaenger;
            if (str_starts_with($e, 'u:')) {
                $query->where('user_id', (int) substr($e, 2));
            } elseif (str_starts_with($e, 'p:')) {
                $query->where('partner_id', substr($e, 2));
            }
        }
        if ($request->filled('typ') && isset(Provision::TYPES[$request->typ])) {
            $query->where('type', $request->typ);
        }
        if ($request->filled('sparte')) {
            $query->where('contract_type', $request->sparte);
        }
        if ($request->filled('gesellschaft')) {
            $query->where('insurer', $request->gesellschaft);
        }
        if ($request->filled('kunde')) {
            $like = '%' . addcslashes(trim($request->kunde), '%_\\') . '%';
            $query->whereHas('customer', fn ($c) => $c->where('customer_number', 'like', $like)
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)));
        }
        if ($request->filled('monat')) {
            try {
                $m = \Carbon\Carbon::createFromFormat('Y-m', $request->monat)->startOfMonth();
                $query->whereBetween('created_at', [$m->copy()->startOfMonth(), $m->copy()->endOfMonth()]);
            } catch (\Throwable) {
                // Ungueltiger Monat -> Filter ignorieren.
            }
        } elseif ($request->filled('jahr') && preg_match('/^\d{4}$/', $request->jahr)) {
            $query->whereBetween('created_at', [
                \Carbon\Carbon::create((int) $request->jahr, 1, 1)->startOfDay(),
                \Carbon\Carbon::create((int) $request->jahr, 12, 31)->endOfDay(),
            ]);
        }
    }

    /** Detailseite: Buchung, Bezuege (Kunde/Vertrag/Gegenbuchung) + Audit-Log. */
    public function show($id) {
        $provision = Provision::with([
            'user', 'partner', 'customer.user', 'contract', 'creator', 'approver',
            'payer', 'relatedProvision', 'counterBookings', 'auditLogs.user',
        ])->findOrFail($id);

        return view('admin.provision_show', compact('provision'));
    }

    /** Provision/Bonus/Abzug manuell erfassen (Verwaltung). */
    public function store(Request $request) {
        $request->validate([
            'empfaenger' => 'required|string|max:60',
            'amount' => 'required|numeric|min:0.01|max:1000000',
            'art' => 'nullable|in:manuell,bonus,abzug',
            'note' => 'nullable|string|max:500|required_if:art,bonus|required_if:art,abzug',
            'kunde_id' => 'nullable|uuid',
            'sparte' => 'nullable|in:' . implode(',', Contract::typeKeys()),
            'period_from' => 'nullable|date',
            'period_to' => 'nullable|date|after_or_equal:period_from',
        ], [
            'note.required_if' => 'Fuer Bonus und Abzug ist ein Grund (Notiz) Pflicht.',
        ]);

        $art = $request->input('art') ?: 'manuell';
        // Abzuege werden als NEGATIVE Buchung gespeichert (Eingabe positiv).
        $amount = round((float) $request->amount, 2) * ($art === 'abzug' ? -1 : 1);

        $data = [
            'amount' => $amount,
            'type' => $art,
            'note' => $request->note,
            'period_from' => $request->period_from,
            'period_to' => $request->period_to,
            'status' => 'offen',
            'created_by' => auth()->id(),
        ];

        if ($request->filled('kunde_id')) {
            $data['customer_id'] = Customer::findOrFail($request->kunde_id)->id;
        }
        if ($request->filled('sparte')) {
            $data['contract_type'] = $request->sparte;
        }

        $e = $request->empfaenger;
        if (str_starts_with($e, 'u:')) {
            $recipient = User::whereIn('role', ['admin', 'manager', 'support', 'employee'])
                ->findOrFail((int) substr($e, 2));
            $data['user_id'] = $recipient->id;
        } elseif (str_starts_with($e, 'p:')) {
            $recipient = Partner::findOrFail(substr($e, 2));
            $data['partner_id'] = $recipient->id;
        } else {
            return back()->with('error', 'Ungueltiger Empfaenger.');
        }

        $provision = Provision::create($data);
        ProvisionAuditLog::write($provision, 'created', 'amount', null,
            number_format($amount, 2, '.', ''), $request->note);
        ActivityLog::record('provision_created', 'provision', $provision->id, [
            'empfaenger' => $recipient->name,
            'betrag' => $amount,
            'art' => $art,
        ]);

        return back()->with('success',
            Provision::TYPES[$art] . ' ueber ' . number_format(abs($amount), 2, ',', '.') . ' EUR fuer ' . $recipient->name . ' erfasst.');
    }

    /**
     * Status aendern: freigeben, auszahlen, stornieren oder wieder oeffnen.
     * Erlaubte Wege: offen -> freigegeben/ausgezahlt/storniert,
     * freigegeben -> ausgezahlt/storniert/offen, ausgezahlt -> offen
     * (Korrektur), storniert -> offen. Jede Aenderung mit Audit-Eintrag.
     */
    public function updateStatus(Request $request, $id) {
        $provision = Provision::findOrFail($id);
        $request->validate([
            'status' => 'required|in:offen,freigegeben,ausgezahlt,storniert',
            'grund' => 'nullable|string|max:500',
        ]);

        $old = $provision->status;
        $new = $request->status;
        $allowed = [
            'offen' => ['freigegeben', 'ausgezahlt', 'storniert'],
            'freigegeben' => ['ausgezahlt', 'storniert', 'offen'],
            'ausgezahlt' => ['offen'],
            'storniert' => ['offen'],
        ];
        if (!in_array($new, $allowed[$old] ?? [], true)) {
            return back()->with('error', 'Statuswechsel von "' . $provision->statusLabel() . '" nach "' . (Provision::STATUSES[$new] ?? $new) . '" ist nicht moeglich.');
        }

        $update = ['status' => $new];
        if ($new === 'freigegeben') {
            $update['approved_by'] = auth()->id();
            $update['approved_at'] = now();
            $update['paid_by'] = null;
            $update['paid_at'] = null;
        } elseif ($new === 'ausgezahlt') {
            // Direkt-Auszahlung aus "offen" schliesst die Freigabe mit ein.
            $update['approved_by'] = $provision->approved_by ?? auth()->id();
            $update['approved_at'] = $provision->approved_at ?? now();
            $update['paid_by'] = auth()->id();
            $update['paid_at'] = now();
        } elseif ($new === 'offen') {
            $update['approved_by'] = null;
            $update['approved_at'] = null;
            $update['paid_by'] = null;
            $update['paid_at'] = null;
        }
        $provision->update($update);

        ProvisionAuditLog::write($provision, 'status_changed', 'status', $old, $new, $request->grund);
        ActivityLog::record('provision_status_changed', 'provision', $provision->id, [
            'empfaenger' => $provision->recipientName(),
            'status' => $new,
        ]);

        return back()->with('success', 'Provision: ' . $provision->statusLabel() . '.');
    }

    /**
     * Betrag anpassen (erhoehen/kuerzen) - nur Verwaltung, nur solange die
     * Buchung nicht ausgezahlt/storniert ist, IMMER mit Grund (Audit-Log).
     */
    public function adjustAmount(Request $request, $id) {
        $provision = Provision::findOrFail($id);
        $request->validate([
            'amount' => 'required|numeric|min:-1000000|max:1000000|not_in:0',
            'grund' => 'required|string|max:500',
        ], [
            'grund.required' => 'Bitte einen Grund fuer die Anpassung angeben.',
        ]);

        if (!$provision->isAmountAdjustable()) {
            return back()->with('error', 'Ausgezahlte oder stornierte Buchungen koennen nicht mehr angepasst werden.');
        }

        $old = (float) $provision->amount;
        $new = round((float) $request->amount, 2);
        if ($old === $new) {
            return back()->with('error', 'Der Betrag ist unveraendert.');
        }

        $provision->update(['amount' => $new]);
        ProvisionAuditLog::write($provision, 'amount_changed', 'amount',
            number_format($old, 2, '.', ''), number_format($new, 2, '.', ''), $request->grund);
        ActivityLog::record('provision_amount_changed', 'provision', $provision->id, [
            'empfaenger' => $provision->recipientName(),
            'alt' => $old, 'neu' => $new, 'grund' => $request->grund,
        ]);

        return back()->with('success', 'Betrag angepasst: '
            . number_format($old, 2, ',', '.') . ' EUR -> ' . number_format($new, 2, ',', '.') . ' EUR.');
    }

    // ---------------------------------------------------------------
    // Provisions-Saetze je Sparte (Konfiguration)
    // ---------------------------------------------------------------

    /** Saetze-Seite: Empfaenger waehlen, je Sparte fix + Prozent pflegen. */
    public function rates(Request $request) {
        $employees = User::whereIn('role', ['admin', 'manager', 'support', 'employee'])->orderBy('name')->get();
        $partners = Partner::orderBy('name')->get();

        $selected = null;
        $selectedKey = (string) $request->get('empfaenger', '');
        if (str_starts_with($selectedKey, 'u:')) {
            $selected = $employees->firstWhere('id', (int) substr($selectedKey, 2));
        } elseif (str_starts_with($selectedKey, 'p:')) {
            $selected = $partners->firstWhere('id', substr($selectedKey, 2));
        }
        $rates = $selected
            ? $selected->provisionRates()->get()->keyBy('contract_type')
            : collect();

        // Uebersicht: wie viele Sparten-Saetze je Empfaenger gepflegt sind.
        $userRateCounts = ProvisionRate::whereNotNull('user_id')
            ->selectRaw('user_id, count(*) as anzahl')->groupBy('user_id')->pluck('anzahl', 'user_id');
        $partnerRateCounts = ProvisionRate::whereNotNull('partner_id')
            ->selectRaw('partner_id, count(*) as anzahl')->groupBy('partner_id')->pluck('anzahl', 'partner_id');

        return view('admin.provision_rates', compact(
            'employees', 'partners', 'selected', 'selectedKey', 'rates',
            'userRateCounts', 'partnerRateCounts'
        ));
    }

    /** Saetze speichern: je Sparte upsert; beide Felder leer = Satz loeschen. */
    public function ratesSave(Request $request) {
        $request->validate([
            'empfaenger' => 'required|string|max:60',
            'global_fixed' => 'nullable|numeric|min:0|max:999999',
            'global_percent' => 'nullable|numeric|min:0|max:100',
            'saetze' => 'nullable|array',
            'saetze.*.fixed' => 'nullable|numeric|min:0|max:999999',
            'saetze.*.percent' => 'nullable|numeric|min:0|max:100',
        ]);

        $e = (string) $request->empfaenger;
        if (str_starts_with($e, 'u:')) {
            $recipient = User::whereIn('role', ['admin', 'manager', 'support', 'employee'])
                ->findOrFail((int) substr($e, 2));
            $fk = ['user_id' => $recipient->id];
        } elseif (str_starts_with($e, 'p:')) {
            $recipient = Partner::findOrFail(substr($e, 2));
            $fk = ['partner_id' => $recipient->id];
        } else {
            return back()->with('error', 'Ungueltiger Empfaenger.');
        }

        // Globaler Fallback-Satz direkt am Empfaenger.
        $recipient->update([
            'provision_fixed' => $request->filled('global_fixed') ? round((float) $request->global_fixed, 2) : null,
            'provision_percent' => $request->filled('global_percent') ? round((float) $request->global_percent, 2) : null,
        ]);

        $saved = 0;
        $deleted = 0;
        foreach ((array) $request->input('saetze', []) as $type => $values) {
            if (!array_key_exists($type, Contract::TYPES)) {
                continue;
            }
            $fixed = ($values['fixed'] ?? '') !== '' ? round((float) $values['fixed'], 2) : null;
            $percent = ($values['percent'] ?? '') !== '' ? round((float) $values['percent'], 2) : null;

            $existing = ProvisionRate::where($fk)->where('contract_type', $type)->first();
            if ($fixed === null && $percent === null) {
                if ($existing) {
                    $existing->delete();
                    $deleted++;
                }
                continue;
            }
            ProvisionRate::updateOrCreate(
                $fk + ['contract_type' => $type],
                ['amount_fixed' => $fixed, 'amount_percent' => $percent],
            );
            $saved++;
        }

        ActivityLog::record('provision_rates_updated',
            array_key_first($fk) === 'user_id' ? 'user' : 'partner',
            $recipient->id, [
            'empfaenger' => $recipient->name,
            'sparten_saetze' => $saved,
            'geloescht' => $deleted,
        ]);

        return redirect()->route('admin.provisions.rates', ['empfaenger' => $e])
            ->with('success', 'Provisions-Saetze fuer ' . $recipient->name . ' gespeichert.');
    }

    // ---------------------------------------------------------------
    // Monatsbericht + Export
    // ---------------------------------------------------------------

    /** Monatsbericht je Mitarbeiter/Partner (blaetterbar, freier Zeitraum). */
    public function report(Request $request) {
        [$from, $to, $month] = $this->resolvePeriod($request);
        $rows = $this->buildReport($from, $to);

        $summary = [
            'kunden' => $rows->sum('kunden'),
            'vertraege' => $rows->sum('vertraege'),
            'provision' => round($rows->sum('provision'), 2),
            'abzuege' => round($rows->sum('abzuege'), 2),
            'netto' => round($rows->sum('netto'), 2),
        ];

        return view('admin.provision_report', compact('rows', 'summary', 'from', 'to', 'month'));
    }

    /** Export des Monatsberichts: Excel (xlsx, CSV-Fallback), CSV oder PDF-Druckansicht. */
    public function reportExport(Request $request) {
        [$from, $to, $month] = $this->resolvePeriod($request);
        $rows = $this->buildReport($from, $to);
        $format = $request->get('format', 'xlsx');
        $periodLabel = $from->format('d.m.Y') . ' - ' . $to->format('d.m.Y');
        $filename = 'provisionsbericht_' . $from->format('Y-m-d') . '_' . $to->format('Y-m-d');

        ActivityLog::record('provision_report_exported', null, null, [
            'zeitraum' => $periodLabel, 'format' => $format,
        ]);

        if ($format === 'pdf') {
            // Druckansicht: der Browser erzeugt daraus das PDF (kein
            // PDF-Fremdpaket noetig, Layout in provision_report_print).
            return view('admin.provision_report_print', [
                'rows' => $rows, 'from' => $from, 'to' => $to,
            ]);
        }

        $header = [
            'Empfaenger', 'Art', 'Neukunden', 'Neue Vertraege', 'Vertraege je Sparte',
            'Provision (EUR)', 'Abzuege (EUR)', 'Netto (EUR)',
        ];
        $data = $rows->map(fn ($r) => [
            $r['label'],
            $r['kind'] === 'partner' ? 'Partner' : 'Mitarbeiter',
            $r['kunden'],
            $r['vertraege'],
            collect($r['sparten'])->map(fn ($n, $t) => ($t !== '' ? (Contract::TYPES[$t]['label'] ?? $t) : 'Ohne') . ' x' . $n)->implode(', '),
            (float) number_format($r['provision'], 2, '.', ''),
            (float) number_format($r['abzuege'], 2, '.', ''),
            (float) number_format($r['netto'], 2, '.', ''),
        ])->all();

        if ($format === 'xlsx' && XlsxWriter::available()) {
            $content = XlsxWriter::create('Provisionsbericht', array_merge(
                [['Provisionsbericht ' . $periodLabel], [''], $header],
                $data,
            ));
            return response($content, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . $filename . '.xlsx"',
            ]);
        }

        // CSV-Fallback (Excel-kompatibel: BOM + Semikolon, Formel-Schutz).
        $csv = Writer::createFromString('');
        $csv->setDelimiter(';');
        $csv->addFormatter(new EscapeFormula());
        $csv->insertOne($header);
        $csv->insertAll($data);

        return response("\u{FEFF}" . $csv->toString(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '.csv"',
        ]);
    }

    /**
     * Berichtszeilen je Empfaenger: Neukunden (Werber-Attribution), neue
     * Vertraege (inkl. Sparten-Aufschluesselung) und Provisionssummen
     * (Provision/Abzuege/Netto; stornierte Buchungen zaehlen nicht).
     */
    private function buildReport(\Carbon\Carbon $from, \Carbon\Carbon $to) {
        $rows = [];
        $row = function (string $key, string $label, string $kind) use (&$rows) {
            return $rows[$key] ??= [
                'key' => $key, 'label' => $label, 'kind' => $kind,
                'kunden' => 0, 'vertraege' => 0, 'sparten' => [],
                'provision' => 0.0, 'abzuege' => 0.0, 'netto' => 0.0,
            ];
        };

        // Neukunden im Zeitraum je Werber.
        $customers = Customer::whereBetween('created_at', [$from, $to])
            ->where(fn ($q) => $q->whereNotNull('acquired_by')->orWhereNotNull('acquired_by_partner_id'))
            ->with(['acquirer', 'acquirerPartner'])->get();
        foreach ($customers as $c) {
            $key = $c->acquirerKey();
            if (!$key) continue;
            $r = $row($key, $c->acquirerLabel() ?? 'Unbekannt', str_starts_with($key, 'u:') ? 'mitarbeiter' : 'partner');
            $r['kunden']++;
            $rows[$key] = $r;
        }

        // Neue Vertraege im Zeitraum je Werber (auch ohne gebuchte Provision -
        // so faellt ein fehlender Satz sofort auf).
        $contracts = Contract::whereBetween('contracts.created_at', [$from, $to])
            ->join('customers', 'customers.id', '=', 'contracts.customer_id')
            ->where(fn ($q) => $q->whereNotNull('customers.acquired_by')->orWhereNotNull('customers.acquired_by_partner_id'))
            ->get(['contracts.type as sparte', 'customers.acquired_by as u', 'customers.acquired_by_partner_id as p']);
        $labels = $this->recipientLabels(
            $contracts->pluck('u')->filter()->unique(),
            $contracts->pluck('p')->filter()->unique(),
        );
        foreach ($contracts as $c) {
            $key = $c->u ? 'u:' . $c->u : 'p:' . $c->p;
            $r = $row($key, $labels[$key] ?? 'Unbekannt', $c->u ? 'mitarbeiter' : 'partner');
            $r['vertraege']++;
            $r['sparten'][$c->sparte ?? ''] = ($r['sparten'][$c->sparte ?? ''] ?? 0) + 1;
            $rows[$key] = $r;
        }

        // Provisionsbuchungen im Zeitraum (ohne stornierte Buchungen).
        $provisions = Provision::whereBetween('created_at', [$from, $to])
            ->where('status', '!=', 'storniert')
            ->with(['user', 'partner'])->get();
        foreach ($provisions as $p) {
            $key = $p->user_id ? 'u:' . $p->user_id : 'p:' . $p->partner_id;
            $r = $row($key, $p->recipientName(), $p->user_id ? 'mitarbeiter' : 'partner');
            $amount = (float) $p->amount;
            if ($amount >= 0) {
                $r['provision'] = round($r['provision'] + $amount, 2);
            } else {
                $r['abzuege'] = round($r['abzuege'] + $amount, 2);
            }
            $r['netto'] = round($r['netto'] + $amount, 2);
            $rows[$key] = $r;
        }

        return collect($rows)->sortByDesc('netto')->values();
    }

    /** Anzeigenamen fuer Empfaenger-Schluessel nachladen (Bericht/Dashboard). */
    private function recipientLabels($userIds, $partnerIds): array {
        $labels = [];
        foreach (User::whereIn('id', $userIds)->get() as $u) {
            $labels['u:' . $u->id] = $u->name;
        }
        foreach (Partner::whereIn('id', $partnerIds)->get() as $p) {
            $labels['p:' . $p->id] = $p->name;
        }
        return $labels;
    }

    /** Zeitraum: ?monat=YYYY-MM (Standard aktueller Monat) oder ?from/?to. */
    private function resolvePeriod(Request $request): array {
        if ($request->filled('from') || $request->filled('to')) {
            $from = \Carbon\Carbon::parse($request->get('from', now()->startOfMonth()))->startOfDay();
            $to = \Carbon\Carbon::parse($request->get('to', now()))->endOfDay();
            return [$from, $to, null];
        }
        try {
            $month = \Carbon\Carbon::createFromFormat('Y-m', $request->get('monat', now()->format('Y-m')))->startOfMonth();
        } catch (\Throwable) {
            $month = now()->startOfMonth();
        }
        return [$month->copy()->startOfMonth(), $month->copy()->endOfMonth(), $month];
    }

    // ---------------------------------------------------------------
    // Performance-Dashboard
    // ---------------------------------------------------------------

    /** Leistungsuebersicht: Vertraege, Provisionen, Beste, Verlaeufe. */
    public function dashboard(Request $request) {
        [$from, $to, $month] = $this->resolvePeriod($request);

        $contracts = Contract::whereBetween('contracts.created_at', [$from, $to])
            ->join('customers', 'customers.id', '=', 'contracts.customer_id')
            ->get([
                'contracts.id', 'contracts.type', 'contracts.premium_amount', 'contracts.premium_interval',
                'contracts.created_at', 'customers.acquired_by as u', 'customers.acquired_by_partner_id as p',
            ]);
        $provisions = Provision::whereBetween('created_at', [$from, $to])
            ->where('status', '!=', 'storniert')
            ->with(['user', 'partner'])->get();

        // Jahresbeitragsvolumen der neuen Vertraege (auf das Jahr normiert).
        $premiumVolume = round($contracts->sum(function ($c) {
            $perYear = Contract::PREMIUM_INTERVALS[$c->premium_interval]['per_year'] ?? 12;
            return (float) $c->premium_amount * $perYear;
        }), 2);

        // Je Empfaenger: Vertraege (Werber-Attribution) + Provisions-Netto.
        $byRecipient = [];
        $labels = $this->recipientLabels(
            $contracts->pluck('u')->filter()->unique()->merge($provisions->pluck('user_id')->filter()->unique()),
            $contracts->pluck('p')->filter()->unique()->merge($provisions->pluck('partner_id')->filter()->unique()),
        );
        $entry = function (string $key, string $kind) use (&$byRecipient, $labels) {
            return $byRecipient[$key] ??= [
                'key' => $key, 'label' => $labels[$key] ?? 'Unbekannt', 'kind' => $kind,
                'vertraege' => 0, 'netto' => 0.0,
            ];
        };
        foreach ($contracts as $c) {
            if (!$c->u && !$c->p) continue;
            $key = $c->u ? 'u:' . $c->u : 'p:' . $c->p;
            $r = $entry($key, $c->u ? 'mitarbeiter' : 'partner');
            $r['vertraege']++;
            $byRecipient[$key] = $r;
        }
        foreach ($provisions as $p) {
            $key = $p->user_id ? 'u:' . $p->user_id : 'p:' . $p->partner_id;
            $r = $entry($key, $p->user_id ? 'mitarbeiter' : 'partner');
            $r['netto'] = round($r['netto'] + (float) $p->amount, 2);
            $byRecipient[$key] = $r;
        }
        $byRecipient = collect($byRecipient)->sortByDesc('netto')->values();

        $kpis = [
            'vertraege' => $contracts->count(),
            'provision_netto' => round($provisions->sum(fn ($p) => (float) $p->amount), 2),
            'abzuege' => round($provisions->filter(fn ($p) => (float) $p->amount < 0)->sum(fn ($p) => (float) $p->amount), 2),
            'beitragsvolumen' => $premiumVolume,
            'bester_mitarbeiter' => $byRecipient->firstWhere('kind', 'mitarbeiter'),
            'bester_partner' => $byRecipient->firstWhere('kind', 'partner'),
        ];

        // Vertraege je Sparte im Zeitraum.
        $byProduct = $contracts->groupBy('type')->map(fn ($g) => $g->count())->sortDesc();

        // Monatsvergleich: die letzten 6 Monate (Vertraege + Provisions-Netto).
        $monthly = [];
        $cursor = ($month ?? now()->startOfMonth())->copy()->subMonths(5);
        for ($i = 0; $i < 6; $i++) {
            $mFrom = $cursor->copy()->startOfMonth();
            $mTo = $cursor->copy()->endOfMonth();
            $monthly[] = [
                'label' => $cursor->locale('de')->translatedFormat('M y'),
                'vertraege' => Contract::whereBetween('created_at', [$mFrom, $mTo])->count(),
                'netto' => round((float) Provision::whereBetween('created_at', [$mFrom, $mTo])
                    ->where('status', '!=', 'storniert')->sum('amount'), 2),
            ];
            $cursor->addMonth();
        }

        // Tagesproduktivitaet im gewaehlten Zeitraum (Vertraege je Tag).
        $daily = [];
        foreach ($contracts as $c) {
            $day = $c->created_at->format('Y-m-d');
            $daily[$day] = ($daily[$day] ?? 0) + 1;
        }
        ksort($daily);

        // Gesamtzahlen (alle Zeit) fuer die Fussleiste.
        $alltime = [
            'vertraege' => Contract::count(),
            'netto' => round((float) Provision::where('status', '!=', 'storniert')->sum('amount'), 2),
            'ausgezahlt' => round((float) Provision::where('status', 'ausgezahlt')->sum('amount'), 2),
        ];

        return view('admin.provision_dashboard', compact(
            'kpis', 'byRecipient', 'byProduct', 'monthly', 'daily', 'alltime', 'from', 'to', 'month'
        ));
    }
}
