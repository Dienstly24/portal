<?php
namespace App\Support;

/**
 * Minimaler XLSX-Erzeuger ohne Fremdpaket (Provisions-Management):
 * eine Arbeitsmappe, ein Blatt, Zellen als inlineStr (Text) oder Zahl.
 * Bewusst ohne composer-Abhaengigkeit (kein maatwebsite/excel) - fuer
 * Berichts-Exporte reicht das vollstaendig. Text-Zellen sind als inlineStr
 * typisiert und werden von Excel NIE als Formel ausgewertet (kein
 * CSV-Injection-Risiko). Faellt die zip-Erweiterung auf dem Server, nutzt
 * der Aufrufer den CSV-Fallback (available()).
 */
class XlsxWriter
{
    public static function available(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /**
     * Erzeugt die XLSX-Datei als Binaer-String.
     *
     * @param array<int, array<int, mixed>> $rows  Zeilen mit Zellwerten
     *        (int/float = Zahl, alles andere = Text). Erste Zeile = Kopf.
     */
    public static function create(string $sheetName, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xml(mb_substr($sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>');

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach (array_values($rows) as $r => $cells) {
            $sheet .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($cells) as $c => $value) {
                $ref = self::columnLetter($c) . ($r + 1);
                if (is_int($value) || is_float($value)) {
                    $sheet .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
                } else {
                    $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                        . self::xml((string) $value) . '</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);

        $zip->close();
        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** 0 -> A, 1 -> B ... 26 -> AA (Excel-Spaltenbezeichnung). */
    private static function columnLetter(int $index): string
    {
        $letter = '';
        while ($index >= 0) {
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intdiv($index, 26) - 1;
        }
        return $letter;
    }
}
