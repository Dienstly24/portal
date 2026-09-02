<?php
namespace App\Services\Provisionsmanagement;

use App\Models\CommissionImport;
use App\Models\Contract;
use App\Models\ContractCommission;
use App\Support\CommissionKind;
use App\Support\CommissionStatus;
use App\Support\ContractCommissionStatus as Zustand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Die Zahlen des Provisionsmanagements (Dashboard §21, Auswertungen §22).
 *
 * ALLES AUS DEN BUCHUNGEN: keine Kennzahl wird irgendwo mitgefuehrt oder
 * fortgeschrieben. Ein gepflegter Zaehler laeuft beim ersten Storno
 * auseinander, und dann glaubt niemand mehr der Anzeige. Die Abfragen sind
 * dafuer bewusst reine Aggregate (SUM/COUNT) - es wird nie eine Zeile
 * geladen, um sie in PHP zusammenzuzaehlen.
 *
 * NETTO IST DIE SUMME, nicht Brutto minus Storno: Rueckbuchungen stehen in
 * den Dateien als NEGATIVE Betraege. Sie noch einmal abzuziehen, haette sie
 * doppelt gezaehlt - genau der Fehler, den §13 mit "-0,59 €" beschreibt.
 */
class CommissionAnalytics
{
    public function __construct(private PoolRegistry $pools)
    {
    }

    /** Das Datum, nach dem ausgewertet wird - fallback auf das Anlagedatum. */
    private const DATE = 'COALESCE(commission_date, booking_date, DATE(created_at))';

    /**
     * Provisions-Kennzahlen eines Zeitraums.
     *
     * @return array{brutto:float,storno:float,korrektur:float,netto:float,anzahl:int}
     */
    public function sums(?Carbon $from = null, ?Carbon $to = null, ?string $pool = null): array
    {
        $row = ContractCommission::query()
            ->when($pool, fn ($q) => $q->where('pool', $pool))
            ->when($from, fn ($q) => $q->whereRaw(self::DATE . ' >= ?', [$from->toDateString()]))
            ->when($to, fn ($q) => $q->whereRaw(self::DATE . ' <= ?', [$to->toDateString()]))
            ->selectRaw('COUNT(*) as anzahl')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END),0) as brutto')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END),0) as negativ')
            ->selectRaw('COALESCE(SUM(CASE WHEN commission_kind = ? THEN amount ELSE 0 END),0) as korrektur', [CommissionKind::KORREKTUR])
            ->selectRaw('COALESCE(SUM(amount),0) as netto')
            ->first();

        return [
            'anzahl' => (int) ($row->anzahl ?? 0),
            'brutto' => round((float) ($row->brutto ?? 0), 2),
            'storno' => round((float) ($row->negativ ?? 0), 2),
            'korrektur' => round((float) ($row->korrektur ?? 0), 2),
            'netto' => round((float) ($row->netto ?? 0), 2),
        ];
    }

    /** Die Karten des Dashboards. */
    public function dashboard(): array
    {
        $heute = Carbon::today();

        return [
            'monat' => $this->sums($heute->copy()->startOfMonth(), $heute->copy()->endOfMonth()),
            'vormonat' => $this->sums(
                $heute->copy()->subMonthNoOverflow()->startOfMonth(),
                $heute->copy()->subMonthNoOverflow()->endOfMonth()
            ),
            'jahr' => $this->sums($heute->copy()->startOfYear(), $heute->copy()->endOfYear()),
            'vorjahr' => $this->sums(
                $heute->copy()->subYear()->startOfYear(),
                $heute->copy()->subYear()->endOfYear()
            ),
            'vertraege' => $this->contractCounters(),
            'probleme' => $this->problems(),
        ];
    }

    /** Vertragszahlen: wer ist verguetet, wer nicht. */
    public function contractCounters(): array
    {
        $heute = Carbon::today();
        $base = fn () => Contract::query()->where(function ($q) {
            $q->whereNotNull('pool')->orWhereNotNull('commission_status');
        });

        $verguetet = [Zustand::ERHALTEN, Zustand::LAUFEND, Zustand::VOLLSTAENDIG];

        return [
            'neu_im_monat' => (int) $base()->where('created_at', '>=', $heute->copy()->startOfMonth())->count(),
            'verguetet' => (int) $base()->whereIn('commission_status', $verguetet)->count(),
            'nicht_verguetet' => (int) $base()->whereIn('commission_status', [
                Zustand::NEU, Zustand::ERWARTET, Zustand::UEBERFAELLIG, Zustand::FEHLT,
            ])->count(),
            'ueberfaellig' => (int) $base()->where('commission_status', Zustand::UEBERFAELLIG)->count(),
            'fehlt' => (int) $base()->where('commission_status', Zustand::FEHLT)->count(),
            'storniert' => (int) $base()->where('commission_status', Zustand::STORNIERT)->count(),
            'korrektur' => (int) $base()->where('commission_status', Zustand::KORREKTUR)->count(),
        ];
    }

    /** Was muss ein Mensch anschauen? */
    public function problems(): array
    {
        return [
            'fehlende' => (int) Contract::whereIn('commission_status', Zustand::OFFENE_FAELLE)->count(),
            'unklare' => (int) ContractCommission::whereNull('contract_id')->count(),
            'unklare_status' => (int) ContractCommission::where('status', CommissionStatus::UNKLAR)->count(),
            'neue_kunden' => (int) CommissionImport::where('status', 'importiert')->sum('customers_created'),
            'neue_vertraege' => (int) CommissionImport::where('status', 'importiert')->sum('contracts_created'),
            'importfehler' => (int) CommissionImport::where('status', 'importiert')->sum('rows_invalid'),
            'entwuerfe' => (int) CommissionImport::where('status', 'entwurf')->count(),
        ];
    }

    /**
     * Provision je Monat (fuer den Verlauf).
     *
     * @return array<int,array{monat:string,netto:float,brutto:float,storno:float,anzahl:int}>
     */
    public function byMonth(int $months = 12, ?string $pool = null): array
    {
        $from = Carbon::today()->startOfMonth()->subMonthsNoOverflow($months - 1);

        $rows = ContractCommission::query()
            ->when($pool, fn ($q) => $q->where('pool', $pool))
            ->whereRaw(self::DATE . ' >= ?', [$from->toDateString()])
            ->selectRaw("substr(" . self::DATE . ", 1, 7) as monat")
            ->selectRaw('COUNT(*) as anzahl')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END),0) as brutto')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END),0) as storno')
            ->selectRaw('COALESCE(SUM(amount),0) as netto')
            ->groupBy('monat')->orderBy('monat')
            ->get();

        return $rows->map(fn ($r) => [
            'monat' => (string) $r->monat,
            'anzahl' => (int) $r->anzahl,
            'brutto' => round((float) $r->brutto, 2),
            'storno' => round((float) $r->storno, 2),
            'netto' => round((float) $r->netto, 2),
        ])->all();
    }

    /**
     * Gruppierte Auswertung nach Pool, Produkt oder Provisionsart.
     *
     * @return array<int,array{schluessel:?string,label:string,anzahl:int,netto:float,storno:float}>
     */
    public function groupedBy(string $column, ?Carbon $from = null, ?Carbon $to = null, int $limit = 50): array
    {
        $erlaubt = ['pool', 'product_name', 'commission_kind', 'company', 'provider', 'sparte'];
        if (! in_array($column, $erlaubt, true)) {
            $column = 'pool'; // nie eine Spalte aus einer Anfrage uebernehmen
        }

        $rows = ContractCommission::query()
            ->when($from, fn ($q) => $q->whereRaw(self::DATE . ' >= ?', [$from->toDateString()]))
            ->when($to, fn ($q) => $q->whereRaw(self::DATE . ' <= ?', [$to->toDateString()]))
            ->selectRaw($column . ' as schluessel')
            ->selectRaw('COUNT(*) as anzahl')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END),0) as storno')
            ->selectRaw('COALESCE(SUM(amount),0) as netto')
            ->groupBy('schluessel')->orderByDesc('netto')->limit($limit)->get();

        return $rows->map(fn ($r) => [
            'schluessel' => $r->schluessel,
            'label' => match ($column) {
                'pool' => $this->pools->label($r->schluessel),
                'commission_kind' => CommissionKind::label($r->schluessel),
                default => (string) ($r->schluessel ?: '— ohne Angabe —'),
            },
            'anzahl' => (int) $r->anzahl,
            'storno' => round((float) $r->storno, 2),
            'netto' => round((float) $r->netto, 2),
        ])->all();
    }

    /**
     * Wirtschaftlichkeit je Kunde (§22).
     *
     * NUR INTERN: diese Zahlen erscheinen ausschliesslich im
     * Provisionsmanagement. Es gibt bewusst keine Beziehung von `Customer`
     * hierher, damit ein `with()` im Portal sie gar nicht mitladen kann.
     *
     * @return array<int,array{customer_id:string,name:string,vertraege:int,brutto:float,storno:float,netto:float,schnitt:float,stornoquote:float}>
     */
    public function customerProfitability(int $limit = 25, string $richtung = 'desc'): array
    {
        $rows = ContractCommission::query()
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id')
            ->selectRaw('MAX(customer_label) as name')
            ->selectRaw('COUNT(DISTINCT contract_id) as vertraege')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END),0) as brutto')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END),0) as storno')
            ->selectRaw('COALESCE(SUM(amount),0) as netto')
            ->groupBy('customer_id')
            ->orderBy('netto', $richtung === 'asc' ? 'asc' : 'desc')
            ->limit($limit)->get();

        return $rows->map(function ($r) {
            $vertraege = max(1, (int) $r->vertraege);
            $brutto = round((float) $r->brutto, 2);
            return [
                'customer_id' => (string) $r->customer_id,
                'name' => (string) ($r->name ?: '—'),
                'vertraege' => (int) $r->vertraege,
                'brutto' => $brutto,
                'storno' => round((float) $r->storno, 2),
                'netto' => round((float) $r->netto, 2),
                'schnitt' => round((float) $r->netto / $vertraege, 2),
                'stornoquote' => $brutto > 0 ? round(abs((float) $r->storno) / $brutto * 100, 1) : 0.0,
            ];
        })->all();
    }

    /** Die Zahlen EINES Kunden - fuer die Kundenakte im Provisionsteil. */
    public function forCustomer(string $customerId): array
    {
        $zahlen = ContractCommission::where('customer_id', $customerId)
            ->selectRaw('COUNT(*) as anzahl')
            ->selectRaw('COUNT(DISTINCT contract_id) as vertraege')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END),0) as brutto')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END),0) as storno')
            ->selectRaw('COALESCE(SUM(amount),0) as netto')
            ->first();

        $vertraege = max(1, (int) ($zahlen->vertraege ?? 0));
        $brutto = round((float) ($zahlen->brutto ?? 0), 2);

        return [
            'anzahl' => (int) ($zahlen->anzahl ?? 0),
            'vertraege' => (int) ($zahlen->vertraege ?? 0),
            'brutto' => $brutto,
            'storno' => round((float) ($zahlen->storno ?? 0), 2),
            'netto' => round((float) ($zahlen->netto ?? 0), 2),
            'schnitt' => round((float) ($zahlen->netto ?? 0) / $vertraege, 2),
            'stornoquote' => $brutto > 0 ? round(abs((float) ($zahlen->storno ?? 0)) / $brutto * 100, 1) : 0.0,
            'nach_monat' => $this->customerByMonth($customerId),
            'nach_produkt' => $this->customerGrouped($customerId, 'product_name'),
            'nach_pool' => $this->customerGrouped($customerId, 'pool'),
        ];
    }

    private function customerByMonth(string $customerId): array
    {
        return ContractCommission::where('customer_id', $customerId)
            ->selectRaw("substr(" . self::DATE . ", 1, 7) as monat")
            ->selectRaw('COALESCE(SUM(amount),0) as netto')
            ->groupBy('monat')->orderBy('monat')->get()
            ->map(fn ($r) => ['monat' => (string) $r->monat, 'netto' => round((float) $r->netto, 2)])
            ->all();
    }

    private function customerGrouped(string $customerId, string $column): array
    {
        return ContractCommission::where('customer_id', $customerId)
            ->selectRaw($column . ' as schluessel')
            ->selectRaw('COALESCE(SUM(amount),0) as netto')
            ->groupBy('schluessel')->orderByDesc('netto')->get()
            ->map(fn ($r) => [
                'label' => $column === 'pool'
                    ? $this->pools->label($r->schluessel)
                    : (string) ($r->schluessel ?: '— ohne Angabe —'),
                'netto' => round((float) $r->netto, 2),
            ])->all();
    }

    /**
     * Monatlicher Vertragsabgleich (§16): abgeschlossene Vertraege gegen
     * erhaltene Provisionen. Die Zahlen beantworten genau die Frage des
     * Betreibers: "100 abgeschlossen - wie viele sind bezahlt, wie viele
     * noch in der Frist, wie viele ueberfaellig?"
     */
    public function monthlyReconciliation(int $months = 6, ?string $pool = null): array
    {
        $from = Carbon::today()->startOfMonth()->subMonthsNoOverflow($months - 1);
        $verguetet = [Zustand::ERHALTEN, Zustand::LAUFEND, Zustand::VOLLSTAENDIG];

        $rows = Contract::query()
            ->whereNotNull('pool')
            ->when($pool, fn ($q) => $q->where('pool', $pool))
            ->whereRaw('COALESCE(signing_date, application_date, start_date, DATE(created_at)) >= ?', [$from->toDateString()])
            ->selectRaw("substr(COALESCE(signing_date, application_date, start_date, DATE(created_at)), 1, 7) as monat")
            ->selectRaw('COUNT(*) as abgeschlossen')
            ->selectRaw('SUM(CASE WHEN commission_status IN (?, ?, ?) THEN 1 ELSE 0 END) as verguetet', $verguetet)
            ->selectRaw('SUM(CASE WHEN commission_status IN (?, ?) THEN 1 ELSE 0 END) as in_frist', [Zustand::NEU, Zustand::ERWARTET])
            ->selectRaw('SUM(CASE WHEN commission_status = ? THEN 1 ELSE 0 END) as ueberfaellig', [Zustand::UEBERFAELLIG])
            ->selectRaw('SUM(CASE WHEN commission_status = ? THEN 1 ELSE 0 END) as fehlt', [Zustand::FEHLT])
            ->groupBy('monat')->orderByDesc('monat')->get();

        return $rows->map(fn ($r) => [
            'monat' => (string) $r->monat,
            'abgeschlossen' => (int) $r->abgeschlossen,
            'verguetet' => (int) $r->verguetet,
            'in_frist' => (int) $r->in_frist,
            'ueberfaellig' => (int) $r->ueberfaellig,
            'fehlt' => (int) $r->fehlt,
        ])->all();
    }
}
