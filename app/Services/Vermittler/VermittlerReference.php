<?php
namespace App\Services\Vermittler;

/**
 * Normalisierung der Vermittler-Kennungen (Referenz-Nr. und Id).
 *
 * REGEL: Der ORIGINALWERT wird immer unveraendert gespeichert und angezeigt -
 * normalisiert wird ausschliesslich fuer den VERGLEICH. So bricht die
 * Zuordnung nicht an einem Leerzeichen, einem fehlenden Bindestrich oder
 * einer anderen Gross-/Kleinschreibung, ohne dass wir die gelieferte Angabe
 * verfaelschen.
 */
class VermittlerReference
{
    /** Zu kurze Kennungen treffen halbe Bestaende - sie werden nie verglichen. */
    public const MIN_LENGTH = 5;

    /**
     * Vergleichsschluessel: nur Buchstaben/Ziffern, Grossschreibung.
     * "1477-6741-9200-53" und "1477 6741 9200 53" ergeben denselben Wert.
     * Liefert null, wenn nichts Vergleichbares uebrig bleibt.
     */
    public static function key(?string $value): ?string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value) ?? '');
        if (strlen($clean) < self::MIN_LENGTH) {
            return null;
        }
        return substr($clean, 0, 60);
    }

    /**
     * Anzeigefassung: nur aeussere Leerzeichen weg, sonst unveraendert
     * (der Betreiber soll die Nummer so sehen, wie sie geliefert wurde).
     */
    public static function display(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : substr($trimmed, 0, 60);
    }

    /** Sind zwei Kennungen dieselbe? (null zaehlt nie als Treffer) */
    public static function same(?string $a, ?string $b): bool
    {
        $ka = self::key($a);
        $kb = self::key($b);
        return $ka !== null && $ka === $kb;
    }
}
