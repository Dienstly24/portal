<?php

namespace App\Support;

/**
 * Eine per OCR gelesene FIN, die in genau EINEM Zeichen von einer erfassten
 * abweicht (Betreiber-Foto 23.11.2026, KI-132): am Fahrzeugschein kam
 * ein "B" der FIN als "E" an. Die Pruefziffer allein hilft nicht - die
 * Korrektur eines Zeichens ergab dort VIER gueltige Kandidaten. Die FIN wird
 * deshalb nie selbst "repariert", sondern nur mit dem BESTAND verglichen:
 * eine erfasste FIN ist echt.
 *
 * Gilt nur fuer Zeichenpaare, die Tesseract nachweislich verwechselt.
 */
final class FinVerlesen
{
    /** Gegenseitig verwechselbare Zeichen (ohne I/O/Q - die gibt es in einer FIN nicht). */
    private const PAARE = [
        ['B', 'E'], ['B', '8'], ['B', 'R'], ['E', 'F'], ['D', '0'], ['S', '5'],
        ['Z', '2'], ['G', '6'], ['1', '7'], ['1', 'T'], ['7', 'T'], ['X', 'K'],
        ['U', 'V'], ['H', 'N'], ['M', 'N'], ['A', '4'],
    ];

    public static function einZeichen(string $gelesen, string $erfasst): bool
    {
        if (strlen($gelesen) !== 17 || strlen($erfasst) !== 17 || $gelesen === $erfasst) {
            return false;
        }
        $stellen = [];
        for ($i = 0; $i < 17; $i++) {
            if ($gelesen[$i] !== $erfasst[$i]) {
                $stellen[] = $i;
            }
        }
        if (count($stellen) !== 1) {
            return false;
        }
        $a = $gelesen[$stellen[0]];
        $b = $erfasst[$stellen[0]];
        foreach (self::PAARE as [$x, $y]) {
            if (($a === $x && $b === $y) || ($a === $y && $b === $x)) {
                return true;
            }
        }

        return false;
    }
}
