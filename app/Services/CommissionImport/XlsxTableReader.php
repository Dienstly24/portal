<?php

namespace App\Services\CommissionImport;

/**
 * XLSX-Leser ohne Fremdpaket (Betreiber-Auftrag 26.08.2026).
 *
 * WARUM SELBST GEBAUT: eine XLSX-Datei ist ein ZIP mit XML darin - PHP bringt
 * ZipArchive und XMLReader mit. Ein Tabellen-Framework nur zum LESEN einiger
 * Spalten waere ein grosses Fremdpaket im Sicherheitsupdate-Pfad einer
 * Anwendung, die Kundendaten haelt. Geschrieben wird hier nichts, gerechnet
 * wird nichts - es werden nur Zellwerte gelesen.
 *
 * BEWUSSTE GRENZEN (und warum sie unschaedlich sind): Formeln werden mit
 * ihrem ZULETZT BERECHNETEN Wert gelesen (den Excel mitspeichert), nicht neu
 * ausgewertet - fuer einen Abrechnungs-Export ist genau das richtig, dort
 * steht das Ergebnis. Formatierungen, Bilder und Diagramme werden ignoriert.
 */
class XlsxTableReader
{
    /** Eingebaute Zahlenformate, die ein DATUM bedeuten (ECMA-376). */
    private const BUILTIN_DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47];

    /** @return array<int,string> Blattnamen in der Reihenfolge der Datei */
    public function sheetNames(string $path): array
    {
        $zip = $this->open($path);
        try {
            return array_column($this->sheets($zip), 'name');
        } finally {
            $zip->close();
        }
    }

    /**
     * Ein Blatt lesen. Ohne Angabe wird das ERSTE genommen - der Admin kann
     * in der Vorschau wechseln, denn "das erste Blatt ist das richtige" ist
     * bei fremden Dateien schlicht nicht wahr (Deckblatt, Legende, Filter).
     */
    public function read(string $path, ?string $sheetName = null): TableFile
    {
        $zip = $this->open($path);
        try {
            $sheets = $this->sheets($zip);
            if ($sheets === []) {
                throw new \RuntimeException('Die Excel-Datei enthält kein Tabellenblatt.');
            }
            $names = array_column($sheets, 'name');

            $sheet = null;
            if ($sheetName !== null && $sheetName !== '') {
                foreach ($sheets as $candidate) {
                    if ($candidate['name'] === $sheetName) {
                        $sheet = $candidate;
                        break;
                    }
                }
                if ($sheet === null) {
                    throw new \RuntimeException('Das Tabellenblatt "'.$sheetName.'" wurde in der Datei nicht gefunden.');
                }
            }
            $sheet ??= $sheets[0];

            $strings = $this->sharedStrings($zip);
            $dateStyles = $this->dateStyles($zip);
            $rows = $this->rows($zip, $sheet['path'], $strings, $dateStyles);

            $rows = array_values(array_filter($rows, fn ($r) => trim(implode('', $r)) !== ''));
            if ($rows === []) {
                throw new \RuntimeException('Das Tabellenblatt "'.$sheet['name'].'" enthält keine Zeilen.');
            }
            $header = array_map(fn ($v) => trim((string) $v), (array) array_shift($rows));

            return new TableFile(
                format: 'xlsx',
                header: $header,
                rows: $rows,
                encoding: 'UTF-8',
                sheetName: $sheet['name'],
                sheetNames: $names,
            );
        } finally {
            $zip->close();
        }
    }

    private function open(string $path): \ZipArchive
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Excel-Dateien können nicht gelesen werden: die PHP-Erweiterung "zip" fehlt auf dem Server. Bitte die Datei als CSV speichern.');
        }
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Die Datei konnte nicht geöffnet werden. Ist es wirklich eine .xlsx-Datei?');
        }
        return $zip;
    }

    /**
     * Blattnamen samt Dateipfad. Die Zuordnung Name -> Datei laeuft ueber die
     * Beziehungs-Datei; die verbreitete Abkuerzung "sheet1.xml ist das erste
     * Blatt" stimmt nicht, sobald in Excel ein Blatt geloescht oder
     * verschoben wurde.
     *
     * @return array<int,array{name:string,path:string}>
     */
    private function sheets(\ZipArchive $zip): array
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        if ($workbook === false) {
            throw new \RuntimeException('Die Datei ist keine gültige Excel-Arbeitsmappe (xl/workbook.xml fehlt).');
        }
        $rels = $this->relations($zip);

        $xml = $this->parse($workbook);
        $sheets = [];
        foreach ($xml->sheets->sheet ?? [] as $node) {
            $attrs = $node->attributes();
            $rid = (string) ($node->attributes('r', true)['id'] ?? '');
            $target = $rels[$rid] ?? null;
            if ($target === null) {
                continue;
            }
            $sheets[] = ['name' => (string) ($attrs['name'] ?? 'Tabelle'), 'path' => $target];
        }
        return $sheets;
    }

    /** @return array<string,string> r:id => Pfad im ZIP */
    private function relations(\ZipArchive $zip): array
    {
        $raw = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($raw === false) {
            return [];
        }
        $map = [];
        foreach ($this->parse($raw)->Relationship ?? [] as $rel) {
            $target = (string) $rel['Target'];
            $target = ltrim($target, '/');
            if (! str_starts_with($target, 'xl/')) {
                $target = 'xl/'.$target;
            }
            $map[(string) $rel['Id']] = $target;
        }
        return $map;
    }

    /** @return array<int,string> Index => Text der gemeinsamen Zeichenketten */
    private function sharedStrings(\ZipArchive $zip): array
    {
        $raw = $zip->getFromName('xl/sharedStrings.xml');
        if ($raw === false) {
            return [];
        }
        $strings = [];
        foreach ($this->parse($raw)->si ?? [] as $si) {
            // Ein Text kann in mehrere <r>-Abschnitte zerfallen (gemischte
            // Formatierung in einer Zelle) - dann ergibt erst deren Summe
            // den Zellinhalt.
            if (isset($si->r)) {
                $text = '';
                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }
                $strings[] = $text;
                continue;
            }
            $strings[] = (string) $si->t;
        }
        return $strings;
    }

    /**
     * Welche Zellformate stehen fuer ein Datum? Ohne diese Information kaeme
     * jedes Datum als nackte Zahl an ("46259") - und die Zuordnung
     * "Provisionsdatum" waere in der Vorschau unlesbar.
     *
     * @return array<int,bool> Index in cellXfs => ist Datumsformat
     */
    private function dateStyles(\ZipArchive $zip): array
    {
        $raw = $zip->getFromName('xl/styles.xml');
        if ($raw === false) {
            return [];
        }
        $xml = $this->parse($raw);

        $customIsDate = [];
        foreach ($xml->numFmts->numFmt ?? [] as $fmt) {
            $customIsDate[(int) $fmt['numFmtId']] = $this->formatIsDate((string) $fmt['formatCode']);
        }

        $styles = [];
        $index = 0;
        foreach ($xml->cellXfs->xf ?? [] as $xf) {
            $id = (int) ($xf['numFmtId'] ?? 0);
            $styles[$index++] = $customIsDate[$id] ?? in_array($id, self::BUILTIN_DATE_FORMATS, true);
        }
        return $styles;
    }

    /**
     * Ist der Formatcode ein Datum? Geprueft wird nur AUSSERHALB von
     * Anfuehrungszeichen: der Code '#,##0 "Tage"' enthaelt ein "d", meint
     * aber eine Zahl.
     */
    private function formatIsDate(string $code): bool
    {
        $stripped = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $code) ?? $code;
        return (bool) preg_match('/[dmyhs]/i', $stripped);
    }

    /**
     * Zeilen des Blattes. Gelesen wird mit XMLReader (Strom), nicht als Baum:
     * ein Abrechnungs-Export kann zehntausende Zeilen haben, und die als
     * SimpleXML-Objekte im Speicher zu halten waere derselbe Fehler, der bei
     * den grossen Listen im Portal schon einmal behoben wurde.
     *
     * @param array<int,string> $strings
     * @param array<int,bool> $dateStyles
     * @return array<int,array<int,string>>
     */
    private function rows(\ZipArchive $zip, string $sheetPath, array $strings, array $dateStyles): array
    {
        $raw = $zip->getFromName($sheetPath);
        if ($raw === false) {
            throw new \RuntimeException('Das Tabellenblatt konnte nicht gelesen werden.');
        }

        $reader = new \XMLReader;
        if (! $reader->XML($raw, 'UTF-8', LIBXML_NONET)) {
            throw new \RuntimeException('Das Tabellenblatt ist beschädigt.');
        }

        $rows = [];
        $row = [];
        $maxColumns = 0;

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'row') {
                $row = [];
                if ($reader->isEmptyElement) {
                    $rows[] = [];
                }
                continue;
            }
            if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->name === 'row') {
                $maxColumns = max($maxColumns, $row === [] ? 0 : max(array_keys($row)) + 1);
                $rows[] = $row;
                continue;
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'c') {
                continue;
            }

            $ref = (string) $reader->getAttribute('r');
            $type = (string) $reader->getAttribute('t');
            $styleIndex = $reader->getAttribute('s');
            $node = $this->parse($reader->readOuterXml());

            $value = $this->cellValue($node, $type, $strings);
            if ($value !== '' && $styleIndex !== null && ($dateStyles[(int) $styleIndex] ?? false) && is_numeric($value)) {
                $value = self::excelDate((float) $value);
            }

            // Leere Zellen werden in XLSX schlicht WEGGELASSEN. Ohne die
            // Spaltenposition aus der Zellreferenz wuerde jede Zeile mit
            // einer Luecke nach links rutschen - und die Spalten waeren
            // zeilenweise verschieden belegt.
            $column = self::columnIndex($ref);
            if ($column === null) {
                $row[] = $value;
                continue;
            }
            for ($i = count($row); $i < $column; $i++) {
                $row[$i] = '';
            }
            $row[$column] = $value;
        }
        $reader->close();

        // Auf gleiche Breite bringen, damit ein Spaltenindex in jeder Zeile
        // dasselbe bedeutet.
        return array_map(function ($r) use ($maxColumns) {
            $width = max($maxColumns, $r === [] ? 0 : max(array_keys($r)) + 1);
            $r += array_fill(0, $width, '');
            ksort($r);
            return array_values($r);
        }, $rows);
    }

    /** @param array<int,string> $strings */
    private function cellValue(\SimpleXMLElement $cell, string $type, array $strings): string
    {
        if ($type === 'inlineStr') {
            $text = '';
            foreach ($cell->is->r ?? [] as $run) {
                $text .= (string) $run->t;
            }
            return $text !== '' ? $text : (string) ($cell->is->t ?? '');
        }
        $value = (string) ($cell->v ?? '');
        if ($value === '') {
            return '';
        }
        if ($type === 's') {
            return $strings[(int) $value] ?? '';
        }
        if ($type === 'b') {
            return $value === '1' ? 'true' : 'false';
        }
        return $value;
    }

    /**
     * Excel-Seriennummer als deutsches Datum. Der 1900er-Bezug samt des
     * beruehmten Schaltjahr-Fehlers von 1900 steckt im Offset 25569 - genau
     * deshalb wird hier UTC gerechnet und nicht mit lokaler Zeit: eine
     * Sommerzeit-Verschiebung wuerde jedes Datum um einen Tag kippen koennen.
     */
    public static function excelDate(float $serial): string
    {
        if ($serial <= 0) {
            return (string) $serial;
        }
        $timestamp = (int) round(($serial - 25569) * 86400);
        $date = (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('UTC'));
        // Uhrzeitanteil nur zeigen, wenn es einen gibt (Provisionsdatum ist
        // in aller Regel ein reines Datum).
        return fmod($serial, 1.0) === 0.0 ? $date->format('d.m.Y') : $date->format('d.m.Y H:i:s');
    }

    /** Spaltenindex aus einer Zellreferenz: "A1" => 0, "AB7" => 27. */
    public static function columnIndex(string $ref): ?int
    {
        if (! preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) {
            return null;
        }
        $index = 0;
        foreach (str_split($m[1]) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }
        return $index - 1;
    }

    private function parse(string $xml): \SimpleXMLElement
    {
        $parsed = @simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        if ($parsed === false) {
            throw new \RuntimeException('Die Excel-Datei enthält ungültiges XML.');
        }
        return $parsed;
    }
}
