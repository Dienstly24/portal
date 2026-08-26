<?php
namespace App\Services\CommissionImport;

use Illuminate\Support\Carbon;

/**
 * Deutung einzelner Zellwerte aus fremden Dateien.
 *
 * GRUNDREGEL: Ein Wert, der nicht ZWEIFELSFREI lesbar ist, wird nicht
 * geraten - er wird null, und die Zeile meldet den Fehler. Ein falsch
 * geratener Betrag oder ein um Monate verschobenes Datum faellt im
 * Tagesgeschaeft nicht auf; eine gemeldete Zeile schon.
 */
class ValueParser
{
    /**
     * Zahl aus deutscher ODER englischer Schreibweise.
     *
     * Das Kernproblem: "1.234" ist deutsch tausendzweihundertvierunddreissig,
     * englisch aber eins Komma zwei drei vier. Entschieden wird deshalb am
     * LETZTEN Trennzeichen und an der Stellenzahl dahinter - nicht an einer
     * Spracheinstellung, die zur Datei gar nicht bekannt ist.
     */
    public static function amount(?string $value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $negative = str_contains($value, '-') || preg_match('/^\(.*\)$/', $value) === 1;
        $clean = preg_replace('/[^0-9,.]/', '', $value) ?? '';
        if ($clean === '' || !preg_match('/[0-9]/', $clean)) {
            return null;
        }

        $lastComma = strrpos($clean, ',');
        $lastDot = strrpos($clean, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // Beide vorhanden: das HINTERE ist das Dezimaltrennzeichen.
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $clean = str_replace($thousands, '', $clean);
            $clean = str_replace($decimal, '.', $clean);
        } elseif ($lastComma !== false) {
            // Nur Komma: Dezimaltrennzeichen, ausser es stehen genau drei
            // Ziffern dahinter UND es kommt mehrfach vor ("1,234,567").
            $clean = substr_count($clean, ',') > 1
                ? str_replace(',', '', $clean)
                : str_replace(',', '.', $clean);
        } elseif ($lastDot !== false) {
            // Nur Punkt: bei genau drei Nachkommastellen und keiner weiteren
            // Angabe ist es in deutschen Exporten ein Tausenderpunkt
            // ("1.234"), sonst ein Dezimalpunkt ("12.34").
            $after = strlen($clean) - $lastDot - 1;
            if ($after === 3 && substr_count($clean, '.') >= 1 && strlen($clean) > 4) {
                $clean = str_replace('.', '', $clean);
            }
        }

        if (!is_numeric($clean)) {
            return null;
        }
        $number = round((float) $clean, 2);
        return $negative ? -abs($number) : $number;
    }

    /**
     * Datum in den Schreibweisen, die in echten Exporten vorkommen.
     *
     * Bewusst KEIN `strtotime`: das deutet "01.02.2026" je nach Umgebung als
     * 1. Februar ODER als 2. Januar. Hier wird nur akzeptiert, was einem
     * bekannten Muster entspricht - alles andere ist ein gemeldeter Fehler.
     */
    public static function date(?string $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        // Excel-Seriennummer (wenn eine Datumsspalte als Zahl ankommt).
        if (preg_match('/^\d{5}(\.\d+)?$/', $value)) {
            $serial = (float) $value;
            if ($serial > 20000 && $serial < 80000) { // ~1954 bis ~2119
                $value = XlsxTableReader::excelDate($serial);
            }
        }

        $formats = [
            'd.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'd.m.y',
            'Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d',
            'd/m/Y', 'd-m-Y', 'Y/m/d',
        ];
        foreach ($formats as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }
            if ($parsed === false) {
                continue;
            }
            // createFromFormat akzeptiert auch ueberzaehlige Zeichen. Der
            // Rueckvergleich sorgt dafuer, dass "31.13.2026" nicht still zum
            // 31.01.2027 wird.
            if ($parsed->format($format) !== $value) {
                continue;
            }
            return $parsed->startOfDay();
        }
        return null;
    }

    /**
     * Waehrung: Kuerzel aus der Spalte oder aus dem Betrag ("1.234,00 EUR").
     * Ohne Angabe bleibt es beim Standard des Betriebs - eine erfundene
     * Fremdwaehrung waere schlimmer als eine unterstellte Hauswaehrung.
     */
    public static function currency(?string $value, string $default = 'EUR'): string
    {
        $value = strtoupper(trim((string) $value));
        if ($value === '') {
            return $default;
        }
        $symbols = ['€' => 'EUR', '$' => 'USD', '£' => 'GBP', 'CHF' => 'CHF'];
        foreach ($symbols as $symbol => $code) {
            if (str_contains($value, $symbol)) {
                return $code;
            }
        }
        return preg_match('/^[A-Z]{3}$/', $value) ? $value : $default;
    }

    /** Text kuerzen, ohne mitten in einem Mehrbyte-Zeichen zu schneiden. */
    public static function text(?string $value, int $max = 190): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
