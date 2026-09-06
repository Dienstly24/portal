<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * BUNDESLAND AUS DER POSTLEITZAHL (Betreiber-Auftrag 06.09.2026).
 *
 * WARUM ABGELEITET UND NICHT GESPEICHERT: die Kundenakte fuehrt kein Feld
 * "Bundesland" - sie fuehrt Strasse, Hausnummer, PLZ und Ort, weil genau das
 * auf Ausweis, Meldebestaetigung und Versicherungsschein steht. Ein neues
 * Pflichtfeld haette den gesamten Bestand rueckwirkend leer gelassen und die
 * Karte damit fuer die vorhandenen Kunden wertlos gemacht. Die PLZ steht
 * dagegen bereits an fast jedem Datensatz.
 *
 * DIE ZUORDNUNG IST NAEHERUNGSWEISE - und das wird in der Oberflaeche auch
 * so gesagt. Die deutschen Leitzonen folgen den Landesgrenzen NICHT exakt:
 * einzelne Zustellbereiche reichen ueber eine Landesgrenze (klassisch die
 * Umgebung von Ulm/Neu-Ulm oder der Raum Hanau). Fuer eine Verteilungs-
 * karte ist das ohne Belang, fuer eine amtliche Aussage waere es zu wenig -
 * deshalb steht unter der Karte ausdruecklich "aus der PLZ abgeleitet".
 *
 * KEINE ERFUNDENE ZUORDNUNG: was sich nicht eindeutig zuordnen laesst (keine
 * PLZ erfasst, ungueltige Laenge, Auslandsanschrift), wird NICHT auf ein
 * Land geraten, sondern als "unbekannt" gefuehrt und getrennt ausgewiesen.
 * Eine still verteilte Restmenge wuerde die Karte glaubwuerdiger aussehen
 * lassen, als die Datenlage ist.
 *
 * SCHLUESSEL sind die amtlichen Kuerzel nach ISO 3166-2:DE (BW, BY, ...) -
 * dieselben, die die Karte in
 * resources/views/admin/partials/analytics/germany_map.blade.php als
 * `data-land` traegt.
 */
class Bundesland
{
    /** Kuerzel -> Anzeigename. Reihenfolge = amtliche Reihenfolge. */
    public const NAMEN = [
        'BW' => 'Baden-Württemberg',
        'BY' => 'Bayern',
        'BE' => 'Berlin',
        'BB' => 'Brandenburg',
        'HB' => 'Bremen',
        'HH' => 'Hamburg',
        'HE' => 'Hessen',
        'MV' => 'Mecklenburg-Vorpommern',
        'NI' => 'Niedersachsen',
        'NW' => 'Nordrhein-Westfalen',
        'RP' => 'Rheinland-Pfalz',
        'SL' => 'Saarland',
        'SN' => 'Sachsen',
        'ST' => 'Sachsen-Anhalt',
        'SH' => 'Schleswig-Holstein',
        'TH' => 'Thüringen',
    ];

    /** Kennung fuer "nicht zuzuordnen" (keine/ungueltige/auslaendische PLZ). */
    public const UNBEKANNT = 'unbekannt';

    /**
     * Zuordnung ueber die ersten ZWEI Stellen der PLZ (Leitregion).
     * Eine Leitregion liegt fast immer vollstaendig in einem Land; wo sie
     * geteilt ist, entscheidet die dritte Stelle - diese Faelle stehen in
     * FEINZUORDNUNG und werden VOR dieser Tabelle geprueft.
     *
     * @var array<string,string>
     */
    private const LEITREGION = [
        '01' => 'SN', '02' => 'SN', '03' => 'BB', '04' => 'SN', '06' => 'ST',
        '07' => 'TH', '08' => 'SN', '09' => 'SN',
        '10' => 'BE', '12' => 'BE', '13' => 'BE', '14' => 'BB', '15' => 'BB',
        '16' => 'BB', '17' => 'MV', '18' => 'MV', '19' => 'MV',
        '20' => 'HH', '21' => 'NI', '22' => 'HH', '23' => 'SH', '24' => 'SH',
        '25' => 'SH', '26' => 'NI', '27' => 'NI', '28' => 'HB', '29' => 'NI',
        '30' => 'NI', '31' => 'NI', '32' => 'NW', '33' => 'NW', '34' => 'HE',
        '35' => 'HE', '36' => 'HE', '37' => 'NI', '38' => 'NI', '39' => 'ST',
        '40' => 'NW', '41' => 'NW', '42' => 'NW', '44' => 'NW', '45' => 'NW',
        '46' => 'NW', '47' => 'NW', '48' => 'NW', '49' => 'NI',
        '50' => 'NW', '51' => 'NW', '52' => 'NW', '53' => 'NW', '54' => 'RP',
        '55' => 'RP', '56' => 'RP', '57' => 'NW', '58' => 'NW', '59' => 'NW',
        '60' => 'HE', '61' => 'HE', '63' => 'HE', '64' => 'HE', '65' => 'HE',
        '66' => 'SL', '67' => 'RP', '68' => 'BW', '69' => 'BW',
        '70' => 'BW', '71' => 'BW', '72' => 'BW', '73' => 'BW', '74' => 'BW',
        '75' => 'BW', '76' => 'BW', '77' => 'BW', '78' => 'BW', '79' => 'BW',
        '80' => 'BY', '81' => 'BY', '82' => 'BY', '83' => 'BY', '84' => 'BY',
        '85' => 'BY', '86' => 'BY', '87' => 'BY', '88' => 'BW', '89' => 'BW',
        '90' => 'BY', '91' => 'BY', '92' => 'BY', '93' => 'BY', '94' => 'BY',
        '95' => 'BY', '96' => 'BY', '97' => 'BY', '98' => 'TH', '99' => 'TH',
    ];

    /**
     * Leitregionen, die ueber eine Landesgrenze reichen - hier entscheidet
     * die dritte Stelle. Bewusst KURZ gehalten: aufgenommen sind nur die
     * Faelle, in denen eine ganze Zehnergruppe eindeutig im Nachbarland
     * liegt. Alles Feinere waere ein PLZ-Verzeichnis, kein Kartenschluessel.
     *
     * @var array<string,string>
     */
    private const FEINZUORDNUNG = [
        // 63x: Untermain - 630xx-636xx Hessen, 637xx-639xx Bayern (Aschaffenburg).
        '637' => 'BY', '638' => 'BY', '639' => 'BY',
        // 65x: 650xx-654xx Hessen (Wiesbaden), 655xx-659xx Rheinland-Pfalz.
        '655' => 'RP', '656' => 'RP', '657' => 'RP', '658' => 'RP', '659' => 'RP',
        // 68x: 680xx-687xx Baden-Wuerttemberg, 688xx-689xx Rheinland-Pfalz.
        '688' => 'RP', '689' => 'RP',
        // 89x: Ulm und der Alb-Donau-Kreis (890xx/891xx) sowie Heidenheim
        // und Ehingen (895xx/896xx) sind Baden-Wuerttemberg; Neu-Ulm,
        // Guenzburg und Dillingen (892xx-894xx) liegen in Bayern.
        '892' => 'BY', '893' => 'BY', '894' => 'BY',
        // 96x: 960xx-968xx Bayern, 969xx Thueringen (Sonneberg).
        '969' => 'TH',
        // 37x: 370xx-379xx Niedersachsen (Goettingen), 372xx teils Thueringen.
        '372' => 'TH',
    ];

    /**
     * Bundesland-Kuerzel zu einer Postleitzahl - oder self::UNBEKANNT.
     *
     * Geraten wird nie: eine PLZ, die nicht aus genau fuenf Ziffern besteht
     * oder deren Leitregion in keiner Tabelle steht, bleibt unbekannt.
     */
    public static function ausPlz(?string $plz): string
    {
        $ziffern = preg_replace('/\D/', '', (string) $plz);
        if (strlen((string) $ziffern) !== 5) {
            return self::UNBEKANNT;
        }

        $drei = substr($ziffern, 0, 3);
        if (isset(self::FEINZUORDNUNG[$drei])) {
            return self::FEINZUORDNUNG[$drei];
        }

        return self::LEITREGION[substr($ziffern, 0, 2)] ?? self::UNBEKANNT;
    }

    /** Anzeigename zu einem Kuerzel (auch fuer self::UNBEKANNT). */
    public static function name(string $kuerzel): string
    {
        return self::NAMEN[$kuerzel] ?? 'Ohne Bundesland';
    }

    /** Gueltige Kuerzel (Validierungs-Whitelist fuer den Filter). */
    public static function kuerzel(): array
    {
        return array_keys(self::NAMEN);
    }

    /**
     * PLZ-Praefixe eines Bundeslandes, aufgeteilt fuer eine SQL-Bedingung:
     *   [ zwei => ['01','02',...], plus => ['637',...], minus => ['688',...] ]
     *
     * Lesart: eine PLZ gehoert zum Land, wenn ihre ersten drei Stellen in
     * `plus` stehen ODER ihre ersten zwei Stellen in `zwei` und ihre ersten
     * drei NICHT in `minus`. Die Ausnahmen (FEINZUORDNUNG) sind damit in
     * BEIDE Richtungen beruecksichtigt - eine Bedingung, die nur `zwei`
     * kennt, wuerde Aschaffenburg zu Hessen zaehlen und in der bayerischen
     * Karte fehlen lassen.
     */
    public static function praefixe(string $kuerzel): array
    {
        // ZEICHENKETTEN, nicht Zahlen. PHP macht aus dem Array-Schluessel
        // '50' still den INTEGER 50 (nur '01' behaelt wegen der fuehrenden
        // Null seinen Typ). Gingen diese Werte als Zahlen in ein whereIn,
        // verglichen SQLite und MySQL sie mit dem TEXT-Ergebnis von substr()
        // - und kein einziger Kunde aus Koeln waere je in Nordrhein-Westfalen
        // gelandet, ohne dass irgendwo ein Fehler erschiene.
        $text = fn (array $schluessel) => array_map('strval', $schluessel);

        return [
            'zwei' => $text(array_keys(array_filter(self::LEITREGION, fn ($k) => $k === $kuerzel))),
            'plus' => $text(array_keys(array_filter(self::FEINZUORDNUNG, fn ($k) => $k === $kuerzel))),
            'minus' => $text(array_keys(array_filter(
                self::FEINZUORDNUNG,
                fn ($k, $drei) => $k !== $kuerzel
                    && (self::LEITREGION[substr((string) $drei, 0, 2)] ?? null) === $kuerzel,
                ARRAY_FILTER_USE_BOTH
            ))),
        ];
    }

    /**
     * Einen Query auf ein Bundesland einschraenken (Spalte mit der PLZ).
     * Wird von Kunden- UND Vertragsabfragen benutzt, damit der Kartenfilter
     * ueberall dieselbe Menge meint.
     */
    public static function filterQuery($query, string $kuerzel, string $spalte = 'customers.address_zip')
    {
        // Der Spaltenname geht in ein DB::raw() - deshalb nur erlauben, was
        // wir selbst vergeben. Nutzereingaben erreichen diesen Parameter nie,
        // aber eine Rohspalte ohne Whitelist ist eine offene Tuer fuer den
        // naechsten Aufrufer.
        if (! in_array($spalte, ['customers.address_zip', 'address_zip'], true)) {
            throw new \InvalidArgumentException('Unzulaessige PLZ-Spalte: '.$spalte);
        }

        $p = self::praefixe($kuerzel);

        return $query->where(function ($w) use ($p, $spalte) {
            if ($p['plus'] !== []) {
                $w->whereIn(DB::raw("substr({$spalte}, 1, 3)"), $p['plus']);
            }
            $w->orWhere(function ($z) use ($p, $spalte) {
                $z->whereIn(DB::raw("substr({$spalte}, 1, 2)"), $p['zwei']);
                if ($p['minus'] !== []) {
                    $z->whereNotIn(DB::raw("substr({$spalte}, 1, 3)"), $p['minus']);
                }
            });
        });
    }
}
