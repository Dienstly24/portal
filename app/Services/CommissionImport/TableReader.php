<?php

namespace App\Services\CommissionImport;

/**
 * Der EINE Einstieg zum Lesen einer Tabellendatei (Betreiber-Auftrag
 * 26.08.2026).
 *
 * ERKANNT WIRD AM INHALT, nicht an der Endung. Genau daran scheiterte der
 * bisherige Weg: eine Datei, die "Abrechnung.csv" heisst, aber in Wahrheit
 * eine .xlsx ist (Excel "Speichern unter" mit falschem Typ), oder umgekehrt
 * eine .xls, die in Wirklichkeit eine HTML-Tabelle enthaelt - beides kommt
 * aus Fremdsystemen regelmaessig an. Die Endung ist ein HINWEIS, die ersten
 * Bytes sind der Beweis.
 */
class TableReader
{
    public function __construct(
        private CsvTableReader $csv = new CsvTableReader,
        private XlsxTableReader $xlsx = new XlsxTableReader,
        private XlsTableReader $xls = new XlsTableReader,
    ) {
    }

    /** Erlaubte Endungen - fuer die Validierung im Controller. */
    public const EXTENSIONS = ['csv', 'txt', 'xlsx', 'xlsm', 'xls'];

    /**
     * Format der Datei am INHALT bestimmen.
     *
     * @return string 'xlsx' | 'xls' | 'csv'
     */
    public function detectFormat(string $path): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Die hochgeladene Datei konnte nicht geöffnet werden.');
        }
        $magic = (string) fread($handle, 8);
        fclose($handle);

        // "PK\x03\x04" = ZIP -> XLSX/XLSM (auch .ods, das hier aber bewusst
        // nicht unterstuetzt wird - es kaeme mit einer klaren Meldung).
        if (str_starts_with($magic, "PK\x03\x04")) {
            return 'xlsx';
        }
        if (str_starts_with($magic, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            return 'xls';
        }
        return 'csv';
    }

    /**
     * Datei einlesen.
     *
     * @param string|null $delimiter erzwungenes CSV-Trennzeichen
     * @param string|null $encoding erzwungene CSV-Kodierung
     * @param string|null $sheetName gewaehltes Excel-Blatt
     */
    public function read(string $path, ?string $delimiter = null, ?string $encoding = null, ?string $sheetName = null): TableFile
    {
        return match ($this->detectFormat($path)) {
            'xlsx' => $this->xlsx->read($path, $sheetName),
            'xls' => $this->xls->read($path, $sheetName),
            default => $this->csv->read($path, $delimiter, $encoding),
        };
    }

    /**
     * Blattnamen einer Excel-Datei. Bei CSV eine leere Liste - dort gibt es
     * kein Blatt, und ein erfundenes "Tabelle1" waere eine Unwahrheit in der
     * Dateierkennung.
     *
     * @return array<int,string>
     */
    public function sheetNames(string $path): array
    {
        return match ($this->detectFormat($path)) {
            'xlsx' => $this->xlsx->sheetNames($path),
            'xls' => $this->xls->sheetNames($path),
            default => [],
        };
    }
}
