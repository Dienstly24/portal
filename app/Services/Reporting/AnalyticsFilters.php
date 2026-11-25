<?php

namespace App\Services\Reporting;

use App\Models\Contract;
use App\Models\Partner;
use App\Models\User;
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
    /**
     * Zeitraeume der Filterleiste (Berichte 2.0, 25.11.2026: Gestern, Woche,
     * Vormonat, Quartal, Vorjahr und die Monatsauswahl kamen dazu - die
     * Frage "wie viele Vertraege im NOVEMBER" brauchte vorher zwei
     * Datumsfelder). 'monatswahl' ist kein Reiter, sondern das Monatsfeld.
     */
    public const ZEITRAEUME = [
        'heute' => 'Heute',
        'gestern' => 'Gestern',
        'woche' => 'Diese Woche',
        'letzte_woche' => 'Letzte Woche',
        'monat' => 'Dieser Monat',
        'letzter_monat' => 'Letzter Monat',
        'quartal' => 'Dieses Quartal',
        'jahr' => 'Dieses Jahr',
        'letztes_jahr' => 'Letztes Jahr',
        'monatswahl' => 'Monat',
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
        public readonly ?string $stadt = null,
        public readonly ?string $plz = null,
        public readonly ?string $gesellschaft = null,
        public readonly ?string $werber = null,
    ) {}

    public static function ausRequest(Request $request): self
    {
        $zeitraum = $request->get('zeitraum');
        $monat = self::monatAus($request->get('monat'));
        if ($zeitraum === 'monatswahl' && $monat === null) {
            $zeitraum = 'monat';
        }
        // "?monat=2026-11" allein (Link aus dem Monatsbericht) meint den Monat.
        if (($zeitraum === null || $zeitraum === '') && $monat !== null) {
            $zeitraum = 'monatswahl';
        }
        if (! array_key_exists((string) $zeitraum, self::ZEITRAEUME)) {
            // Alte Lesezeichen der Vorgaengerseite trugen nur ?from/?to.
            $zeitraum = ($request->filled('von') || $request->filled('bis')
                || $request->filled('from') || $request->filled('to'))
                ? 'benutzerdefiniert' : 'monat';
        }

        [$von, $bis] = match ($zeitraum) {
            'heute' => [now()->startOfDay(), now()->endOfDay()],
            'gestern' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            'woche' => [now()->startOfWeek(), now()->endOfDay()],
            'letzte_woche' => [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()],
            'letzter_monat' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'quartal' => [now()->startOfQuarter(), now()->endOfDay()],
            'jahr' => [now()->startOfYear(), now()->endOfDay()],
            'letztes_jahr' => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()],
            // Ein ganzer Kalendermonat. Liegt er in der Zukunft, wird er
            // trotzdem gezeigt (leer) - das ist eine Auswahl, kein Fehler.
            // $monat ist hier nie null: ohne gueltigen Monat wurde der Zeitraum
            // oben auf 'monat' gesetzt.
            'monatswahl' => [$monat->copy()->startOfMonth(), $monat->copy()->endOfMonth()],
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
            bundesland: self::ausListe($request->get('bundesland'), [...Bundesland::kuerzel(), Bundesland::UNBEKANNT]),
            kundentyp: self::ausListe($request->get('kundentyp'), ['privat', 'firma']),
            vertragsstatus: self::ausListe($request->get('status'), [
                Contract::GROUP_ACTIVE, Contract::GROUP_PENDING, Contract::GROUP_HISTORY,
            ]),
            stadt: self::text($request->get('stadt'), 80),
            // PLZ: nur Ziffern, 1-5 Stellen - als ANFANG gelesen ("24" =
            // alle 24xxx). Alles andere waere kein PLZ-Filter, sondern Suche.
            plz: preg_match('/^\d{1,5}$/', trim((string) $request->get('plz'))) ? trim((string) $request->get('plz')) : null,
            gesellschaft: self::text($request->get('gesellschaft'), 120),
            werber: self::werberAus($request->get('werber')),
        );
    }

    /** Monat aus "JJJJ-MM" - oder null bei allem anderen. */
    private static function monatAus($wert): ?Carbon
    {
        if (! is_string($wert) || ! preg_match('/^(\d{4})-(\d{2})$/', $wert, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
            return null;
        }

        return Carbon::create((int) $m[1], (int) $m[2], 1)->startOfMonth();
    }

    /**
     * Werber-Schluessel wie im Neukunden-Bericht: 'u:<id>' (Mitarbeiter),
     * 'p:<uuid>' (Partner) oder 'keiner'. Nur die FORM wird hier geprueft;
     * der Wert geht ausschliesslich als gebundener Parameter in die Abfrage
     * und trifft bei einer unbekannten ID schlicht niemanden.
     */
    private static function werberAus($wert): ?string
    {
        $wert = is_string($wert) ? trim($wert) : '';
        if ($wert === 'keiner' || preg_match('/^u:\d{1,10}$/', $wert) || preg_match('/^p:[A-Za-z0-9-]{1,64}$/', $wert)) {
            return $wert;
        }

        return null;
    }

    /** Freitext-Filterwert (Stadt, Gesellschaft): getrimmt, gedeckelt, sonst null. */
    private static function text($wert, int $max): ?string
    {
        $wert = is_string($wert) ? trim($wert) : '';

        return ($wert !== '' && mb_strlen($wert) <= $max) ? $wert : null;
    }

    /** Monatswert fuer das Monatsfeld der Filterleiste ("2026-11"). */
    public function monatWert(): string
    {
        return $this->von->format('Y-m');
    }

    /** Lesbare Beschreibung des Zeitraums fuer Ueberschriften und Exporte. */
    public function zeitraumText(): string
    {
        return $this->von->format('d.m.Y').' – '.$this->bis->format('d.m.Y');
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
            || $this->kundentyp !== null || $this->vertragsstatus !== null
            || $this->stadt !== null || $this->plz !== null
            || $this->gesellschaft !== null || $this->werber !== null;
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
        if ($this->stadt !== null) {
            $chips[] = ['schluessel' => 'stadt', 'label' => 'Stadt', 'wert' => $this->stadt];
        }
        if ($this->plz !== null) {
            $chips[] = ['schluessel' => 'plz', 'label' => 'PLZ', 'wert' => 'PLZ '.$this->plz.(strlen($this->plz) < 5 ? '…' : '')];
        }
        if ($this->gesellschaft !== null) {
            $chips[] = ['schluessel' => 'gesellschaft', 'label' => 'Gesellschaft', 'wert' => $this->gesellschaft];
        }
        if ($this->werber !== null) {
            $chips[] = ['schluessel' => 'werber', 'label' => 'Mitarbeiter/Vermittler', 'wert' => $this->werberName()];
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
            'monat' => $this->zeitraum === 'monatswahl' ? $this->von->format('Y-m') : null,
            'takt' => $this->granularitaet,
            'sparte' => $this->sparte,
            'bundesland' => $this->bundesland,
            'kundentyp' => $this->kundentyp,
            'status' => $this->vertragsstatus,
            'stadt' => $this->stadt,
            'plz' => $this->plz,
            'gesellschaft' => $this->gesellschaft,
            'werber' => $this->werber,
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

    /**
     * Query fuer die Detailliste hinter einer Zahl: aktuelle Auswahl +
     * Liste + zusaetzliche Filter (Klick auf "Kiel" = stadt=Kiel). Mit
     * $zeitraum bekommt die Liste eine EIGENE Spanne - fuer die festen
     * Kacheln "diesen Monat"/"dieses Jahr", deren Zahl nicht der Auswahl
     * oben folgt.
     *
     * @param  array<string,mixed>  $filter
     * @param  array{0:Carbon,1:Carbon}|null  $zeitraum
     */
    public function listeQuery(string $liste, array $filter = [], ?array $zeitraum = null): array
    {
        $ersetzen = $filter + ['liste' => $liste];
        if ($zeitraum !== null) {
            $ersetzen += [
                'zeitraum' => 'benutzerdefiniert',
                'von' => $zeitraum[0]->format('Y-m-d'),
                'bis' => $zeitraum[1]->format('Y-m-d'),
                'monat' => null,
            ];
        }

        return $this->alsQuery($ersetzen);
    }

    /** Anzeigename des Werber-Filters (Name aus der Datenbank, nie die ID). */
    public function werberName(): string
    {
        if ($this->werber === null) {
            return '';
        }
        if ($this->werber === 'keiner') {
            return 'Ohne Mitarbeiter/Vermittler';
        }
        $id = substr($this->werber, 2);
        $name = str_starts_with($this->werber, 'u:')
            ? User::whereKey((int) $id)->value('name')
            : Partner::whereKey($id)->value('name');

        return $name ?: 'Unbekannt';
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
