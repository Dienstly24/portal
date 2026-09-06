<?php

namespace App\Services\Reporting;

use App\Models\Contract;
use App\Models\Customer;
use App\Support\Bundesland;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * DIE Zahlen der Berichtsseite (Betreiber-Auftrag 06.09.2026).
 *
 * WARUM EIN DIENST UND NICHT DER CONTROLLER: die Seite beantwortet ein
 * Dutzend Fragen ueber DENSELBEN Datenbestand mit DERSELBEN Auswahl. Lagen
 * die Abfragen im Controller, waere die Definition von "abgeschlossen",
 * "aktiv" oder "verlaengert" je Kachel neu formuliert - und damit frueher
 * oder spaeter unterschiedlich. Hier steht jede Definition genau einmal;
 * die Oberflaeche bekommt fertige Zahlen und rechnet selbst nichts.
 *
 * Das Ergebnis ist bewusst ein reines Array aus Skalaren und Listen: es
 * geht heute an eine Blade-Vorlage und kann morgen unveraendert als JSON
 * ueber eine API gehen, ohne dass die Oberflaeche die Struktur diktiert.
 *
 * ---------------------------------------------------------------------
 * DIE VIER DEFINITIONEN, AUF DENEN ALLES ANDERE STEHT
 *
 * 1. ABGESCHLOSSEN. Wann gilt ein Vertrag als "in diesem Monat gemacht"?
 *    Nicht am Anlagedatum im Portal - ein Vertrag vom Maerz, der im
 *    September importiert wird, waere sonst ein September-Abschluss.
 *    Massstab ist dieselbe Reihenfolge, die das Provisionsmanagement
 *    benutzt: Unterschrift -> Antrag -> Beginn -> Anlage. Damit meinen
 *    Berichte und Provisionsfristen denselben Zeitpunkt.
 *
 * 2. AKTIV. Ausschliesslich Contract::scopeCurrentlyActive() - nie ein
 *    Vergleich auf status === 'active'. Die Regel steht im Modell (siehe
 *    CLAUDE.md "AKTIV vs. HISTORIE"), diese Seite spiegelt sie nur.
 *
 * 3. VERLAENGERT. Der Bestand fuehrt kein Feld "verlaengert" - deutsche
 *    Vertraege verlaengern sich still. Gezaehlt wird deshalb, was
 *    beobachtbar ist: ein Vertrag, dessen Ablaufdatum IM ZEITRAUM lag und
 *    der danach WEITER AKTIV ist, hat sich verlaengert. Das ist eine
 *    Ableitung, keine Meldung des Versicherers - die Oberflaeche sagt das
 *    an der Kachel dazu.
 *
 * 4. VERTRAGSWERT. Summe der JAHRESBEITRAEGE (Contract::yearlyPremium()
 *    als SQL-Ausdruck). Einmalbeitraege haben per_year = 0 und gehen
 *    bewusst nicht ein - ein Saisonbeitrag ist kein Jahreswert. Es ist
 *    der Beitrag des KUNDEN, nicht unser Ertrag; die Kachel sagt das.
 * ---------------------------------------------------------------------
 */
class DashboardAnalyticsService
{
    /**
     * Abschluss-Zeitpunkt eines Vertrags als SQL-Ausdruck (Definition 1).
     * Als Konstante, damit jede Abfrage dieser Seite wortgleich denselben
     * Zeitpunkt meint.
     *
     * Das aeussere date() ist kein Schmuck: die Reihe mischt DATUMS-Spalten
     * (signing_date ...) mit einer ZEITSTEMPEL-Spalte (created_at). SQLite
     * vergleicht beides als Zeichenkette - '2026-09-01' liegt dort VOR
     * '2026-09-01 00:00:00', ein Abschluss am Monatsersten waere also aus
     * seinem eigenen Monat gefallen. Auf beiden Seiten auf das Datum
     * normiert, sagen SQLite (Tests) und MySQL (Betrieb) dasselbe.
     */
    private const ABSCHLUSS = 'date(COALESCE(contracts.signing_date, contracts.application_date, contracts.start_date, contracts.created_at))';

    /** @param array<int,mixed>|null $sichtbareKunden null = alle Kunden sichtbar */
    public function __construct(
        private readonly ?array $sichtbareKunden = null,
    ) {}

    /**
     * Alles, was die Seite braucht - in einem Durchgang.
     *
     * @return array{kpis:array,verlauf:array,sparten:array,karte:array,kunden:array,status:array,insights:array,zeitraum:array}
     */
    public function auswerten(AnalyticsFilters $f): array
    {
        $kpis = $this->kennzahlen($f);
        $sparten = $this->sparten($f);
        $karte = $this->karte($f);
        $kunden = $this->kundenstruktur($f);
        $status = $this->vertragsstatus($f);

        return [
            'zeitraum' => [
                'von' => $f->von->format('d.m.Y'),
                'bis' => $f->bis->format('d.m.Y'),
                'vergleich_von' => $f->vergleichVon()->format('d.m.Y'),
                'vergleich_bis' => $f->vergleichBis()->format('d.m.Y'),
            ],
            'kpis' => $kpis,
            'verlauf' => $this->verlauf($f),
            'sparten' => $sparten,
            'karte' => $karte,
            'kunden' => $kunden,
            'status' => $status,
            'insights' => $this->insights($f, $kpis, $sparten, $karte, $kunden, $status),
        ];
    }

    // =================================================================
    // Kennzahlen
    // =================================================================

    /**
     * Die Kachelzeile. Monat und Jahr sind FEST (ihre Ueberschrift nennt den
     * Zeitraum - sie duerften nicht etwas anderes zeigen), die uebrigen
     * Kacheln folgen dem gewaehlten Zeitraum. Die Sachfilter (Sparte,
     * Bundesland, Kundentyp, Status) wirken auf ALLE.
     */
    private function kennzahlen(AnalyticsFilters $f): array
    {
        $monatJetzt = $this->abschluesse($f, now()->startOfMonth(), now()->endOfMonth());
        $monatVor = $this->abschluesse($f, now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth());
        $jahrJetzt = $this->abschluesse($f, now()->startOfYear(), now()->endOfYear());
        $jahrVor = $this->abschluesse($f, now()->subYear()->startOfYear(), now()->subYear()->endOfYear());

        $kundenNeu = $this->neueKunden($f, $f->von, $f->bis);
        $kundenVor = $this->neueKunden($f, $f->vergleichVon(), $f->vergleichBis());

        $wert = $this->vertragswert($f, $f->von, $f->bis);
        $wertVor = $this->vertragswert($f, $f->vergleichVon(), $f->vergleichBis());

        $verlaengert = $this->verlaengerungen($f, $f->von, $f->bis);
        $beendet = $this->beendetImZeitraum($f, $f->von, $f->bis);
        $quote = ($verlaengert + $beendet) > 0 ? round($verlaengert / ($verlaengert + $beendet) * 100, 1) : null;

        $auslaufend = $this->auslaufend($f, 30);
        $ueberfaellig = $this->ueberfaellig($f);

        return [
            [
                'schluessel' => 'monat',
                'label' => 'Verträge diesen Monat',
                'wert' => $monatJetzt,
                'format' => 'zahl',
                'trend' => $this->trend($monatJetzt, $monatVor),
                'trend_text' => 'vs. Vormonat',
                'hinweis' => 'Abschlüsse im laufenden Kalendermonat',
                'zeitraum_fest' => true,
            ],
            [
                'schluessel' => 'jahr',
                'label' => 'Verträge dieses Jahr',
                'wert' => $jahrJetzt,
                'format' => 'zahl',
                'trend' => $this->trend($jahrJetzt, $jahrVor),
                'trend_text' => 'vs. Vorjahr',
                'hinweis' => 'Abschlüsse seit dem 1. Januar',
                'zeitraum_fest' => true,
            ],
            [
                'schluessel' => 'neukunden',
                'label' => 'Neue Kunden',
                'wert' => $kundenNeu,
                'format' => 'zahl',
                'trend' => $this->trend($kundenNeu, $kundenVor),
                'trend_text' => 'vs. Vorzeitraum',
                'hinweis' => 'Im gewählten Zeitraum angelegt',
            ],
            [
                'schluessel' => 'wert',
                'label' => 'Vertragswert',
                'wert' => $wert,
                'format' => 'euro',
                'trend' => $this->trend($wert, $wertVor),
                'trend_text' => 'vs. Vorzeitraum',
                'hinweis' => 'Jahresbeitrag der Abschlüsse im Zeitraum – Beitrag des Kunden, nicht Ertrag',
            ],
            [
                'schluessel' => 'verlaengerungen',
                'label' => 'Verlängerungen',
                'wert' => $verlaengert,
                'format' => 'zahl',
                'trend' => null,
                'trend_text' => $quote !== null
                    ? $this->zahl($quote, 1).' % Verlängerungsquote'
                    : 'Keine Abläufe im Zeitraum',
                'hinweis' => 'Ablauf im Zeitraum, Vertrag läuft weiter (stillschweigende Verlängerung)',
            ],
            [
                'schluessel' => 'auslaufend',
                'label' => 'Auslaufende Verträge',
                'wert' => $auslaufend,
                'format' => 'zahl',
                'trend' => null,
                'trend_text' => $ueberfaellig > 0
                    ? $ueberfaellig.' bereits überfällig'
                    : 'Keine überfälligen Verträge',
                'warnung' => $auslaufend > 0 || $ueberfaellig > 0,
                'hinweis' => 'Aktive Verträge mit Ablauf in den nächsten 30 Tagen',
            ],
        ];
    }

    /** Prozentuale Veraenderung - oder null, wenn es nichts zu vergleichen gibt. */
    private function trend(float $jetzt, float $vorher): ?array
    {
        if ($vorher <= 0) {
            // Ohne Basis ist jede Prozentangabe erfunden ("+100 %" waere
            // dasselbe fuer 1 wie fuer 1000). Dann lieber nichts sagen.
            return $jetzt > 0 ? ['richtung' => 'neu', 'prozent' => null] : null;
        }

        $delta = ($jetzt - $vorher) / $vorher * 100;

        return [
            'richtung' => $delta > 0.05 ? 'auf' : ($delta < -0.05 ? 'ab' : 'gleich'),
            'prozent' => round($delta, 1),
            'basis' => $vorher,
        ];
    }

    // =================================================================
    // Zeitreihe
    // =================================================================

    /**
     * Vertragsentwicklung ueber die Zeit: Neu, Verlaengert, Gekuendigt je
     * Periode - plus dieselbe Reihe der VORPERIODE als Vergleichslinie.
     *
     * Gruppiert wird in PHP ueber die geladenen Eckdaten, nicht per
     * SQL-Datumsfunktion: `DATE_FORMAT` (MySQL) und `strftime` (SQLite)
     * heissen unterschiedlich, und die Seite muss in beiden laufen (Tests
     * auf SQLite, Betrieb auf MySQL). Geladen werden nur zwei Datumsspalten
     * je Vertrag, keine Modelle.
     */
    private function verlauf(AnalyticsFilters $f): array
    {
        $perioden = $this->perioden($f);
        $erste = $perioden[0]['von'];
        $letzte = end($perioden)['bis'];
        $spanne = (int) round($erste->diffInDays($letzte)) + 1;
        $vorVon = $erste->copy()->subDays($spanne);

        // Ein Durchgang ueber alle relevanten Vertraege statt 3 Abfragen je
        // Periode (bei 12 Perioden waeren das 72 Abfragen).
        // toBase(): hier werden nur vier Eckdaten je Vertrag gebraucht. Als
        // Modelle geladen, waeren es Tausende Objekte samt Casts - und die
        // Aliase (`abschluss`) waeren dort ohnehin keine echten Eigenschaften.
        $zeilen = $this->vertragsbasis($f)
            ->selectRaw(self::ABSCHLUSS.' as abschluss')
            ->addSelect(['contracts.end_date', 'contracts.cancellation_date', 'contracts.status'])
            ->where(function ($w) use ($vorVon, $letzte) {
                $w->whereRaw(self::ABSCHLUSS.' between ? and ?', [$vorVon->toDateString(), $letzte->toDateString()])
                    ->orWhereBetween('contracts.end_date', [$vorVon->toDateString(), $letzte->toDateString()])
                    ->orWhereBetween('contracts.cancellation_date', [$vorVon->toDateString(), $letzte->toDateString()]);
            })
            ->toBase()->get();

        $leer = fn () => array_fill(0, count($perioden), 0);
        $neu = $leer();
        $verlaengert = $leer();
        $gekuendigt = $leer();
        $vergleich = $leer();

        foreach ($zeilen as $z) {
            $abschluss = $z->abschluss ? Carbon::parse($z->abschluss) : null;
            $ende = $z->end_date ? Carbon::parse($z->end_date) : null;
            $kuendigung = $z->cancellation_date ? Carbon::parse($z->cancellation_date) : null;
            $laueftNoch = in_array($z->status, Contract::ACTIVE_STATUSES, true) && $kuendigung === null;

            foreach ($perioden as $i => $p) {
                if ($abschluss && $abschluss->betweenIncluded($p['von'], $p['bis'])) {
                    $neu[$i]++;
                }
                if ($ende && $ende->betweenIncluded($p['von'], $p['bis']) && $laueftNoch) {
                    $verlaengert[$i]++;
                }
                if ($kuendigung && $kuendigung->betweenIncluded($p['von'], $p['bis'])) {
                    $gekuendigt[$i]++;
                }
                // Vergleichsreihe: derselbe Index, um eine Spanne nach hinten.
                $vp = ['von' => $p['von']->copy()->subDays($spanne), 'bis' => $p['bis']->copy()->subDays($spanne)];
                if ($abschluss && $abschluss->betweenIncluded($vp['von'], $vp['bis'])) {
                    $vergleich[$i]++;
                }
            }
        }

        return [
            'labels' => array_column($perioden, 'label'),
            'reihen' => [
                ['schluessel' => 'neu', 'label' => 'Neue Verträge', 'werte' => $neu],
                ['schluessel' => 'verlaengert', 'label' => 'Verlängerte Verträge', 'werte' => $verlaengert],
                ['schluessel' => 'gekuendigt', 'label' => 'Gekündigte Verträge', 'werte' => $gekuendigt],
            ],
            'vergleich' => ['label' => 'Neue Verträge (Vorperiode)', 'werte' => $vergleich],
            'granularitaet' => $f->granularitaet,
        ];
    }

    /** Die Perioden der Zeitachse - Anzahl und Zuschnitt je nach Takt. */
    private function perioden(AnalyticsFilters $f): array
    {
        $perioden = [];
        if ($f->granularitaet === 'jahr') {
            for ($i = 4; $i >= 0; $i--) {
                $von = now()->subYears($i)->startOfYear();
                $perioden[] = ['von' => $von, 'bis' => $von->copy()->endOfYear(), 'label' => $von->format('Y')];
            }
        } elseif ($f->granularitaet === 'quartal') {
            for ($i = 7; $i >= 0; $i--) {
                $von = now()->subQuarters($i)->startOfQuarter();
                $perioden[] = ['von' => $von, 'bis' => $von->copy()->endOfQuarter(), 'label' => 'Q'.$von->quarter.' '.$von->format('y')];
            }
        } else {
            $monate = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
            for ($i = 11; $i >= 0; $i--) {
                $von = now()->subMonthsNoOverflow($i)->startOfMonth();
                $perioden[] = ['von' => $von, 'bis' => $von->copy()->endOfMonth(), 'label' => $monate[$von->month - 1].' '.$von->format('y')];
            }
        }

        return $perioden;
    }

    // =================================================================
    // Sparten
    // =================================================================

    /** Verteilung des AKTIVEN Bestands auf die Sparten (absolut + Anteil). */
    private function sparten(AnalyticsFilters $f): array
    {
        $roh = $this->vertragsbasis($f)
            ->currentlyActive()
            ->selectRaw('contracts.type as type, count(*) as anzahl')
            ->groupBy('contracts.type')
            ->pluck('anzahl', 'type');

        $gesamt = (int) $roh->sum();

        return collect($roh)
            ->map(function ($anzahl, $type) use ($gesamt) {
                $cfg = Contract::TYPES[$type] ?? Contract::LEGACY_TYPES[$type] ?? null;

                return [
                    'schluessel' => $type,
                    'label' => $cfg['label'] ?? $type,
                    'farbe' => $cfg['color'] ?? '#5F5E5A',
                    'anzahl' => (int) $anzahl,
                    'anteil' => $gesamt > 0 ? round($anzahl / $gesamt * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('anzahl')
            ->values()->all();
    }

    // =================================================================
    // Karte
    // =================================================================

    /**
     * Kundenverteilung je Bundesland - abgeleitet aus der PLZ
     * (App\Support\Bundesland). Je Land: Kunden, neue Vertraege im
     * Zeitraum, Verlaengerungen und Vertragswert; das ist genau der
     * Inhalt des Tooltips.
     *
     * Aggregiert wird ueber die ersten DREI PLZ-Stellen - eine Gruppe je
     * Leitbereich statt eine Zeile je Kunde, und die Zuordnung zum Land
     * passiert dann einmal in PHP mit derselben Funktion, die auch der
     * Filter benutzt.
     */
    private function karte(AnalyticsFilters $f): array
    {
        $kunden = $this->kundenbasis($f)
            ->selectRaw('substr(customers.address_zip, 1, 3) as plz3, count(*) as anzahl')
            ->groupBy('plz3')->pluck('anzahl', 'plz3');

        $vertraege = $this->vertragsbasis($f)
            ->whereRaw(self::ABSCHLUSS.' between ? and ?', [$f->von->toDateString(), $f->bis->toDateString()])
            ->selectRaw('substr(customers.address_zip, 1, 3) as plz3, count(*) as anzahl, '.$this->jahresbeitragSql().' as wert')
            ->groupBy('plz3')->toBase()->get();

        $verlaengerungen = $this->vertragsbasis($f)
            ->whereBetween('contracts.end_date', [$f->von->toDateString(), $f->bis->toDateString()])
            ->whereIn('contracts.status', Contract::ACTIVE_STATUSES)
            ->whereNull('contracts.cancellation_date')
            ->selectRaw('substr(customers.address_zip, 1, 3) as plz3, count(*) as anzahl')
            ->groupBy('plz3')->pluck('anzahl', 'plz3');

        // ZUERST nur Zahlen summieren, DANN die Anzeigezeilen bauen. Vorher
        // wuchs dieselbe Struktur ueber eine Closure mit &-Referenz - das
        // vermischt Aufsummieren und Darstellen in einem Wert, den hinterher
        // weder ein Leser noch ein Werkzeug sicher beschreiben kann.
        $summen = [];
        foreach ([...Bundesland::kuerzel(), Bundesland::UNBEKANNT] as $schluessel) {
            $summen[$schluessel] = ['kunden' => 0, 'neue_vertraege' => 0, 'verlaengerungen' => 0, 'wert' => 0.0];
        }

        foreach ($kunden as $plz3 => $anzahl) {
            $this->addieren($summen, $plz3, 'kunden', (int) $anzahl);
        }
        foreach ($vertraege as $zeile) {
            $this->addieren($summen, $zeile->plz3, 'neue_vertraege', (int) $zeile->anzahl);
            $this->addieren($summen, $zeile->plz3, 'wert', round((float) $zeile->wert, 2));
        }
        foreach ($verlaengerungen as $plz3 => $anzahl) {
            $this->addieren($summen, $plz3, 'verlaengerungen', (int) $anzahl);
        }

        $gesamtKunden = (int) array_sum(array_column($summen, 'kunden'));

        $laender = [];
        $top = [];
        foreach (Bundesland::NAMEN as $kuerzel => $name) {
            $zeile = $summen[$kuerzel] + [
                'kuerzel' => $kuerzel,
                'name' => $name,
                'anteil' => $gesamtKunden > 0 ? round($summen[$kuerzel]['kunden'] / $gesamtKunden * 100, 1) : 0.0,
            ];
            $laender[$kuerzel] = $zeile;
            if ($zeile['kunden'] > 0) {
                $top[] = $zeile;
            }
        }

        // Die fuenf staerksten Laender - Laender ohne Kunden stehen bewusst
        // nicht in der Rangliste (ein Rang ohne Kunden ist kein Rang).
        usort($top, fn (array $a, array $b) => $b['kunden'] <=> $a['kunden']);

        return [
            'laender' => $laender,
            'max' => max(1, (int) max(array_column($summen, 'kunden'))),
            'top' => array_slice($top, 0, 5),
            'ohne_zuordnung' => $summen[Bundesland::UNBEKANNT]['kunden'],
            'gesamt' => $gesamtKunden,
        ];
    }

    /**
     * Einen Wert dem Bundesland seiner Leitzahl zuschlagen. Was sich nicht
     * zuordnen laesst, landet unter Bundesland::UNBEKANNT - nie anteilig auf
     * die Laender verteilt: die Karte soll nicht vollstaendiger aussehen als
     * der Datenbestand ist.
     *
     * @param  array<string,array<string,float|int>>  $summen
     */
    private function addieren(array &$summen, ?string $plz3, string $feld, float|int $wert): void
    {
        $land = Bundesland::ausPlz(((string) $plz3).'00');
        $summen[$land][$feld] += $wert;
    }

    // =================================================================
    // Kundenstruktur / Vertragsstatus
    // =================================================================

    private function kundenstruktur(AnalyticsFilters $f): array
    {
        $gesamt = (clone $this->kundenbasis($f))->count();
        $privat = (clone $this->kundenbasis($f))->where('customers.customer_type', 'privat')->count();
        $firma = (clone $this->kundenbasis($f))->where('customers.customer_type', 'firma')->count();
        $neu = $this->neueKunden($f, $f->von, $f->bis);

        return [
            'gesamt' => $gesamt,
            'gruppen' => [
                ['schluessel' => 'privat', 'label' => 'Privatkunden', 'wert' => $privat, 'basis' => $gesamt],
                ['schluessel' => 'firma', 'label' => 'Firmenkunden', 'wert' => $firma, 'basis' => $gesamt],
                ['schluessel' => 'neu', 'label' => 'Neue Kunden', 'wert' => $neu, 'basis' => $gesamt],
                ['schluessel' => 'bestand', 'label' => 'Bestandskunden', 'wert' => max(0, $gesamt - $neu), 'basis' => $gesamt],
            ],
            // Ohne erfassten Typ - ehrlich ausgewiesen statt still auf
            // "privat" gerechnet (die Summe muesste sonst nicht aufgehen).
            'ohne_typ' => max(0, $gesamt - $privat - $firma),
        ];
    }

    private function vertragsstatus(AnalyticsFilters $f): array
    {
        $aktiv = (clone $this->vertragsbasis($f))->currentlyActive()->count();
        $anbahnung = (clone $this->vertragsbasis($f))->inProgress()->count();
        $auslaufend = $this->auslaufend($f, 30);
        $verlaengert = $this->verlaengerungen($f, $f->von, $f->bis);
        $gekuendigt = (clone $this->vertragsbasis($f))
            ->whereBetween('contracts.cancellation_date', [$f->von->toDateString(), $f->bis->toDateString()])
            ->count();

        $basis = max(1, $aktiv + $anbahnung + $gekuendigt);

        return [
            'basis' => $basis,
            'zeilen' => [
                ['schluessel' => 'aktiv', 'label' => 'Aktiv', 'wert' => $aktiv, 'ton' => 'gut',
                    'hinweis' => 'Laufender Bestand'],
                ['schluessel' => 'auslaufend', 'label' => 'Auslaufend', 'wert' => $auslaufend, 'ton' => 'warnung',
                    'hinweis' => 'Ablauf in 30 Tagen'],
                ['schluessel' => 'verlaengert', 'label' => 'Verlängert', 'wert' => $verlaengert, 'ton' => 'info',
                    'hinweis' => 'Ablauf im Zeitraum, läuft weiter'],
                ['schluessel' => 'anbahnung', 'label' => 'In Bearbeitung', 'wert' => $anbahnung, 'ton' => 'neutral',
                    'hinweis' => 'Antrag noch nicht im Bestand'],
                ['schluessel' => 'gekuendigt', 'label' => 'Gekündigt', 'wert' => $gekuendigt, 'ton' => 'schlecht',
                    'hinweis' => 'Kündigung im Zeitraum eingereicht'],
            ],
        ];
    }

    // =================================================================
    // Insights
    // =================================================================

    /**
     * Beobachtungen im Klartext. JEDER Satz stammt aus einer der oben
     * berechneten Zahlen - es wird nichts hergeleitet, was nicht auch als
     * Zahl auf der Seite steht. Eine Aussage, die der Nutzer nicht
     * nachzaehlen kann, waere in einem Bericht wertlos.
     *
     * Ohne Datengrundlage entsteht KEIN Satz (leerer Abschnitt statt
     * Fuellsaetzen wie "keine Auffaelligkeiten").
     */
    private function insights(AnalyticsFilters $f, array $kpis, array $sparten, array $karte, array $kunden, array $status): array
    {
        $insights = [];
        $kpi = collect($kpis)->keyBy('schluessel');

        $monat = $kpi->get('monat');
        if ($monat && ($monat['trend']['prozent'] ?? null) !== null && abs($monat['trend']['prozent']) >= 1) {
            $auf = $monat['trend']['prozent'] > 0;
            $insights[] = [
                'ton' => $auf ? 'gut' : 'warnung',
                'text' => 'Die Zahl neuer Verträge ist im Vergleich zum Vormonat um '
                    .$this->zahl(abs($monat['trend']['prozent']), 1).' % '.($auf ? 'gestiegen' : 'gesunken')
                    .' ('.$monat['wert'].' statt '.(int) $monat['trend']['basis'].').',
            ];
        }

        if (($karte['top'][0] ?? null) && $karte['top'][0]['anteil'] > 0) {
            $spitze = $karte['top'][0];
            $insights[] = [
                'ton' => 'info',
                'text' => $spitze['name'].' hat mit '.$this->zahl($spitze['anteil'], 1).' % den höchsten Kundenanteil ('
                    .$spitze['kunden'].' von '.$karte['gesamt'].' Kunden).',
            ];
        }

        if (($sparten[0] ?? null) && $sparten[0]['anzahl'] > 0) {
            $insights[] = [
                'ton' => 'info',
                'text' => 'Die Sparte '.$sparten[0]['label'].' trägt mit '.$sparten[0]['anzahl']
                    .' Verträgen ('.$this->zahl($sparten[0]['anteil'], 1).' %) den größten Teil des aktiven Bestands.',
            ];
        }

        $auslaufend = $kpi->get('auslaufend');
        if ($auslaufend && $auslaufend['wert'] > 0) {
            $insights[] = [
                'ton' => 'warnung',
                'text' => $auslaufend['wert'].' aktive Verträge laufen in den nächsten 30 Tagen ab – '
                    .'jetzt ansprechen, solange die Kündigungsfrist noch läuft.',
            ];
        }

        if ($karte['ohne_zuordnung'] > 0 && $karte['gesamt'] > 0) {
            $anteil = round($karte['ohne_zuordnung'] / $karte['gesamt'] * 100, 1);
            if ($anteil >= 5) {
                $insights[] = [
                    'ton' => 'warnung',
                    'text' => 'Bei '.$karte['ohne_zuordnung'].' Kunden ('.$this->zahl($anteil, 1)
                        .' %) fehlt eine auswertbare Postleitzahl – sie fehlen in der Karte.',
                ];
            }
        }

        $firma = collect($kunden['gruppen'])->firstWhere('schluessel', 'firma');
        if ($firma && $kunden['gesamt'] > 0 && $firma['wert'] > 0) {
            $insights[] = [
                'ton' => 'info',
                'text' => 'Firmenkunden machen '.$this->zahl(round($firma['wert'] / $kunden['gesamt'] * 100, 1), 1)
                    .' % des Bestands aus ('.$firma['wert'].' von '.$kunden['gesamt'].').',
            ];
        }

        return array_slice($insights, 0, 5);
    }

    // =================================================================
    // Bausteine der Abfragen
    // =================================================================

    /**
     * Vertragsabfrage mit allen aktiven Filtern (immer mit Kunden-Join).
     *
     * Der generische Typ ist Pflicht, nicht Zierde: ohne ihn ist der
     * Rueckgabewert ein Builder ueber "irgendein Model", und weder die
     * statische Analyse noch die Entwicklungsumgebung kennen dann
     * `currentlyActive()` oder `statusGroup()` - also ausgerechnet die
     * Scopes, die die Definition von "aktiv" tragen.
     *
     * @return Builder<Contract>
     */
    private function vertragsbasis(AnalyticsFilters $f): Builder
    {
        $q = Contract::query()->join('customers', 'customers.id', '=', 'contracts.customer_id');

        if ($this->sichtbareKunden !== null) {
            $q->whereIn('contracts.customer_id', $this->sichtbareKunden);
        }
        if ($f->sparte !== null) {
            $q->where('contracts.type', $f->sparte);
        }
        if ($f->kundentyp !== null) {
            $q->where('customers.customer_type', $f->kundentyp);
        }
        if ($f->bundesland !== null) {
            Bundesland::filterQuery($q, $f->bundesland);
        }
        if ($f->vertragsstatus !== null) {
            $q->statusGroup($f->vertragsstatus);
        }

        return $q;
    }

    /**
     * Kundenabfrage mit den Filtern, die auf Kunden anwendbar sind.
     *
     * @return Builder<Customer>
     */
    private function kundenbasis(AnalyticsFilters $f): Builder
    {
        $q = Customer::query();

        if ($this->sichtbareKunden !== null) {
            $q->whereIn('customers.id', $this->sichtbareKunden);
        }
        if ($f->kundentyp !== null) {
            $q->where('customers.customer_type', $f->kundentyp);
        }
        if ($f->bundesland !== null) {
            Bundesland::filterQuery($q, $f->bundesland);
        }
        // Sparte/Status schraenken Kunden ueber IHRE Vertraege ein - sonst
        // zaehlte die Karte bei "Sparte: KFZ" weiterhin alle Kunden und die
        // Zahlen der Seite widersprächen sich.
        //
        // Als Unterabfrage statt whereHas('contracts', ...): der Filter laeuft
        // damit ueber denselben typisierten Vertrags-Builder wie alles andere
        // auf dieser Seite, `statusGroup()` ist die bekannte Scope-Methode und
        // nicht ein Aufruf auf "irgendeinem Model" im Inneren einer Closure.
        if ($f->sparte !== null || $f->vertragsstatus !== null) {
            $vertraege = Contract::query()->select('contracts.customer_id');
            if ($f->sparte !== null) {
                $vertraege->where('contracts.type', $f->sparte);
            }
            if ($f->vertragsstatus !== null) {
                $vertraege->statusGroup($f->vertragsstatus);
            }
            $q->whereIn('customers.id', $vertraege);
        }

        return $q;
    }

    private function abschluesse(AnalyticsFilters $f, Carbon $von, Carbon $bis): int
    {
        return (clone $this->vertragsbasis($f))
            ->whereRaw(self::ABSCHLUSS.' between ? and ?', [$von->toDateString(), $bis->toDateString()])
            ->count();
    }

    private function neueKunden(AnalyticsFilters $f, Carbon $von, Carbon $bis): int
    {
        return (clone $this->kundenbasis($f))
            ->whereBetween('customers.created_at', [$von, $bis])
            ->count();
    }

    private function vertragswert(AnalyticsFilters $f, Carbon $von, Carbon $bis): float
    {
        $summe = (clone $this->vertragsbasis($f))
            ->whereRaw(self::ABSCHLUSS.' between ? and ?', [$von->toDateString(), $bis->toDateString()])
            ->selectRaw($this->jahresbeitragSql().' as wert')
            ->value('wert');

        return round((float) $summe, 2);
    }

    /** Verlaengerungen nach Definition 3 (siehe Klassenkopf). */
    private function verlaengerungen(AnalyticsFilters $f, Carbon $von, Carbon $bis): int
    {
        return (clone $this->vertragsbasis($f))
            ->whereBetween('contracts.end_date', [$von->toDateString(), $bis->toDateString()])
            ->whereIn('contracts.status', Contract::ACTIVE_STATUSES)
            ->whereNull('contracts.cancellation_date')
            ->count();
    }

    /** Gegenstueck zur Verlaengerung: Ablauf im Zeitraum, Vertrag beendet. */
    private function beendetImZeitraum(AnalyticsFilters $f, Carbon $von, Carbon $bis): int
    {
        return (clone $this->vertragsbasis($f))
            ->whereBetween('contracts.end_date', [$von->toDateString(), $bis->toDateString()])
            ->where(function ($w) {
                $w->whereIn('contracts.status', Contract::HISTORIC_STATUSES)
                    ->orWhereNotNull('contracts.cancellation_date');
            })
            ->count();
    }

    private function auslaufend(AnalyticsFilters $f, int $tage): int
    {
        return (clone $this->vertragsbasis($f))
            ->whereNotNull('contracts.end_date')
            ->whereDate('contracts.end_date', '>=', now()->toDateString())
            ->whereDate('contracts.end_date', '<=', now()->addDays($tage)->toDateString())
            ->currentlyActive()
            ->count();
    }

    private function ueberfaellig(AnalyticsFilters $f): int
    {
        return (clone $this->vertragsbasis($f))
            ->whereNotNull('contracts.end_date')
            ->whereDate('contracts.end_date', '<', now()->toDateString())
            ->currentlyActive()
            ->count();
    }

    /**
     * Jahresbeitrag als SQL-Summe - der Query-Spiegel von
     * Contract::yearlyPremium(). Die Faktoren kommen aus derselben
     * Konstante wie in PHP, damit beide Wege nie auseinanderlaufen.
     */
    private function jahresbeitragSql(): string
    {
        $faelle = '';
        foreach (Contract::PREMIUM_INTERVALS as $schluessel => $cfg) {
            $faelle .= " when '".$schluessel."' then ".(int) $cfg['per_year'];
        }

        // Ohne erfasstes Intervall gilt - wie in yearlyPremium() - monatlich.
        return 'sum(coalesce(contracts.premium_amount, 0) * (case contracts.premium_interval'
            .$faelle.' else 12 end))';
    }

    /** Deutsche Zahlenformatierung fuer Fliesstext in den Insights. */
    private function zahl(float $wert, int $stellen = 0): string
    {
        return number_format($wert, $stellen, ',', '.');
    }
}
