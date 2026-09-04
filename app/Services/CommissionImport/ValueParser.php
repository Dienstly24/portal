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
        if ($clean === '' || ! preg_match('/[0-9]/', $clean)) {
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

        if (! is_numeric($clean)) {
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
        if ($value === '' || self::isEmptyDatePlaceholder($value)) {
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
     * Schreibweisen, mit denen Fremdsysteme "kein Datum" ausdruecken.
     *
     * WARUM DAS WICHTIG IST: Das Vertriebsportal schreibt ein fehlendes
     * Geburtsdatum als "00.00.0000". Wird das als kaputtes Datum gewertet,
     * faellt die GANZE Zeile als fehlerhaft aus - und mit ihr Name,
     * Anschrift und Vertrag, die vollstaendig da sind. Der Platzhalter ist
     * kein Fehler der Datei, sondern ihre Art, "nicht angegeben" zu sagen.
     * Ein wirklich VERSTUEMMELTES Datum wird weiterhin gemeldet.
     */
    private const EMPTY_DATES = [
        '00.00.0000', '0000-00-00', '00/00/0000', '0.0.0000',
        '-', '--', 'n/a', 'na', 'unbekannt', 'keine angabe', 'kein datum',
    ];

    public static function isEmptyDatePlaceholder(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), self::EMPTY_DATES, true);
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

    /**
     * Einzeilige Anschrift in ihre Teile zerlegen
     * ("Alte Kieler Landstr. 141, 24768 Rendsburg").
     *
     * Der ANKER ist die deutsche Postleitzahl (genau fuenf Ziffern als
     * eigenes Wort) - nicht das Komma: es fehlt in manchen Exporten, und
     * eine Strasse wie "Str. des 17. Juni 12" enthaelt selbst Ziffern.
     * Laesst sich die PLZ nicht finden, wird NICHTS zerlegt und die Zeile
     * bleibt als Ganzes stehen: eine halb erkannte Adresse ist schlechter
     * als eine unzerlegte.
     *
     * @return array{street:?string,house_number:?string,zip:?string,city:?string,raw:?string}
     */
    public static function address(?string $value): array
    {
        $raw = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
        $empty = ['street' => null, 'house_number' => null, 'zip' => null, 'city' => null, 'raw' => null];
        if ($raw === '') {
            return $empty;
        }

        if (! preg_match('/(?:^|[,\s])(\d{5})\s+(.+)$/u', $raw, $m, PREG_OFFSET_CAPTURE)) {
            return array_merge($empty, ['raw' => mb_substr($raw, 0, 190)]);
        }

        $zip = $m[1][0];
        $city = trim($m[2][0], ' ,');
        $before = trim(mb_strcut($raw, 0, $m[1][1]), ' ,');

        // Hausnummer = letzte Zahl (mit moeglichem Buchstaben) der Strasse.
        $street = $before;
        $houseNumber = null;
        if (preg_match('/^(.*?)[\s,]+(\d+\s*[a-zA-Z]?(?:\s*[-\/]\s*\d+\s*[a-zA-Z]?)?)$/u', $before, $h)) {
            $street = trim($h[1], ' ,');
            $houseNumber = trim($h[2]);
        }

        return [
            'street' => $street !== '' ? mb_substr($street, 0, 190) : null,
            'house_number' => $houseNumber !== null && $houseNumber !== '' ? mb_substr($houseNumber, 0, 20) : null,
            'zip' => $zip,
            'city' => $city !== '' ? mb_substr($city, 0, 190) : null,
            'raw' => mb_substr($raw, 0, 190),
        ];
    }

    /** Text kuerzen, ohne mitten in einem Mehrbyte-Zeichen zu schneiden. */
    public static function text(?string $value, int $max = 190): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
