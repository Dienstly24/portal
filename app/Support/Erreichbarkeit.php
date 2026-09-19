<?php

namespace App\Support;

/**
 * Die EINE Stelle, die aus `config/website.php` einen lesbaren
 * Zeiten-Satz macht.
 *
 * Warum ueberhaupt eine Klasse fuer zwei Zeilen Text: die Zeiten standen
 * am 19.09.2026 bereits doppelt (Hamburg-Seite + `InsuranceAgency`-Schema)
 * und wurden zu EINER Quelle zusammengefuehrt. Dabei blieb eine DRITTE
 * Kopie unbemerkt stehen - fest verdrahtet im Kontaktblock der
 * Startseite. Eine gemeinsame Quelle fuer den WERT genuegt also nicht,
 * solange jede Vorlage ihre eigene FORMATIERUNG mitbringt: die naechste
 * neue Seite schreibt die Zeile wieder selbst ab.
 *
 * ES SIND ERREICHBARKEITS-ZEITEN, keine Oeffnungszeiten eines Ladens
 * (Betreiber-Klarstellung 19.09.2026: der Betrieb arbeitet ausschliesslich
 * online, es gibt keinen Besuchsbetrieb). Im schema.org-Block heissen sie
 * trotzdem `openingHoursSpecification` - ein anderes Feld kennt die
 * Vokabel nicht.
 */
class Erreichbarkeit
{
    /**
     * Tagesnamen in der schema.org-Schreibweise auf die Anzeige abbilden.
     * Bewusst nur die Tage, die als RAND einer Spanne vorkommen koennen -
     * ein unbekannter Tag liefert lieber nichts als einen falschen Namen.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const TAGE = [
        'Monday' => ['Montag', 'الاثنين'],
        'Tuesday' => ['Dienstag', 'الثلاثاء'],
        'Wednesday' => ['Mittwoch', 'الأربعاء'],
        'Thursday' => ['Donnerstag', 'الخميس'],
        'Friday' => ['Freitag', 'الجمعة'],
        'Saturday' => ['Samstag', 'السبت'],
        'Sunday' => ['Sonntag', 'الأحد'],
    ];

    /** @return array{tage: list<string>, von: string, bis: string} */
    public static function zeiten(): array
    {
        $roh = config('website.opening_hours');

        return [
            'tage' => array_values((array) ($roh['tage'] ?? [])),
            'von' => (string) ($roh['von'] ?? ''),
            'bis' => (string) ($roh['bis'] ?? ''),
        ];
    }

    /**
     * Lange Fassung fuer eine eigene Zeile:
     * "Montag bis Freitag, 09:00 - 18:00 Uhr".
     */
    public static function zeile(?bool $arabisch = null): string
    {
        return self::bauen($arabisch, kurz: false);
    }

    /**
     * Kurze Fassung fuer eine Aufzaehlung neben Telefon und E-Mail:
     * "Mo-Fr, 09:00 - 18:00 Uhr".
     */
    public static function kurz(?bool $arabisch = null): string
    {
        return self::bauen($arabisch, kurz: true);
    }

    private static function bauen(?bool $arabisch, bool $kurz): string
    {
        $ar = $arabisch ?? (app()->getLocale() === 'ar');
        $z = self::zeiten();

        if ($z['tage'] === [] || $z['von'] === '' || $z['bis'] === '') {
            return '';
        }

        $vonTag = self::TAGE[(string) reset($z['tage'])][$ar ? 1 : 0] ?? '';
        $bisTag = self::TAGE[(string) end($z['tage'])][$ar ? 1 : 0] ?? '';

        if ($vonTag === '' || $bisTag === '') {
            return '';
        }

        if ($kurz && ! $ar) {
            // Im Deutschen ist "Mo-Fr" die uebliche Kurzform; im
            // Arabischen gibt es keine etablierte Abkuerzung fuer
            // Wochentage - dort bleibt der ausgeschriebene Name.
            $vonTag = mb_substr($vonTag, 0, 2);
            $bisTag = mb_substr($bisTag, 0, 2);

            return $vonTag.'-'.$bisTag.', '.$z['von'].' – '.$z['bis'].' Uhr';
        }

        return $ar
            ? $vonTag.' – '.$bisTag.'، '.$z['von'].' – '.$z['bis']
            : $vonTag.' bis '.$bisTag.', '.$z['von'].' – '.$z['bis'].' Uhr';
    }
}
