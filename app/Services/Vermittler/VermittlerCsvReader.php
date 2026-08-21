<?php
namespace App\Services\Vermittler;

use League\Csv\Reader;
use League\Csv\Statement;

/**
 * Liest die Abrechnungs-CSV des Vermittlers.
 *
 * BEWUSST TOLERANT (Betreiber-Vorgabe: das Format des Vermittlers darf sich
 * nicht nach uns richten):
 *  - Trennzeichen ";" oder "," wird aus der Kopfzeile erkannt,
 *  - Spalten werden ueber SCHREIBWEISEN-Varianten gefunden, nicht ueber ihre
 *    Position (eine zusaetzliche Spalte verschiebt nichts),
 *  - "Referenz-Nr." DARF FEHLEN - dann traegt allein die `Id` die Zuordnung,
 *  - Zahlen kommen deutsch ("16,5") und werden nur fuer die Speicherung
 *    umgerechnet.
 */
class VermittlerCsvReader
{
    /** Spalte => moegliche Ueberschriften (klein, ohne Sonderzeichen). */
    private const COLUMNS = [
        'datum' => ['datum', 'date', 'abschlussdatum', 'verkaufsdatum'],
        'produkt' => ['produkt', 'product', 'tarif'],
        'vermittler_id' => ['id', 'vorgangsid', 'vorgangsnr', 'datensatzid'],
        'status' => ['status', 'statuscode'],
        'provision' => ['provision', 'verguetung', 'betrag'],
        'tracking_id' => ['trackingid', 'tracking'],
        'storno_reason' => ['stornogrund', 'storno', 'stornierungsgrund'],
        'reference_number' => ['referenznr', 'referenznummer', 'referenz', 'reference'],
    ];

    /**
     * @return array{header: array<string,int>, rows: array<int,array<string,?string>>, missing: array<int,string>}
     * @throws \RuntimeException wenn die Pflichtspalte `Id` fehlt.
     */
    public function read(string $path): array
    {
        return $this->readString((string) file_get_contents($path));
    }

    /**
     * Dieselbe Lesung auf einem bereits geladenen Inhalt - fuer Dateien, die
     * nicht als Pfad vorliegen (z.B. ein Dokument aus dem Dokumenten-Eingang,
     * das auf einer Storage-Disk liegt).
     *
     * @return array{header: array<string,int>, rows: array<int,array<string,?string>>, missing: array<int,string>}
     */
    public function readString(string $raw): array
    {
        // Der Vermittler liefert teils Latin-1. Ohne Umwandlung wuerden
        // Umlaute in Stornogruenden als Fragezeichen gespeichert.
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;

        $csv = Reader::createFromString($raw);
        $csv->setDelimiter($this->detectDelimiter($raw));
        $csv->setHeaderOffset(null);

        $records = iterator_to_array((new Statement())->process($csv), false);
        if ($records === []) {
            throw new \RuntimeException('Die Datei enthält keine Zeilen.');
        }

        $map = $this->mapHeader((array) array_shift($records));
        if (!isset($map['vermittler_id'])) {
            throw new \RuntimeException('Die Spalte "Id" fehlt in der Datei. Ohne sie lässt sich kein Datensatz zuordnen.');
        }

        $rows = [];
        foreach ($records as $record) {
            $record = array_values((array) $record);
            if (trim(implode('', $record)) === '') {
                continue; // Leerzeile am Dateiende
            }
            $row = [];
            foreach (self::COLUMNS as $key => $_) {
                $row[$key] = isset($map[$key]) ? trim((string) ($record[$map[$key]] ?? '')) : null;
            }
            $rows[] = $row;
        }

        return [
            'header' => $map,
            'rows' => $rows,
            'missing' => array_values(array_diff(array_keys(self::COLUMNS), array_keys($map))),
        ];
    }

    /** Deutsche Zahl ("1.234,56" / "16,5") als float - leer bleibt null. */
    public static function amount(?string $value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $clean = preg_replace('/[^0-9,.\-]/', '', $value) ?? '';
        // Deutsches Format: Punkt = Tausender, Komma = Dezimal.
        if (str_contains($clean, ',')) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        }
        return is_numeric($clean) ? round((float) $clean, 2) : null;
    }

    /** Datum aus der Datei - unlesbares Datum bleibt null (nie geraten). */
    public static function date(?string $value): ?\Illuminate\Support\Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d', 'd.m.Y H:i:s', 'd.m.Y', 'd/m/Y'] as $format) {
            try {
                $parsed = \Illuminate\Support\Carbon::createFromFormat($format, $value);
                if ($parsed !== false) {
                    return $parsed->startOfDay();
                }
            } catch (\Throwable) {
                // naechstes Format versuchen
            }
        }
        return null;
    }

    private function detectDelimiter(string $raw): string
    {
        $firstLine = strtok($raw, "\n") ?: '';
        return substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
    }

    /** @return array<string,int> Spaltenschluessel => Spaltenindex */
    private function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $index => $label) {
            $norm = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $label) ?? '');
            if ($norm === '') {
                continue;
            }
            foreach (self::COLUMNS as $key => $aliases) {
                if (!isset($map[$key]) && in_array($norm, $aliases, true)) {
                    $map[$key] = $index;
                    break;
                }
            }
        }
        return $map;
    }
}
