<?php
namespace App\Services\CommissionImport;

/**
 * Das Ergebnis des Einlesens einer Tabellendatei - unabhaengig davon, ob sie
 * als CSV, XLSX oder XLS vorlag. Genau dieser eine Typ macht den Rest des
 * Imports formatblind: Zuordnung, Pruefung und Uebernahme sehen nur noch
 * Kopfzeile und Zeilen.
 *
 * Die ERKENNUNGS-Angaben (Trennzeichen, Kodierung, Blattname) werden
 * mitgefuehrt, weil der Admin sie in der Vorschau sehen und - beim CSV -
 * korrigieren koennen muss. Eine Erkennung, die man nicht ueberstimmen kann,
 * ist bei fremden Dateien frueher oder spaeter ein Sackgassen-Fehler.
 */
class TableFile
{
    /**
     * @param array<int,string> $header Kopfzeile, wie sie in der Datei steht
     * @param array<int,array<int,string>> $rows Datenzeilen (ohne Kopfzeile)
     * @param array<int,string> $sheetNames alle Blaetter (nur Excel)
     */
    public function __construct(
        public readonly string $format,
        public readonly array $header,
        public readonly array $rows,
        public readonly ?string $delimiter = null,
        public readonly ?string $encoding = null,
        public readonly ?string $sheetName = null,
        public readonly array $sheetNames = [],
    ) {
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /** Zelle einer Zeile - fehlende Spalten sind leer, nie ein Fehler. */
    public static function cell(array $row, ?int $index): string
    {
        if ($index === null) {
            return '';
        }
        return trim((string) ($row[$index] ?? ''));
    }
}
