<?php

namespace App\Services\Dashboard;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\SignatureRequest;
use App\Models\Task;
use App\Models\User;
use App\Services\Provisionsmanagement\CommissionAnalytics;
use App\Services\Reporting\AnalyticsFilters;
use App\Services\Reporting\DashboardAnalyticsService;
use App\Support\SignatureStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Zahlen und Listen des Dashboards (UI-Modernisierung 27.11.2026).
 *
 * Die Seite RECHNET NICHT SELBST: jede Kennzahl kommt aus derselben
 * Abfrage wie die Liste, auf die sie verlinkt. "Neue Kunden diesen Monat"
 * zaehlt deshalb ueber DashboardAnalyticsService::liste('neukunden') - die
 * Berichtsliste dahinter hat damit genau so viele Zeilen wie die Kachel.
 *
 * Portfolio-Scope ueber $ids wie ueberall (null = alle sichtbar).
 *
 * PROVISIONEN NUR FUER ADMINISTRATOREN (Betreiber-Vorgabe 27.11.2026): die
 * Kachel entsteht nur bei role === 'admin' - auch ein Manager mit dem Recht
 * `provisionen-verwalten` sieht sie auf dem Dashboard nicht (er hat dafuer
 * das Provisionsmanagement). Fuer alle anderen wird die Summe gar nicht
 * erst berechnet, sie kann also auch nicht im HTML stehen.
 */
class DashboardUebersicht
{
    /** @param  array<int,string>|null  $ids */
    public function __construct(private readonly ?array $ids, private readonly User $user)
    {
    }

    /** @return array<string,mixed> */
    public function kennzahlen(): array
    {
        $monat = now()->format('Y-m');
        $vormonat = now()->startOfMonth()->subMonthNoOverflow()->format('Y-m');
        $analyse = new DashboardAnalyticsService($this->ids);

        $neu = $analyse->liste('neukunden', $this->monatsFilter($monat))->count();
        $neuVormonat = $analyse->liste('neukunden', $this->monatsFilter($vormonat))->count();

        $offeneAufgaben = Task::query()->where('assigned_to', $this->user->id)->where('status', '!=', 'done');
        $ueberfaellig = (clone $offeneAufgaben)->whereNotNull('due_date')->whereDate('due_date', '<', today())->count();

        $zahlen = [
            'neukunden' => $neu,
            'neukunden_vormonat' => $neuVormonat,
            'neukunden_trend' => self::trend($neu, $neuVormonat),
            'neukunden_link' => route('admin.reports.liste', ['liste' => 'neukunden', 'zeitraum' => 'monatswahl', 'monat' => $monat]),
            'in_bearbeitung' => $this->vertraege()->inProgress()->ownPortfolio()->count(),
            'aufgaben' => (clone $offeneAufgaben)->count(),
            'aufgaben_ueberfaellig' => $ueberfaellig,
            'provision' => null,
        ];

        if ($this->user->role === 'admin') {
            $analytics = app(CommissionAnalytics::class);
            $jetzt = $analytics->sums(now()->startOfMonth(), now()->endOfMonth());
            $vor = $analytics->sums(now()->startOfMonth()->subMonthNoOverflow(), now()->startOfMonth()->subMonthNoOverflow()->endOfMonth());
            $zahlen['provision'] = [
                'netto' => $jetzt['netto'],
                'anzahl' => $jetzt['anzahl'],
                'vormonat' => $vor['netto'],
                'trend' => self::trend($jetzt['netto'], $vor['netto']),
            ];
        }

        return $zahlen;
    }

    /**
     * Neue Vertraege je Monat (letzte 6 Monate), Abschluss-Definition der
     * Berichte (Unterschrift -> Antrag -> Beginn -> Anlage), eigener Bestand.
     *
     * @return array{labels:array<int,string>,werte:array<int,int>,monate:array<int,string>}
     */
    public function vertraegeJeMonat(int $monate = 6): array
    {
        $von = now()->startOfMonth()->subMonthsNoOverflow($monate - 1);
        $sql = DashboardAnalyticsService::abschlussSql();

        $zeilen = $this->vertraege()->ownPortfolio()
            ->whereRaw("date($sql) >= ?", [$von->toDateString()])
            ->selectRaw("substr(date($sql), 1, 7) as monat, COUNT(*) as anzahl")
            ->groupBy('monat')
            ->pluck('anzahl', 'monat');

        $labels = $werte = $schluessel = [];
        for ($i = 0; $i < $monate; $i++) {
            $m = $von->copy()->addMonthsNoOverflow($i);
            $schluessel[] = $m->format('Y-m');
            $labels[] = $m->locale('de')->translatedFormat('M y');
            $werte[] = (int) ($zeilen[$m->format('Y-m')] ?? 0);
        }

        return ['labels' => $labels, 'werte' => $werte, 'monate' => $schluessel];
    }

    /**
     * Aktive Vertraege des eigenen Bestands je Sparte - EINE gruppierte
     * Abfrage. Vorher zaehlte das Diagramm fuenf feste Sparten ueber den
     * GESAMTEN Bestand (ohne Portfolio-Scope, inkl. beendeter Vertraege)
     * - ein Mitarbeiter sah dort Zahlen fremder Kunden.
     *
     * @return array<int,array{label:string,wert:int,sparte:string}>
     */
    public function sparten(int $max = 6): array
    {
        $zeilen = $this->vertraege()->currentlyActive()->ownPortfolio()
            ->select('type', DB::raw('COUNT(*) as anzahl'))
            ->groupBy('type')->orderByDesc('anzahl')->get();

        $ergebnis = [];
        $rest = 0;
        foreach ($zeilen as $i => $z) {
            if ($i < $max - 1 || $zeilen->count() <= $max) {
                $ergebnis[] = [
                    'label' => Contract::TYPES[$z->type]['label'] ?? (string) $z->type,
                    'wert' => (int) $z->anzahl,
                    'sparte' => (string) $z->type,
                ];
            } else {
                $rest += (int) $z->anzahl;
            }
        }
        if ($rest > 0) {
            $ergebnis[] = ['label' => 'Weitere', 'wert' => $rest, 'sparte' => ''];
        }

        return $ergebnis;
    }

    /** Eigene offene Aufgaben: ueberfaellig zuerst, dann nach Faelligkeit. */
    public function meineAufgaben(int $anzahl = 6)
    {
        return Task::query()->with('customer.user')
            ->where('assigned_to', $this->user->id)->where('status', '!=', 'done')
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')->orderByDesc('created_at')
            ->take($anzahl)->get();
    }

    /**
     * Signaturen, die auf eine Unterschrift warten - dieselbe Sichtbarkeit
     * wie die Signaturliste (Kundenvorgaenge nach Portfolio, eigenstaendige
     * Vorgaenge nur fuer ihren Ersteller).
     */
    public function offeneSignaturen(int $anzahl = 5): array
    {
        $basis = fn () => $this->sichtbareSignaturen()->whereIn('status', [
            SignatureStatus::SENT, SignatureStatus::VIEWED, SignatureStatus::PARTIALLY_SIGNED,
        ]);

        return [
            'anzahl' => $basis()->count(),
            'liste' => $basis()->with(['customer.user', 'signers'])->latest('updated_at')->take($anzahl)->get(),
        ];
    }

    /** Neu angelegte Kunden (Anlagedatum), im Portfolio. */
    public function neuesteKunden(int $anzahl = 6)
    {
        return Customer::query()->with('user')
            ->when($this->ids !== null, fn ($q) => $q->whereIn('id', $this->ids))
            ->withCount(['contracts as active_contracts_count' => fn ($q) => $q->currentlyActive()->ownPortfolio()])
            ->latest()->take($anzahl)->get();
    }

    /**
     * Veraenderung gegen den Vergleichswert. Ohne Vergleichsbasis gibt es
     * KEINEN Prozentwert ("+100 %" waere fuer 1 dasselbe wie fuer 1000).
     *
     * @return array{art:string,text:string}
     */
    public static function trend(float|int $jetzt, float|int $vorher): array
    {
        if ((float) $vorher == 0.0) {
            return (float) $jetzt == 0.0
                ? ['art' => 'gleich', 'text' => '±0 %']
                : ['art' => 'neu', 'text' => 'neu'];
        }
        $prozent = (int) round((($jetzt - $vorher) / abs($vorher)) * 100);

        return [
            'art' => $prozent > 0 ? 'hoch' : ($prozent < 0 ? 'runter' : 'gleich'),
            'text' => ($prozent > 0 ? '+' : ($prozent < 0 ? '−' : '±')).abs($prozent).' %',
        ];
    }

    private function vertraege()
    {
        return Contract::query()->when($this->ids !== null, fn ($q) => $q->whereIn('customer_id', $this->ids));
    }

    private function sichtbareSignaturen()
    {
        $query = SignatureRequest::query();
        if ($this->ids === null) {
            return $query;
        }
        $ids = $this->ids;
        $userId = (int) $this->user->id;

        return $query->where(function ($q) use ($ids, $userId) {
            $q->where(fn ($i) => $i->whereNotNull('customer_id')->whereIn('customer_id', $ids))
                ->orWhere(fn ($i) => $i->whereNull('customer_id')->where('created_by', $userId));
        });
    }

    private function monatsFilter(string $monat): AnalyticsFilters
    {
        return AnalyticsFilters::ausRequest(new Request(['zeitraum' => 'monatswahl', 'monat' => $monat]));
    }
}
