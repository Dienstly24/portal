<?php
namespace App\Http\Controllers;

use App\Models\CommissionAuditLog;
use App\Models\CommissionImport;
use App\Models\CommissionPool;
use App\Models\CommissionReferenceLink;
use App\Models\Contract;
use App\Models\ContractCommission;
use App\Services\Provisionsmanagement\CommissionAnalytics;
use App\Services\Provisionsmanagement\CommissionStatusEngine;
use App\Services\Provisionsmanagement\MissingCommissionService;
use App\Services\Provisionsmanagement\PoolRegistry;
use App\Services\Provisionsmanagement\ReferenceLinkService;
use App\Support\CommissionKind;
use App\Support\CommissionStatus;
use App\Support\ContractCommissionStatus as Zustand;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Carbon;

/**
 * PROVISIONSMANAGEMENT (Betreiber-Auftrag 02.09.2026) - der Ueberbau ueber
 * den bereits vorhandenen Provisions-Import.
 *
 * ARBEITSTEILUNG, damit nichts doppelt existiert:
 *  - Import, Vorschau, Bestaetigung, Einzel-Provision, Rechnungsabgleich und
 *    Protokoll bleiben im `ContractCommissionController` - dort ist die
 *    Mechanik erprobt, und eine zweite Kopie liefe auseinander.
 *  - HIER liegt alles, was ueber die einzelne Datei hinausgeht: Dashboard,
 *    Vertraege mit ihrem Provisions-Zustand, fehlende Provisionen samt
 *    Nachverfolgung, Auswertungen und die Pool-Einstellungen.
 *
 * ZUGRIFF: dasselbe Recht wie im uebrigen Provisionsteil
 * (`provisionen-verwalten` = Admin oder ausdruecklich vergebenes Recht).
 * Die Pruefung steht an der ROUTE und zusaetzlich hier - ein Menuepunkt, den
 * man nicht sieht, ist keine Berechtigung, und eine Route allein wuerde beim
 * naechsten Umbau der Routendatei still verloren gehen.
 */
class ProvisionsmanagementController extends Controller implements HasMiddleware
{
    public function __construct(
        private PoolRegistry $pools,
        private CommissionAnalytics $analytics,
        private CommissionStatusEngine $engine,
        private MissingCommissionService $missing,
        private ReferenceLinkService $links,
    ) {
    }

    public static function middleware(): array
    {
        return ['can:provisionen-verwalten'];
    }

    // ------------------------------------------------------------ Dashboard

    public function dashboard()
    {
        return view('admin.provisionsmanagement.dashboard', [
            'kpi' => $this->analytics->dashboard(),
            'verlauf' => $this->analytics->byMonth(12),
            'pools' => $this->analytics->groupedBy('pool'),
            'abgleich' => $this->analytics->monthlyReconciliation(6),
            'letzteImporte' => CommissionImport::with('importer')->latest()->limit(5)->get(),
            'poolListe' => $this->pools->all(),
        ]);
    }

    // -------------------------------------------------------------- Importe

    /** Import-Historie (§24): jeder Lauf bleibt nachvollziehbar. */
    public function imports(Request $request)
    {
        $imports = CommissionImport::with('importer')
            ->when($request->filled('pool'), fn ($q) => $q->where('pool', $request->string('pool')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()->paginate(25)->withQueryString();

        return view('admin.provisionsmanagement.imports', [
            'imports' => $imports,
            'poolListe' => $this->pools->all(),
            'filters' => $request->only(['pool', 'status']),
        ]);
    }

    /**
     * Abrechnungen: die BESTAETIGTEN Laeufe mit ihren Summen.
     *
     * Bewusst getrennt von der Import-Historie: ein verworfener Entwurf ist
     * ein Vorgang, aber keine Abrechnung. Wer nach Geld sucht, soll nicht
     * zwischen Entwuerfen suchen muessen.
     */
    public function statements(Request $request)
    {
        $summen = ContractCommission::selectRaw('import_id, COUNT(*) as anzahl, COALESCE(SUM(amount),0) as netto')
            ->whereNotNull('import_id')->groupBy('import_id')->get()->keyBy('import_id');

        $imports = CommissionImport::with('importer')
            ->where('status', CommissionImport::IMPORTIERT)
            ->when($request->filled('pool'), fn ($q) => $q->where('pool', $request->string('pool')))
            ->latest('confirmed_at')->paginate(25)->withQueryString();

        return view('admin.provisionsmanagement.statements', [
            'imports' => $imports,
            'summen' => $summen,
            'poolListe' => $this->pools->all(),
            'filters' => $request->only(['pool']),
        ]);
    }

    // ------------------------------------------------------------- Vertraege

    /** Vertraege mit ihrem Provisions-Zustand (§23 in Listenform). */
    public function contracts(Request $request)
    {
        $status = $request->string('status')->toString();

        $vertraege = Contract::query()
            ->with(['customer.user'])
            ->withSum('commissions as provision_netto', 'amount')
            ->withCount('commissions')
            ->where(fn ($q) => $q->whereNotNull('pool')->orWhereNotNull('commission_status'))
            ->when($request->filled('pool'), fn ($q) => $q->where('pool', $request->string('pool')))
            ->when(Zustand::isValid($status), fn ($q) => $q->where('commission_status', $status))
            ->when($request->filled('q'), fn ($q) => $q->search($request->string('q')->toString()))
            ->orderByDesc('created_at')
            ->paginate(30)->withQueryString();

        return view('admin.provisionsmanagement.contracts', [
            'vertraege' => $vertraege,
            'poolListe' => $this->pools->all(),
            'filters' => $request->only(['pool', 'status', 'q']),
        ]);
    }

    /** Provisionsbereich EINES Vertrags (§23). */
    public function contract(string $id)
    {
        $contract = Contract::with(['customer.user', 'commissions', 'commissionFollowup'])->findOrFail($id);
        $this->engine->refresh($contract);

        return view('admin.provisionsmanagement.contract', [
            'contract' => $contract->fresh(['customer.user', 'commissions', 'commissionFollowup']),
            'pool' => $this->pools->find($contract->pool),
            'poolListe' => $this->pools->all(),
            'links' => CommissionReferenceLink::where('contract_id', $contract->id)->get(),
            'monate' => $this->missing->monthsSinceClosing($contract),
            'abschluss' => $this->engine->closingDate($contract),
        ]);
    }

    // -------------------------------------------------- Fehlende Provisionen

    public function missingList(Request $request)
    {
        $filter = $request->only(['pool', 'status', 'monat', 'produkt', 'mitarbeiter', 'kunde']);
        $liste = $this->missing->query($filter)->paginate(30)->withQueryString();

        return view('admin.provisionsmanagement.missing', [
            'liste' => $liste,
            'filters' => $filter,
            'poolListe' => $this->pools->all(),
            'monate' => $this->missing,
            'mitarbeiter' => \App\Models\User::whereIn('role', ['admin', 'manager', 'support', 'employee'])
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Bearbeitungsstand eines Falls setzen (§19). */
    public function followup(Request $request, string $id)
    {
        $contract = Contract::findOrFail($id);
        $daten = $request->validate([
            'status' => 'required|in:' . implode(',', array_keys(\App\Models\CommissionFollowup::STATUSES)),
            'contacted_on' => 'nullable|date',
            'contact_person' => 'nullable|string|max:190',
            'response' => 'nullable|string|max:2000',
            'note' => 'nullable|string|max:2000',
        ]);

        $this->missing->updateFollowup($contract, $daten, auth()->id());

        return back()->with('success', 'Bearbeitungsstand gespeichert.');
    }

    /** Zustand neu berechnen - fuer einen Vertrag oder den ganzen Bestand. */
    public function recalculate(Request $request)
    {
        $id = $request->string('contract_id')->toString();
        if ($id !== '') {
            $contract = Contract::findOrFail($id);
            $this->engine->refresh($contract);
            return back()->with('success', 'Provisions-Zustand neu berechnet.');
        }

        $ergebnis = $this->engine->refreshAll();
        return back()->with('success', sprintf(
            '%d Verträge geprüft, %d Zustände aktualisiert.',
            $ergebnis['geprueft'],
            $ergebnis['geaendert']
        ));
    }

    // --------------------------------------------------- Unklare Zuordnungen

    /**
     * Was ein Mensch entscheiden muss: Provisionen ohne Vertrag und
     * Buchungen mit unklarem Status. Beides steht bewusst auf EINER Seite -
     * es ist dieselbe Arbeit ("hinschauen und zuordnen").
     */
    public function unclear(Request $request)
    {
        $art = $request->string('art')->toString() ?: 'ohne_vertrag';

        $liste = ContractCommission::query()
            ->when($art === 'ohne_vertrag', fn ($q) => $q->whereNull('contract_id'))
            ->when($art === 'status', fn ($q) => $q->where('status', CommissionStatus::UNKLAR))
            ->when($request->filled('pool'), fn ($q) => $q->where('pool', $request->string('pool')))
            ->latest('created_at')->paginate(30)->withQueryString();

        return view('admin.provisionsmanagement.unclear', [
            'liste' => $liste,
            'art' => $art,
            'poolListe' => $this->pools->all(),
            'filters' => $request->only(['pool', 'art']),
            'anzahlOhneVertrag' => ContractCommission::whereNull('contract_id')->count(),
            'anzahlStatus' => ContractCommission::where('status', CommissionStatus::UNKLAR)->count(),
        ]);
    }

    // ---------------------------------------------------------- Auswertungen

    public function analytics(Request $request)
    {
        [$von, $bis] = $this->range($request);

        return view('admin.provisionsmanagement.analytics', [
            'von' => $von,
            'bis' => $bis,
            'summen' => $this->analytics->sums($von, $bis, $request->string('pool')->toString() ?: null),
            'verlauf' => $this->analytics->byMonth(12, $request->string('pool')->toString() ?: null),
            'nachPool' => $this->analytics->groupedBy('pool', $von, $bis),
            'nachProdukt' => $this->analytics->groupedBy('product_name', $von, $bis, 20),
            'nachArt' => $this->analytics->groupedBy('commission_kind', $von, $bis),
            'nachGesellschaft' => $this->analytics->groupedBy('company', $von, $bis, 20),
            'kundenTop' => $this->analytics->customerProfitability(20, 'desc'),
            'kundenFlop' => $this->analytics->customerProfitability(10, 'asc'),
            'abgleich' => $this->analytics->monthlyReconciliation(12, $request->string('pool')->toString() ?: null),
            'poolListe' => $this->pools->all(),
            'filters' => $request->only(['pool', 'von', 'bis']),
        ]);
    }

    /** Wirtschaftlichkeit EINES Kunden - ausschliesslich hier sichtbar. */
    public function customer(string $id)
    {
        $customer = \App\Models\Customer::with('user')->findOrFail($id);

        return view('admin.provisionsmanagement.customer', [
            'customer' => $customer,
            'zahlen' => $this->analytics->forCustomer($customer->id),
            'buchungen' => ContractCommission::where('customer_id', $customer->id)
                ->orderByDesc('commission_date')->limit(200)->get(),
        ]);
    }

    /**
     * Export der Auswertung (§31). CSV, weil es jede Tabellenkalkulation
     * ohne Zusatzpaket oeffnet - und weil ein Export, der beim Oeffnen
     * scheitert, keiner ist. Der Download wird GESTREAMT: eine
     * Jahresauswertung darf nicht erst vollstaendig im Speicher stehen.
     */
    public function export(Request $request)
    {
        [$von, $bis] = $this->range($request);
        $pool = $request->string('pool')->toString() ?: null;

        $query = ContractCommission::query()
            ->when($pool, fn ($q) => $q->where('pool', $pool))
            ->when($von, fn ($q) => $q->whereDate('commission_date', '>=', $von))
            ->when($bis, fn ($q) => $q->whereDate('commission_date', '<=', $bis))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('art'), fn ($q) => $q->where('commission_kind', $request->string('art')))
            ->when($request->filled('kunde'), fn ($q) => $q->where('customer_id', $request->string('kunde')))
            ->when($request->filled('vertrag'), fn ($q) => $q->where('contract_id', $request->string('vertrag')));

        $dateiname = 'provisionen-' . now()->format('Y-m-d-His') . '.csv';

        // Der Protokolleintrag entsteht VOR dem Streamen: er darf nicht
        // davon abhaengen, dass der Download sauber zu Ende laeuft.
        app(\App\Services\CommissionImport\CommissionAuditLogger::class)
            ->log('export', null, ['new_value' => $dateiname]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel liest sonst keine Umlaute
            fputcsv($out, [
                'Pool', 'Quelle', 'Datei', 'Zeile', 'Provisionsdatum', 'Buchungsdatum',
                'Provisionsart (Quelle)', 'Provisionsart', 'Kunde', 'Vertrag',
                'Interne Vertragsnr.', 'Referenz-Nr.', 'Pool-Id', 'Gesellschaft', 'Produkt',
                'Betrag', 'Währung', 'Status', 'Zuordnung', 'Stornogrund',
            ], ';');

            $query->orderBy('id')->chunkById(500, function ($chunk) use ($out) {
                foreach ($chunk as $c) {
                    fputcsv($out, [
                        $c->poolLabel(), $c->providerLabel(), $c->source_file, $c->source_row,
                        $c->commission_date?->format('d.m.Y'), $c->booking_date?->format('d.m.Y'),
                        $c->commission_type, $c->kindLabel(), $c->customer_label, $c->contract_label,
                        $c->internal_contract_number, $c->reference_number, $c->vermittler_id,
                        $c->company, $c->product_name,
                        number_format((float) $c->amount, 2, ',', ''), $c->currency,
                        $c->statusLabel(), $c->matchLabel(), $c->storno_reason,
                    ], ';');
                }
            });
            fclose($out);
        }, $dateiname, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // --------------------------------------------------------- Einstellungen

    public function settings()
    {
        return view('admin.provisionsmanagement.settings', [
            'pools' => CommissionPool::orderBy('name')->get(),
            'profile' => $this->pools->profileOptions(),
            'letzteProtokolle' => CommissionAuditLog::latest('created_at')->limit(20)->get(),
        ]);
    }

    public function poolStore(Request $request)
    {
        $daten = $this->validatePool($request);
        $daten['key'] = \Illuminate\Support\Str::slug($request->string('key')->toString() ?: $daten['name'], '_');

        if (CommissionPool::where('key', $daten['key'])->exists()) {
            return back()->with('error', 'Diesen Pool-Schlüssel gibt es bereits.');
        }

        CommissionPool::create($daten);
        $this->pools->forget();
        app(\App\Services\CommissionImport\CommissionAuditLogger::class)
            ->log('pool_angelegt', null, ['new_value' => $daten['name']]);

        return back()->with('success', 'Pool „' . $daten['name'] . '“ angelegt.');
    }

    public function poolUpdate(Request $request, string $id)
    {
        $pool = CommissionPool::findOrFail($id);
        $vorher = $pool->deadlineLabel();
        $pool->update($this->validatePool($request));
        $this->pools->forget();

        app(\App\Services\CommissionImport\CommissionAuditLogger::class)->log('pool_geaendert', null, [
            'field' => 'fristen',
            'old_value' => $vorher,
            'new_value' => $pool->fresh()->deadlineLabel(),
        ]);

        // Die Fristen sind die Rechengrundlage der Zustaende - aendert sie
        // jemand, muessen die betroffenen Vertraege neu bewertet werden.
        // Sonst zeigte die Liste bis zum naechsten Nachtlauf alte Zahlen.
        $this->engine->refreshAll(
            Contract::where('pool', $pool->key)->pluck('id')->all() ?: null
        );

        return back()->with('success', 'Pool gespeichert – die Fristen wurden neu angewendet.');
    }

    /** @return array<string,mixed> */
    private function validatePool(Request $request): array
    {
        $daten = $request->validate([
            'name' => 'required|string|max:120',
            'source_profile' => 'nullable|string|max:60',
            // Obergrenze mit Absicht: eine Frist von 100 Monaten waere keine
            // Frist mehr, sondern ein Vertrag, den nie jemand prueft.
            'expected_months' => 'required|integer|min:0|max:36',
            'check_months' => 'required|integer|min:0|max:60',
            'contact' => 'nullable|string|max:190',
            'notes' => 'nullable|string|max:2000',
        ]);
        $daten['active'] = $request->boolean('active');
        // Ein nicht gesendetes Feld ist kein Fehler: das Formular der
        // Einstellungen darf schmaler sein als die Tabelle.
        $daten['source_profile'] = ($daten['source_profile'] ?? null) ?: null;

        // Die Prueffrist liegt nie VOR der Erwartung - sonst waere ein
        // Vertrag "fehlt", bevor er ueberhaupt "erwartet" war.
        if ($daten['check_months'] < $daten['expected_months']) {
            $daten['check_months'] = $daten['expected_months'];
        }
        return $daten;
    }

    // ----------------------------------------------------------------- intern

    /** @return array{0:?Carbon,1:?Carbon} */
    private function range(Request $request): array
    {
        $von = $request->filled('von') ? Carbon::parse($request->string('von')->toString())->startOfDay() : null;
        $bis = $request->filled('bis') ? Carbon::parse($request->string('bis')->toString())->endOfDay() : null;
        return [$von, $bis];
    }
}
