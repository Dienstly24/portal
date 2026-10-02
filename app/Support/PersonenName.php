<?php

namespace App\Support;

/**
 * Namenspartikel an den NACHNAMEN binden.
 *
 * DAS PROBLEM: alle Vorlagen-Parser teilen einen Namen nach derselben Regel
 * "letztes Wort = Nachname". Fuer "Max Mustermann" stimmt sie. Fuer
 * "Yusuf Al Rahman" nicht: daraus wurde der Vorname "Yusuf Al" und der
 * Nachname "Rahman". Der Artikel "Al" gehoert zum Nachnamen, nicht zum
 * Vornamen - "Al Rahman" ist der Familienname. Dasselbe bei "Jan van der
 * Berg", "Ahmed Abu Bakr", "Khalid bin Walid", "Jean Le Blanc".
 *
 * Das trifft ausgerechnet den Kundenstamm dieses Betriebs besonders oft, und
 * es faellt kaum auf: der Datensatz sieht vollstaendig aus. Erst bei der
 * Anrede im Schreiben ("Sehr geehrter Herr Rahman") und beim Abgleich mit
 * einem zweiten Dokument ("Al Rahman") faellt es auseinander.
 *
 * WO DIE REGEL STEHT: an EINER Stelle, naemlich in `ValidatesExtractedFields`
 * - der Stelle, durch die JEDE Quelle laeuft (23 Parser, KI-Antwort,
 * OCR-Heuristik). Dieselbe Entscheidung wie bei der Hausnummer-Trennung; sie
 * in 23 Parser zu kopieren hiesse, sie 23-mal pflegen zu muessen.
 *
 * KONSERVATIV: verschoben wird nur, was am ENDE des Vornamens steht und in
 * der Liste steht - und NIE der ganze Vorname. "Al Pacino" bleibt deshalb
 * "Al" + "Pacino": bliebe kein Vorname uebrig, wird nichts geaendert.
 */
final class PersonenName
{
    /**
     * Partikel, die zum Nachnamen gehoeren, wenn sie unmittelbar davor stehen.
     *
     * BEWUSST NICHT dabei: "abd"/"abdul". Sie sind in aller Regel Teil des
     * VORnamens ("Abdul Rahman" als Rufname) - sie hier aufzunehmen hiesse,
     * einen Vornamen zu zerschneiden. Im Zweifel bleibt der Name, wie er ist.
     *
     * @var list<string>
     */
    public const PARTIKEL = [
        // arabisch (Artikel und Abstammung)
        'al', 'el', 'ul', 'ad', 'ar', 'as', 'ash', 'at', 'az',
        'abu', 'abou', 'aby', 'bin', 'ben', 'bint', 'ibn',
        // niederlaendisch/deutsch
        'van', 'von', 'vom', 'ter', 'ten', 'op', 'zu', 'zur', 'zum',
        'der', 'den', 'des', 'dem',
        // romanisch
        'de', 'del', 'della', 'di', 'da', 'dos', 'du', 'la', 'le', 'lo',
    ];

    /**
     * Schiebt Partikel vom Ende des Vornamens an den Anfang des Nachnamens.
     *
     * @return array{0: ?string, 1: ?string} [Vorname, Nachname]
     */
    public static function teile(?string $vorname, ?string $nachname): array
    {
        if ($vorname === null || $nachname === null) {
            return [$vorname, $nachname];
        }
        $vorTeile = preg_split('/\s+/u', trim($vorname)) ?: [];
        $vorTeile = array_values(array_filter($vorTeile, static fn ($t) => $t !== ''));
        if (count($vorTeile) < 2) {
            // Ein einziges Wort bleibt der Vorname - sonst entstuende aus
            // "Al Pacino" ein Mensch ohne Vornamen.
            return [$vorname, $nachname];
        }

        $verschoben = [];
        // Von hinten nach vorn, aber nie bis zum letzten verbliebenen Wort.
        while (count($vorTeile) > 1 && self::istPartikel(end($vorTeile))) {
            array_unshift($verschoben, (string) array_pop($vorTeile));
        }
        if ($verschoben === []) {
            return [$vorname, $nachname];
        }

        return [
            implode(' ', $vorTeile),
            implode(' ', $verschoben).' '.trim($nachname),
        ];
    }

    private static function istPartikel(string $wort): bool
    {
        $wort = mb_strtolower(trim($wort, " \t\n\r\0\x0B.,"));

        return $wort !== '' && in_array($wort, self::PARTIKEL, true);
    }
}
