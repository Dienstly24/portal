<?php

namespace App\Services\CommissionImport;

use League\Csv\Reader;
use League\Csv\Statement;

/**
 * CSV-Leser fuer FREMDE Dateien (Betreiber-Auftrag 26.08.2026).
 *
 * LEHRE aus den echten Dateien des Betriebs: drei Exporte, drei Bauformen -
 * eine deutsche Semikolon-Datei mit UTF-8-BOM (Maklerpool), eine
 * Semikolon-Datei in Latin-1 mit teils gequoteten Kopfzellen
 * (Energie-Vertriebsportal) und eine durchgehend gequotete Semikolon-Datei
 * (Vergleichsportal). Keine davon ist "kaputt" - sie sind einfach nicht
 * unsere. Deshalb wird ERKANNT statt vorausgesetzt:
 *  - Kodierung: UTF-8 (mit/ohne BOM), UTF-16, sonst Windows-1252,
 *  - Trennzeichen: ; , Tabulator |,
 * und beides ist in der Vorschau vom Admin ueberstimmbar.
 */
class CsvTableReader
{
    /** Kandidaten in der Reihenfolge, in der sie in DE-Exporten vorkommen. */
    public const DELIMITERS = [';', ',', "\t", '|'];

    /**
     * @param string|null $delimiter erzwungenes Trennzeichen (aus der Vorschau)
     * @param string|null $encoding erzwungene Kodierung (aus der Vorschau)
     */
    public function read(string $path, ?string $delimiter = null, ?string $encoding = null): TableFile
    {
        return $this->readString((string) file_get_contents($path), $delimiter, $encoding);
    }

    public function readString(string $raw, ?string $delimiter = null, ?string $encoding = null): TableFile
    {
        [$text, $detectedEncoding] = $this->toUtf8($raw, $encoding);
        $delimiter = $delimiter ?: $this->detectDelimiter($text);

        $csv = Reader::fromString($text);
        $csv->setDelimiter($delimiter);
        $csv->setHeaderOffset(null);

        $records = iterator_to_array((new Statement)->process($csv), false);
        // Vollstaendig leere Zeilen fliegen raus - Exporte enden gern mit
        // einer davon, und als "fehlerhafter Datensatz" waere sie ein
        // Fehlalarm in der Zusammenfassung.
        $records = array_values(array_filter(
            array_map(fn ($r) => array_map(fn ($v) => (string) $v, array_values((array) $r)), $records),
            fn ($r) => trim(implode('', $r)) !== ''
        ));

        if ($records === []) {
            throw new \RuntimeException('Die Datei enthält keine Zeilen.');
        }

        $header = array_map(fn ($v) => trim($v), (array) array_shift($records));

        return new TableFile(
            format: 'csv',
            header: $header,
            rows: $records,
            delimiter: $delimiter,
            encoding: $detectedEncoding,
        );
    }

    /**
     * Kodierung erkennen und nach UTF-8 wandeln.
     *
     * Reihenfolge ist Absicht: BOMs sind eindeutig und werden zuerst
     * geprueft. Erst danach entscheidet `mb_check_encoding` - denn JEDE
     * Latin-1-Datei besteht die UTF-8-Pruefung nicht, waehrend umgekehrt
     * jede UTF-8-Datei auch als Latin-1 "lesbar" waere (mit kaputten
     * Umlauten). Wer zuerst nach Latin-1 fragt, zerstoert gute Dateien.
     *
     * @return array{0:string,1:string} Text und Name der Kodierung
     */
    private function toUtf8(string $raw, ?string $forced = null): array
    {
        if ($forced !== null && $forced !== '') {
            $text = $this->stripBom($raw);
            if (strtoupper($forced) !== 'UTF-8') {
                $text = (string) mb_convert_encoding($text, 'UTF-8', $forced);
            }
            return [$text, $forced];
        }

        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return [substr($raw, 3), 'UTF-8 (BOM)'];
        }
        if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            $le = str_starts_with($raw, "\xFF\xFE");
            return [(string) mb_convert_encoding(substr($raw, 2), 'UTF-8', $le ? 'UTF-16LE' : 'UTF-16BE'), 'UTF-16'];
        }
        if (mb_check_encoding($raw, 'UTF-8')) {
            return [$raw, 'UTF-8'];
        }
        // Windows-1252 statt ISO-8859-1: es ist die Obermenge und deckt die
        // Zeichen ab, die Excel unter Windows tatsaechlich schreibt
        // (typografische Anfuehrungszeichen, Euro-Zeichen).
        return [(string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'), 'Windows-1252 / ISO-8859-1'];
    }

    private function stripBom(string $raw): string
    {
        return str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw;
    }

    /**
     * Trennzeichen aus den ersten Zeilen erkennen.
     *
     * Gezaehlt wird NUR AUSSERHALB von Anfuehrungszeichen. Ohne diese
     * Einschraenkung gewinnt in einer Datei mit Adressen ("Weinberggasse 8,
     * 94209 Regen") das Komma gegen das echte Semikolon - der Kopf zerfaellt
     * dann in Bruchstuecke und nichts wird zugeordnet.
     */
    public function detectDelimiter(string $text): string
    {
        $lines = array_slice(preg_split('/\r\n|\r|\n/', $text) ?: [], 0, 5);
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));
        if ($lines === []) {
            return ';';
        }

        $best = ';';
        $bestScore = -1;
        foreach (self::DELIMITERS as $candidate) {
            $counts = array_map(fn ($line) => $this->countOutsideQuotes($line, $candidate), $lines);
            $first = $counts[0] ?? 0;
            if ($first < 1) {
                continue;
            }
            // Ein echtes Trennzeichen kommt in JEDER Zeile gleich oft vor.
            // Diese Gleichmaessigkeit ist das verlaesslichere Signal als die
            // blosse Haeufigkeit.
            $consistent = count(array_filter($counts, fn ($c) => $c === $first));
            $score = $consistent * 1000 + $first;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }
        return $best;
    }

    private function countOutsideQuotes(string $line, string $needle): int
    {
        $count = 0;
        $inQuotes = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $char = $line[$i];
            if ($char === '"') {
                $inQuotes = ! $inQuotes;
                continue;
            }
            if (! $inQuotes && $char === $needle) {
                $count++;
            }
        }
        return $count;
    }
}
