<?php

namespace App\Services\Reporting;

use App\Models\Contract;
use App\Models\Customer;
use App\Support\Bundesland;
use App\Support\LocalTime;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * DETAILLISTE HINTER JEDER ZAHL der Berichtsseite (Berichte 2.0,
 * Betreiber-Auftrag 25.11.2026: "wir wissen nicht genau, mit wie vielen
 * Kunden wir im Monat gearbeitet haben - und mit WELCHEN").
 *
 * EINE QUELLE FUER ANZEIGE UND EXPORT. Tabelle, CSV und Excel bekommen ihre
 * Zeilen aus zeilen() - eine Spalte, die im Export anders gerechnet waere
 * als auf dem Bildschirm, kann so nicht entstehen. Die Abfrage selbst kommt
 * aus DashboardAnalyticsService::liste(), also derselben, mit der die
 * Kachel zaehlt: Zahl auf der Kachel = Zeilen hier = Zeilen im Export.
 *
 * SUCHE UND SORTIERUNG schraenken nur die ANZEIGE ein (die Seite sagt dann
 * "x von y"); die Gesamtzahl bleibt die der Kachel.
 */
class BerichtsListe
{
    /** Sortierbare Spalten je Listenart - Whitelist, nie Nutzereingabe in SQL. */
    public const SORTIERUNG = [
        'vertrag' => [
            'abschluss' => 'abschluss',
            'beginn' => 'contracts.start_date',
            'ablauf' => 'contracts.end_date',
            'gesellschaft' => 'contracts.insurer',
            'sparte' => 'contracts.type',
            'ort' => 'customers.address_city',
            'beitrag' => 'jahresbeitrag',
        ],
        'kunde' => [
            'angelegt' => 'customers.created_at',
            'nummer' => 'customers.customer_number',
            'ort' => 'customers.address_city',
            'plz' => 'customers.address_zip',
        ],
    ];

    public function __construct(
        private readonly DashboardAnalyticsService $dienst,
        private readonly AnalyticsFilters $f,
        public readonly string $liste,
    ) {}

    public function art(): string
    {
        return DashboardAnalyticsService::LISTEN[$this->liste]['art'];
    }

    /** Gesamtzahl OHNE Suche - die Zahl der Kachel. */
    public function gesamt(): int
    {
        return $this->dienst->liste($this->liste, $this->f)->count();
    }

    /** Abfrage samt Suche und Sortierung. */
    public function abfrage(?string $suche, ?string $sortierung, string $richtung = 'desc'): Builder
    {
        $q = $this->dienst->liste($this->liste, $this->f);
        $richtung = $richtung === 'asc' ? 'asc' : 'desc';
        $spalten = self::SORTIERUNG[$this->art()];

        if ($this->art() === 'vertrag') {
            // contracts.* ausdruecklich: die Basis joint customers, und ohne
            // Spaltenwahl ueberschriebe customers.id die Vertrags-ID.
            $q->select('contracts.*')
                ->selectRaw(DashboardAnalyticsService::abschlussSql().' as abschluss')
                ->selectRaw(DashboardAnalyticsService::jahresbeitragZeileSql().' as jahresbeitrag')
                ->with(['customer.user', 'customer.acquirer', 'customer.acquirerPartner']);
            if ($suche !== null && trim($suche) !== '') {
                $like = '%'.addcslashes(trim($suche), '%_\\').'%';
                $kunden = Customer::query()->search($suche)->select('customers.id');
                $q->where(function ($w) use ($like, $kunden) {
                    $w->where('contracts.contract_number', 'like', $like)
                        ->orWhere('contracts.reference_number', 'like', $like)
                        ->orWhere('contracts.insurer', 'like', $like)
                        ->orWhereIn('contracts.customer_id', $kunden);
                });
            }
            $q->orderByRaw(($spalten[$sortierung] ?? 'abschluss').' '.$richtung)->orderBy('contracts.id');
        } else {
            $q->select('customers.*')->with(['user', 'acquirer', 'acquirerPartner'])
                ->withCount(['contracts as vertraege_gesamt' => fn ($c) => $c->ownPortfolio()]);
            if ($suche !== null && trim($suche) !== '') {
                $q->search($suche);
            }
            $q->orderBy($spalten[$sortierung] ?? 'customers.created_at', $richtung)->orderBy('customers.id');
        }

        return $q;
    }

    public function seite(?string $suche, ?string $sortierung, string $richtung, int $proSeite = 50): LengthAwarePaginator
    {
        $seite = $this->abfrage($suche, $sortierung, $richtung)->paginate($proSeite)->withQueryString();
        $seite->setCollection(collect($this->zeilen($seite->getCollection())));

        return $seite;
    }

    /**
     * Modelle -> fertige Zeilen (Anzeige UND Export).
     *
     * @param  Collection<int,Model>  $modelle
     * @return list<array<string,mixed>>
     */
    public function zeilen(Collection $modelle): array
    {
        return $this->art() === 'vertrag'
            ? $this->vertragsZeilen($modelle->whereInstanceOf(Contract::class))
            : $this->kundenZeilen($modelle->whereInstanceOf(Customer::class));
    }

    /** Spaltenkoepfe des Exports (gleiche Reihenfolge wie exportZeile()). */
    public function exportKopf(): array
    {
        return $this->art() === 'vertrag'
            ? ['Vertragsnummer', 'Referenz-Nr.', 'Kundennummer', 'Kunde', 'Kundentyp', 'PLZ', 'Ort', 'Bundesland',
                'Sparte', 'Gesellschaft', 'Antrag', 'Unterschrift', 'Beginn', 'Abschluss', 'Datumsquelle',
                'Ablauf', 'Kündigung', 'Status', 'Beitrag', 'Zahlweise', 'Jahresbeitrag', 'Mitarbeiter/Vermittler']
            : ['Kundennummer', 'Kunde', 'Kundentyp', 'PLZ', 'Ort', 'Bundesland', 'Angelegt am',
                'Verträge gesamt', 'Abschlüsse im Zeitraum', 'Sparten im Zeitraum', 'Jahresbeitrag im Zeitraum',
                'Mitarbeiter/Vermittler'];
    }

    /** @param array<string,mixed> $z */
    public function exportZeile(array $z): array
    {
        return $this->art() === 'vertrag'
            ? [$z['vertragsnummer'], $z['referenz'], $z['kundennummer'], $z['kunde'], $z['kundentyp'], $z['plz'],
                $z['ort'], $z['bundesland'], $z['sparte'], $z['gesellschaft'], $z['antrag'], $z['unterschrift'],
                $z['beginn'], $z['abschluss'], $z['datumsquelle'], $z['ablauf'], $z['kuendigung'], $z['status'],
                $z['beitrag'], $z['zahlweise'], $z['jahresbeitrag'], $z['werber']]
            : [$z['kundennummer'], $z['kunde'], $z['kundentyp'], $z['plz'], $z['ort'], $z['bundesland'],
                $z['angelegt'], $z['vertraege_gesamt'], $z['abschluesse'], $z['sparten'], $z['wert'], $z['werber']];
    }

    // -----------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function vertragsZeilen(Collection $vertraege): array
    {
        $datum = fn ($w) => $w ? Carbon::parse($w)->format('d.m.Y') : '';

        return $vertraege->map(function (Contract $c) use ($datum) {
            $kunde = $c->customer;
            $quelle = match (true) {
                $c->signing_date !== null => 'Unterschrift',
                $c->application_date !== null => 'Antrag',
                $c->start_date !== null => 'Beginn',
                default => 'Anlage',
            };

            return [
                'id' => $c->id,
                'kunde_id' => $c->customer_id,
                'vertragsnummer' => (string) $c->contract_number,
                'referenz' => (string) $c->reference_number,
                'kundennummer' => (string) $kunde?->customer_number,
                'kunde' => $this->kundenName($kunde),
                'kundentyp' => $this->kundentyp($kunde),
                'plz' => (string) $kunde?->address_zip,
                'ort' => trim((string) $kunde?->address_city),
                'bundesland' => $kunde ? $this->land($kunde->address_zip) : '',
                'sparte' => Contract::TYPES[$c->type]['label'] ?? Contract::LEGACY_TYPES[$c->type]['label'] ?? (string) $c->type,
                'gesellschaft' => (string) $c->insurer,
                'antrag' => $datum($c->application_date),
                'unterschrift' => $datum($c->signing_date),
                'beginn' => $datum($c->start_date),
                'abschluss' => $datum($c->getAttribute('abschluss')),
                'datumsquelle' => $quelle,
                'ablauf' => $datum($c->end_date),
                'kuendigung' => $datum($c->cancellation_date),
                'status' => $c->statusGroupLabel(),
                'beitrag' => $c->hasPremium() ? round((float) $c->premium_amount, 2) : '',
                'zahlweise' => $c->hasPremium() ? $c->premiumIntervalLabel() : '',
                'jahresbeitrag' => round((float) $c->getAttribute('jahresbeitrag'), 2),
                'werber' => (string) ($kunde?->acquirerLabel() ?? ''),
            ];
        })->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function kundenZeilen(Collection $kunden): array
    {
        // Abschluesse IM ZEITRAUM je Kunde: eine Abfrage fuer die ganze
        // Seite (nicht je Zeile) und ueber DIESELBE Vertragsmenge wie die
        // Kachel "Neue Vertraege" - mit allen Filtern.
        $ids = $kunden->pluck('id')->all();
        $abschluesse = $ids === [] ? collect() : $this->dienst->liste('vertraege_neu', $this->f)
            ->whereIn('contracts.customer_id', $ids)
            ->selectRaw('contracts.customer_id as kid, contracts.type as type, '.DashboardAnalyticsService::jahresbeitragZeileSql().' as wert')
            ->toBase()->get()->groupBy('kid');

        return $kunden->map(function (Customer $k) use ($abschluesse) {
            $eigene = $abschluesse->get($k->id, collect());
            $sparten = collect($eigene)->pluck('type')->unique()
                ->map(fn ($t) => Contract::TYPES[$t]['label'] ?? Contract::LEGACY_TYPES[$t]['label'] ?? $t)
                ->implode(', ');

            return [
                'id' => $k->id,
                'kunde_id' => $k->id,
                'kundennummer' => (string) $k->customer_number,
                'kunde' => $this->kundenName($k),
                'kundentyp' => $this->kundentyp($k),
                'plz' => (string) $k->address_zip,
                'ort' => trim((string) $k->address_city),
                'bundesland' => $this->land($k->address_zip),
                'angelegt' => (string) LocalTime::for($k->created_at)?->format('d.m.Y'),
                'vertraege_gesamt' => (int) $k->getAttribute('vertraege_gesamt'),
                'abschluesse' => count($eigene),
                'sparten' => $sparten,
                'wert' => round((float) collect($eigene)->sum('wert'), 2),
                'werber' => (string) ($k->acquirerLabel() ?? ''),
            ];
        })->values()->all();
    }

    private function kundenName(?Customer $k): string
    {
        if (! $k) {
            return '';
        }
        $firma = trim((string) $k->company_name);
        $name = trim((string) $k->user?->name);

        return $k->customer_type === 'firma' && $firma !== '' ? $firma : ($name !== '' ? $name : $firma);
    }

    private function kundentyp(?Customer $k): string
    {
        return match ($k?->customer_type) {
            'firma' => 'Firma',
            'privat' => 'Privat',
            default => '',
        };
    }

    /** Bundesland wie auf der Karte (gleiche Ableitung ueber drei Stellen). */
    private function land(?string $plz): string
    {
        $kuerzel = Bundesland::ausPlz(substr(trim((string) $plz), 0, 3).'00');

        return $kuerzel === Bundesland::UNBEKANNT ? '' : Bundesland::name($kuerzel);
    }
}
