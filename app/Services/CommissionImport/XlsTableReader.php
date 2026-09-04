<?php

namespace App\Services\CommissionImport;

/**
 * Leser fuer das ALTE Excel-Format .xls (BIFF8, Excel 97-2003).
 *
 * WARUM UEBERHAUPT: der Betrieb bekommt Abrechnungen aus fremden Systemen,
 * und aeltere Portale exportieren weiterhin .xls. Ein "bitte erst als xlsx
 * speichern" ist ein Arbeitsschritt, den jemand jeden Monat von Hand machen
 * muesste - und irgendwann vergisst.
 *
 * Gelesen werden ausschliesslich ZELLWERTE (Text, Zahl, Datum, Formelergebnis
 * wie zuletzt von Excel berechnet). Formeln werden NICHT ausgewertet, Makros
 * nie angefasst.
 */
class XlsTableReader
{
    // BIFF-Satzarten, die einen Zellwert oder Aufbau tragen.
    private const BOF = 0x0809;
    private const EOF_REC = 0x000A;
    private const BOUNDSHEET = 0x0085;
    private const SST = 0x00FC;
    private const CONTINUE = 0x003C;
    private const LABELSST = 0x00FD;
    private const LABEL = 0x0204;
    private const RK = 0x027E;
    private const MULRK = 0x00BD;
    private const NUMBER = 0x0203;
    private const FORMULA = 0x0006;
    private const STRING_REC = 0x0207;
    private const FORMAT = 0x041E;
    private const XF = 0x00E0;
    private const DATEMODE = 0x0022;

    private const SUBSTREAM_WORKSHEET = 0x0010;

    /** Eingebaute Formate, die ein Datum bedeuten (wie in XLSX). */
    private const BUILTIN_DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47];

    /** @return array<int,string> */
    public function sheetNames(string $path): array
    {
        return array_column($this->boundSheets($this->workbookStream($path)), 'name');
    }

    public function read(string $path, ?string $sheetName = null): TableFile
    {
        $stream = $this->workbookStream($path);
        $sheets = $this->boundSheets($stream);
        if ($sheets === []) {
            throw new \RuntimeException('Die .xls-Datei enthält kein Tabellenblatt.');
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

        $globals = $this->globals($stream);
        $rows = $this->sheetRows($stream, $sheet['offset'], $globals);

        $rows = array_values(array_filter($rows, fn ($r) => trim(implode('', $r)) !== ''));
        if ($rows === []) {
            throw new \RuntimeException('Das Tabellenblatt "'.$sheet['name'].'" enthält keine Zeilen.');
        }
        $header = array_map(fn ($v) => trim((string) $v), (array) array_shift($rows));

        return new TableFile(
            format: 'xls',
            header: $header,
            rows: $rows,
            encoding: 'UTF-8',
            sheetName: $sheet['name'],
            sheetNames: $names,
        );
    }

    private function workbookStream(string $path): string
    {
        $ole = OleCompoundFile::fromPath($path);
        // Excel 97+ nennt den Strom "Workbook", Excel 5/95 "Book". Beide
        // Namen zu kennen kostet nichts und erspart eine unverstaendliche
        // Fehlermeldung bei einer sehr alten Datei.
        foreach (['Workbook', 'Book'] as $name) {
            if ($ole->has($name)) {
                return $ole->stream($name);
            }
        }
        throw new \RuntimeException('Die Datei enthält keine Excel-Arbeitsmappe. Bitte als .xlsx oder CSV speichern.');
    }

    /**
     * Saetze des Stroms durchlaufen. CONTINUE-Saetze sind die Fortsetzung des
     * VORIGEN Satzes und werden hier bereits angehaengt - mit Ausnahme der
     * Zeichenketten-Tabelle, die ihre Bruchstellen selbst kennen muss (dort
     * beginnt jedes Bruchstueck mit einem eigenen Kennzeichen-Byte).
     *
     * @return \Generator<int,array{id:int,data:string,segments:array<int,string>,offset:int}>
     */
    private function records(string $stream, int $from = 0): \Generator
    {
        $length = strlen($stream);
        $position = $from;
        $pending = null;

        while ($position + 4 <= $length) {
            $id = unpack('v', substr($stream, $position, 2))[1];
            $size = unpack('v', substr($stream, $position + 2, 2))[1];
            $data = substr($stream, $position + 4, $size);
            $offset = $position;
            $position += 4 + $size;

            if ($id === self::CONTINUE && $pending !== null) {
                $pending['data'] .= $data;
                $pending['segments'][] = $data;
                continue;
            }
            if ($pending !== null) {
                yield $pending;
                $pending = null;
            }
            $record = ['id' => $id, 'data' => $data, 'segments' => [$data], 'offset' => $offset];

            // Nur Saetze, die tatsaechlich fortgesetzt werden koennen, warten
            // auf ein moegliches CONTINUE. Alles andere sofort ausliefern -
            // sonst haengt der letzte Satz einer Datei in der Schleife fest.
            if (in_array($id, [self::SST, self::FORMAT, self::LABEL], true)) {
                $pending = $record;
                continue;
            }
            yield $record;
        }
        if ($pending !== null) {
            yield $pending;
        }
    }

    /**
     * Blattnamen und die Startposition ihres Teilstroms.
     *
     * @return array<int,array{name:string,offset:int}>
     */
    private function boundSheets(string $stream): array
    {
        $sheets = [];
        foreach ($this->records($stream) as $record) {
            if ($record['id'] === self::EOF_REC) {
                break; // Ende des Globalteils - danach kommen die Blaetter
            }
            if ($record['id'] !== self::BOUNDSHEET) {
                continue;
            }
            $offset = unpack('V', substr($record['data'], 0, 4))[1];
            $type = ord($record['data'][5] ?? "\0") & 0x0F;
            if ($type !== 0) {
                continue; // Diagramm- oder Makroblatt, keine Tabelle
            }
            $sheets[] = [
                'name' => $this->shortString(substr($record['data'], 6)),
                'offset' => $offset,
            ];
        }
        return $sheets;
    }

    /**
     * Globalteil: Zeichenketten-Tabelle, Datumsformate und Datumssystem.
     *
     * @return array{strings:array<int,string>,dateXf:array<int,bool>,base1904:bool}
     */
    private function globals(string $stream): array
    {
        $strings = [];
        $formats = [];
        $xfFormats = [];
        $base1904 = false;

        foreach ($this->records($stream) as $record) {
            if ($record['id'] === self::EOF_REC) {
                break;
            }
            switch ($record['id']) {
                case self::SST:
                    $strings = $this->sharedStrings($record['segments']);
                    break;
                case self::FORMAT:
                    $id = unpack('v', substr($record['data'], 0, 2))[1];
                    $formats[$id] = $this->unicodeString(substr($record['data'], 2));
                    break;
                case self::XF:
                    $xfFormats[] = unpack('v', substr($record['data'], 2, 2))[1];
                    break;
                case self::DATEMODE:
                    // 1904-System: der Mac-Nullpunkt. Wird es uebersehen,
                    // liegt JEDES Datum um gut vier Jahre daneben - und das
                    // sieht plausibel genug aus, um niemandem aufzufallen.
                    $base1904 = (unpack('v', substr($record['data'], 0, 2))[1] ?? 0) === 1;
                    break;
            }
        }

        $dateXf = [];
        foreach ($xfFormats as $index => $formatId) {
            $dateXf[$index] = isset($formats[$formatId])
                ? $this->formatIsDate($formats[$formatId])
                : in_array($formatId, self::BUILTIN_DATE_FORMATS, true);
        }

        return ['strings' => $strings, 'dateXf' => $dateXf, 'base1904' => $base1904];
    }

    /**
     * Zellen eines Blattes einlesen.
     *
     * @param array{strings:array<int,string>,dateXf:array<int,bool>,base1904:bool} $globals
     * @return array<int,array<int,string>>
     */
    private function sheetRows(string $stream, int $offset, array $globals): array
    {
        $cells = [];
        $maxColumn = 0;
        $started = false;
        $lastFormulaCell = null;

        foreach ($this->records($stream, $offset) as $record) {
            if ($record['id'] === self::BOF) {
                $type = unpack('v', substr($record['data'], 2, 2))[1] ?? 0;
                if ($type !== self::SUBSTREAM_WORKSHEET) {
                    return []; // kein Tabellenblatt
                }
                $started = true;
                continue;
            }
            if (! $started) {
                continue;
            }
            if ($record['id'] === self::EOF_REC) {
                break;
            }

            $data = $record['data'];
            switch ($record['id']) {
                case self::LABELSST:
                    [$row, $column, $xf] = $this->cellHeader($data);
                    $index = unpack('V', substr($data, 6, 4))[1] ?? 0;
                    $cells[$row][$column] = $globals['strings'][$index] ?? '';
                    break;

                case self::LABEL:
                    [$row, $column, $xf] = $this->cellHeader($data);
                    $cells[$row][$column] = $this->unicodeString(substr($data, 6));
                    break;

                case self::NUMBER:
                    [$row, $column, $xf] = $this->cellHeader($data);
                    $value = unpack('e', substr($data, 6, 8))[1] ?? 0.0;
                    $cells[$row][$column] = $this->number($value, $xf, $globals);
                    break;

                case self::RK:
                    [$row, $column, $xf] = $this->cellHeader($data);
                    $value = $this->decodeRk(substr($data, 6, 4));
                    $cells[$row][$column] = $this->number($value, $xf, $globals);
                    break;

                case self::MULRK:
                    $row = unpack('v', substr($data, 0, 2))[1];
                    $first = unpack('v', substr($data, 2, 2))[1];
                    $count = intdiv(strlen($data) - 6, 6);
                    for ($i = 0; $i < $count; $i++) {
                        $base = 4 + $i * 6;
                        $xf = unpack('v', substr($data, $base, 2))[1];
                        $value = $this->decodeRk(substr($data, $base + 2, 4));
                        $cells[$row][$first + $i] = $this->number($value, $xf, $globals);
                    }
                    break;

                case self::FORMULA:
                    [$row, $column, $xf] = $this->cellHeader($data);
                    $result = substr($data, 6, 8);
                    // 0xFFFF in den oberen zwei Bytes heisst: das Ergebnis ist
                    // KEINE Zahl, sondern folgt als eigener STRING-Satz (oder
                    // ist Wahrheitswert/Fehler). Ohne diese Weiche kaeme
                    // sonst eine sinnlose Gleitkommazahl in die Zelle.
                    if (substr($result, 6, 2) === "\xFF\xFF") {
                        $cells[$row][$column] = '';
                        $lastFormulaCell = [$row, $column];
                        break;
                    }
                    $value = unpack('e', $result)[1] ?? 0.0;
                    $cells[$row][$column] = $this->number($value, $xf, $globals);
                    break;

                case self::STRING_REC:
                    if ($lastFormulaCell !== null) {
                        [$row, $column] = $lastFormulaCell;
                        $cells[$row][$column] = $this->unicodeString($data);
                        $lastFormulaCell = null;
                    }
                    break;
            }
        }

        if ($cells === []) {
            return [];
        }
        foreach ($cells as $row) {
            $maxColumn = max($maxColumn, $row === [] ? 0 : max(array_keys($row)) + 1);
        }

        ksort($cells);
        $out = [];
        // Luecken zwischen Zeilennummern auffuellen: eine leere Zeile mitten
        // in der Datei darf die Reihenfolge nicht verschieben.
        $lastRow = max(array_keys($cells));
        for ($r = 0; $r <= $lastRow; $r++) {
            $row = ($cells[$r] ?? []) + array_fill(0, $maxColumn, '');
            ksort($row);
            $out[] = array_values($row);
        }
        return $out;
    }

    /** @return array{0:int,1:int,2:int} Zeile, Spalte, Formatindex */
    private function cellHeader(string $data): array
    {
        return [
            unpack('v', substr($data, 0, 2))[1] ?? 0,
            unpack('v', substr($data, 2, 2))[1] ?? 0,
            unpack('v', substr($data, 4, 2))[1] ?? 0,
        ];
    }

    /**
     * @param array{strings:array<int,string>,dateXf:array<int,bool>,base1904:bool} $globals
     */
    private function number(float $value, int $xf, array $globals): string
    {
        if ($globals['dateXf'][$xf] ?? false) {
            return self::excelDate($value, $globals['base1904']);
        }
        // Ganze Zahlen ohne ".0" ausgeben - eine Vertragsnummer als
        // "19613073.0" wuerde beim Vergleich nicht mehr treffen.
        return fmod($value, 1.0) === 0.0 && abs($value) < 1e15
            ? (string) (int) $value
            : rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    /**
     * RK-Wert: Excel spart Platz, indem es haeufige Zahlen in 4 Bytes packt.
     * Bit 0 = die Zahl war durch 100 geteilt, Bit 1 = Ganzzahl statt
     * Gleitkomma.
     */
    private function decodeRk(string $bytes): float
    {
        $raw = unpack('V', $bytes)[1] ?? 0;
        $isInteger = ($raw & 0x02) !== 0;
        $isPercent = ($raw & 0x01) !== 0;

        if ($isInteger) {
            $value = $raw >> 2;
            // Vorzeichen aus dem 30-Bit-Wert wiederherstellen.
            if ($value & 0x20000000) {
                $value -= 0x40000000;
            }
            $result = (float) $value;
        } else {
            $result = unpack('e', "\x00\x00\x00\x00".pack('V', $raw & 0xFFFFFFFC))[1] ?? 0.0;
        }
        return $isPercent ? $result / 100 : $result;
    }

    /**
     * Zeichenketten-Tabelle. Der schwierige Teil sind die Bruchstellen: eine
     * lange Tabelle wird in Fortsetzungs-Saetze zerlegt, und jedes Bruchstueck
     * beginnt mit einem EIGENEN Kennzeichen-Byte (8- oder 16-Bit-Zeichen).
     * Wer das ignoriert, bekommt ab der ersten Bruchstelle Zeichensalat - und
     * zwar nur bei GROSSEN Dateien, also erst im Echtbetrieb.
     *
     * @param array<int,string> $segments
     * @return array<int,string>
     */
    private function sharedStrings(array $segments): array
    {
        $cursor = new BiffStringCursor($segments);
        $cursor->skip(4);                      // Gesamtzahl (nicht benoetigt)
        $unique = $cursor->uint32();

        $strings = [];
        for ($i = 0; $i < $unique; $i++) {
            $string = $cursor->string();
            if ($string === null) {
                break; // Datei zu Ende - lieber weniger Texte als ein Absturz
            }
            $strings[] = $string;
        }
        return $strings;
    }

    /** Kurzer Text mit 1-Byte-Laenge (Blattname). */
    private function shortString(string $data): string
    {
        $length = ord($data[0] ?? "\0");
        $flags = ord($data[1] ?? "\0");
        $wide = ($flags & 0x01) !== 0;
        $bytes = substr($data, 2, $wide ? $length * 2 : $length);
        return $wide
            ? (string) mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE')
            : (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    /** Text mit 2-Byte-Laenge (Zellwert, Formelergebnis). */
    private function unicodeString(string $data): string
    {
        $length = unpack('v', substr($data, 0, 2))[1] ?? 0;
        $flags = ord($data[2] ?? "\0");
        $wide = ($flags & 0x01) !== 0;
        $offset = 3;
        if (($flags & 0x08) !== 0) { // Rich-Text: Anzahl Formatlaeufe
            $offset += 2;
        }
        if (($flags & 0x04) !== 0) { // Fernost-Zusatzdaten
            $offset += 4;
        }
        $bytes = substr($data, $offset, $wide ? $length * 2 : $length);
        return $wide
            ? (string) mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE')
            : (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    private function formatIsDate(string $code): bool
    {
        $stripped = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $code) ?? $code;
        return (bool) preg_match('/[dmyhs]/i', $stripped);
    }

    /** Seriennummer als deutsches Datum (siehe XlsxTableReader::excelDate). */
    public static function excelDate(float $serial, bool $base1904 = false): string
    {
        if ($serial <= 0) {
            return (string) $serial;
        }
        $offset = $base1904 ? 24107 : 25569;
        $timestamp = (int) round(($serial - $offset) * 86400);
        $date = (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('UTC'));
        return fmod($serial, 1.0) === 0.0 ? $date->format('d.m.Y') : $date->format('d.m.Y H:i:s');
    }
}
