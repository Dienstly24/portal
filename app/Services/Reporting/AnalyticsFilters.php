<?php

namespace App\Services\Reporting;

use App\Models\Contract;
use App\Support\Bundesland;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * DIE Filter der Berichtsseite - EIN Objekt statt fuenf Request-Zugriffe
 * (Betreiber-Auftrag 06.09.2026).
 *
 * WARUM EIGENES OBJEKT: die Seite wertet dieselbe Auswahl an rund einem
 * Dutzend Stellen aus (Kennzahlen, Verlauf, Sparten, Karte, Kundenstruktur,
 * Status, Insights). Laege die Auslegung von `?zeitraum=monat` in jeder
 * dieser Abfragen erneut, wuerden sie frueher oder spaeter verschiedene
 * Zeitraeume meinen - genau der Fehler, den die Seite aufdecken soll.
 *
 * VALIDIERT WIRD HIER, NICHT SPAETER: eine Sparte, ein Bundesland oder ein
 * Status, den es nicht gibt, wird verworfen (nicht "durchgereicht und
 * hoffentlich ignoriert"). Der Filterwert geht in Abfragen ein - er darf
 * nichts sein, was wir nicht kennen.
 *
 * Der Vergleichszeitraum ist IMMER die unmittelbar davorliegende Spanne
 * GLEICHER LAENGE. Ein Vergleich mit "Vorjahr gleicher Monat" waere bei
 * frei gewaehltem Zeitraum nicht definierbar; die einheitliche Regel ist
 * erklaerbar und gilt fuer jede Auswahl.
 */
class AnalyticsFilters
{
    public const ZEITRAEUME = [
        'heute' => 'Heute',
        'monat' => 'Dieser Monat',
        'jahr' => 'Dieses Jahr',
        'benutzerdefiniert' => 'Benutzerdefiniert',
    ];

    public const GRANULARITAETEN = [
        'monat' => 'Monat',
        'quartal' => 'Quartal',
        'jahr' => 'Jahr',
    ];

    public function __construct(
        public readonly string $zeitraum,
        public readonly Carbon $von,
        public readonly Carbon $bis,
        public readonly string $granularitaet,
        public readonly ?string $sparte = null,
        public readonly ?string $bundesland = null,
        public readonly ?string $kundentyp = null,
        public readonly ?string $vertragsstatus = null,
    ) {}

    public static function ausRequest(Request $request): self
    {
        $zeitraum = $request->get('zeitraum');
        if (! array_key_exists((string) $zeitraum, self::ZEITRAEUME)) {
            // Alte Lesezeichen der Vorgaengerseite trugen nur ?from/?to.
            $zeitraum = ($request->filled('von') || $request->filled('bis')
                || $request->filled('from') || $request->filled('to'))
                ? 'benutzerdefiniert' : 'monat';
        }

        [$von, $bis] = match ($zeitraum) {
            'heute' => [now()->startOfDay(), now()->endOfDay()],
            'jahr' => [now()->startOfYear(), now()->endOfDay()],
            'benutzerdefiniert' => [
                self::datum($request->get('von') ?? $request->get('from'), now()->startOfMonth())->startOfDay(),
                self::datum($request->get('bis') ?? $request->get('to'), now())->endOfDay(),
            ],
            default => [now()->startOfMonth(), now()->endOfDay()],
        };

        // Verdrehte Eingabe nicht ablehnen, sondern drehen - der Nutzer meint
        // erkennbar eine Spanne, nicht einen Fehler.
        if ($von->greaterThan($bis)) {
            [$von, $bis] = [$bis->copy()->startOfDay(), $von->copy()->endOfDay()];
        }

        $granularitaet = $request->get('takt');
        if (! array_key_exists((string) $granularitaet, self::GRANULARITAETEN)) {
            $granularitaet = 'monat';
        }

        return new self(
            zeitraum: $zeitraum,
            von: $von,
            bis: $bis,
            granularitaet: $granularitaet,
            sparte: self::ausListe($request->get('sparte'), Contract::typeKeys()),
            bundesland: self::ausListe($request->get('bundesland'), Bundesland::kuerzel()),
            kundentyp: self::ausListe($request->get('kundentyp'), ['privat', 'firma']),
            vertragsstatus: self::ausListe($request->get('status'), [
                Contract::GROUP_ACTIVE, Contract::GROUP_PENDING, Contract::GROUP_HISTORY,
            ]),
        );
    }

    /** Vergleichszeitraum: gleich lange Spanne unmittelbar davor. */
    public function vergleichVon(): Carbon
    {
        return $this->vergleichBis()->subSeconds($this->laengeSekunden());
    }

    public function vergleichBis(): Carbon
    {
        return $this->von->copy()->subSecond();
    }

    /**
     * ABGERUNDET, nicht gerundet: `bis` traegt als Tagesende
     * 23:59:59.999999, eine kaufmaennische Rundung machte daraus eine
     * Sekunde mehr - und der Vergleichszeitraum begaenne einen Tag zu
     * frueh. Bei einer Kennzahl, die "gegenüber dem Vorzeitraum" behauptet,
     * ist ein zusaetzlicher Tag ein echter Fehler.
     */
    private function laengeSekunden(): int
    {
        return max(1, (int) floor($this->von->diffInSeconds($this->bis)));
    }

    /** Ist ausser dem Zeitraum ueberhaupt etwas eingeschraenkt? */
    public function hatSachfilter(): bool
    {
        return $this->sparte !== null || $this->bundesland !== null
            || $this->kundentyp !== null || $this->vertragsstatus !== null;
    }

    public function istStandard(): bool
    {
        return $this->zeitraum === 'monat' && $this->granularitaet === 'monat' && ! $this->hatSachfilter();
    }

    /** Aktive Filter als Chips ("Sparte: KFZ") - fuer die Filterleiste. */
    public function chips(): array
    {
        $chips = [];
        if ($this->sparte !== null) {
            $chips[] = ['schluessel' => 'sparte', 'label' => 'Sparte', 'wert' => Contract::TYPES[$this->sparte]['label'] ?? $this->sparte];
        }
        if ($this->bundesland !== null) {
            $chips[] = ['schluessel' => 'bundesland', 'label' => 'Bundesland', 'wert' => Bundesland::name($this->bundesland)];
        }
        if ($this->kundentyp !== null) {
            $chips[] = ['schluessel' => 'kundentyp', 'label' => 'Kundentyp', 'wert' => $this->kundentyp === 'privat' ? 'Privatkunden' : 'Firmenkunden'];
        }
        if ($this->vertragsstatus !== null) {
            $chips[] = ['schluessel' => 'status', 'label' => 'Vertragsstatus', 'wert' => Contract::GROUP_LABELS[$this->vertragsstatus] ?? $this->vertragsstatus];
        }

        return $chips;
    }

    /** Aktuelle Auswahl als Query-Parameter (Links behalten den Filterstand). */
    public function alsQuery(array $ersetzen = []): array
    {
        $q = array_filter([
            'zeitraum' => $this->zeitraum,
            'von' => $this->zeitraum === 'benutzerdefiniert' ? $this->von->format('Y-m-d') : null,
            'bis' => $this->zeitraum === 'benutzerdefiniert' ? $this->bis->format('Y-m-d') : null,
            'takt' => $this->granularitaet,
            'sparte' => $this->sparte,
            'bundesland' => $this->bundesland,
            'kundentyp' => $this->kundentyp,
            'status' => $this->vertragsstatus,
        ], fn ($v) => $v !== null && $v !== '');

        foreach ($ersetzen as $k => $v) {
            if ($v === null) {
                unset($q[$k]);
            } else {
                $q[$k] = $v;
            }
        }

        return $q;
    }

    private static function ausListe($wert, array $erlaubt): ?string
    {
        $wert = is_string($wert) ? trim($wert) : null;

        return ($wert !== null && $wert !== '' && in_array($wert, $erlaubt, true)) ? $wert : null;
    }

    private static function datum($wert, Carbon $ersatz): Carbon
    {
        try {
            return $wert ? Carbon::parse($wert) : $ersatz->copy();
        } catch (\Throwable) {
            return $ersatz->copy();
        }
    }
}
