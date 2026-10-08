<?php

namespace App\Support;

/**
 * Die EINE Stelle, die aus Strasse, Hausnummer und Zusatz eine Anschrift
 * zusammensetzt und vergleichbar macht (KI-069, PR-4 des Dubletten-Plans).
 *
 * Gemeldet war "Nagelshof 20 51": die Hausnummer stand in `address_street`
 * UND im eigenen Feld. Das Zusammensetzen haengte sie ungeprueft an - die
 * Anzeige war falsch, und der Haushalts-Schluessel derselben Anschrift wich
 * vom sauber erfassten Nachbarn ab. Steht dieselbe Nummer doppelt, wird sie
 * jetzt EINMAL gezeigt. Widersprechen sich beide (20 gegen 51), bleibt die
 * Zeile wie erfasst - geraten wird nie, welche stimmt; der Befehl
 * `kunden:anschriften-pruefen` listet diese Faelle fuer einen Menschen.
 */
final class Anschrift
{
    /**
     * Hausnummer am Ende der Strasse: "23", "21 b", "21b", "12-14", "12/1".
     * Dieselbe Form wie in ValidatesExtractedFields::splitStreetAndHouseNumber.
     * Vor der Nummer muss ein Buchstabe stehen ("Strasse des 17. Juni" endet
     * auf einen Buchstaben und wird deshalb nie zerlegt).
     */
    private const NUMMER_AM_ENDE = '/^(.*\p{L}.*?)[\s,]+(\d{1,4}\s?[a-zA-Z]?(?:\s*[-\/]\s*\d{1,4}\s?[a-zA-Z]?)?)$/u';

    /**
     * Strassenzeile aus den drei Feldern.
     *
     * @return array{zeile: string, fall: string} fall: 'sauber' | 'doppelt'
     *                                              | 'widerspruch' | 'nur_in_strasse'
     */
    public static function strassenzeile(?string $strasse, ?string $nummer, ?string $zusatz): array
    {
        $strasse = trim((string) $strasse);
        $nummer = trim((string) $nummer);
        $zusatz = trim((string) $zusatz);
        $nummerVoll = trim($nummer.($zusatz !== '' ? ' '.$zusatz : ''));

        $inStrasse = self::nummerInStrasse($strasse);

        if ($nummer === '') {
            return [
                'zeile' => trim($strasse.($zusatz !== '' ? ' '.$zusatz : '')),
                'fall' => $inStrasse !== null ? 'nur_in_strasse' : 'sauber',
            ];
        }
        if ($inStrasse === null) {
            return ['zeile' => trim($strasse.' '.$nummerVoll), 'fall' => 'sauber'];
        }

        [$strassenteil, $nummerAusStrasse] = $inStrasse;
        $n = self::nummernSchluessel($nummerAusStrasse);
        if ($n === self::nummernSchluessel($nummerVoll)) {
            // Dieselbe Nummer samt Zusatz steht schon in der Strasse.
            return ['zeile' => $strassenteil.' '.$nummerVoll, 'fall' => 'doppelt'];
        }
        if ($n === self::nummernSchluessel($nummer)) {
            // Nummer doppelt, der Zusatz nur im eigenen Feld ("Hauptstr. 5" + 5 + a).
            return ['zeile' => $strassenteil.' '.$nummerVoll, 'fall' => 'doppelt'];
        }

        // Zwei verschiedene Nummern: unveraendert lassen, nicht raten.
        return ['zeile' => trim($strasse.' '.$nummerVoll), 'fall' => 'widerspruch'];
    }

    /**
     * Vergleichsschluessel einer Anschrift: klein, Umlaute umgeschrieben,
     * "Straße"/"Strasse"/"Str." gleich, ohne Satz- und Leerzeichen.
     * "Hauptstraße 5, 24103 Kiel" und "Hauptstr. 5, 24103 Kiel" ergeben
     * denselben Schluessel.
     */
    public static function schluessel(string $anschrift): string
    {
        $s = mb_strtolower(trim($anschrift));
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = (string) preg_replace('/str(asse|\.)/', 'str', $s);

        return (string) preg_replace('/[^a-z0-9]+/', '', $s);
    }

    /** @return array{0: string, 1: string}|null [Strassenteil, Nummer] */
    private static function nummerInStrasse(string $strasse): ?array
    {
        if ($strasse === '' || ! preg_match(self::NUMMER_AM_ENDE, $strasse, $m)) {
            return null;
        }
        $teil = rtrim(trim($m[1]), ',');

        return $teil === '' ? null : [$teil, trim((string) preg_replace('/\s+/', ' ', $m[2]))];
    }

    private static function nummernSchluessel(string $nummer): string
    {
        return mb_strtolower((string) preg_replace('/[\s.\-\/]/', '', $nummer));
    }
}
