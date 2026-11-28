<?php

namespace App\Services\Pdf;

/**
 * Erzeugt ein schlichtes Tabellen-PDF (A4 quer) ohne Fremdpaket - fuer den
 * PDF-Export der Vertragsliste (28.11.2026).
 *
 * Bewusst dieselbe Abwaegung wie beim XLSX-Schreiber und beim Signatur-
 * Stempler: ein PDF-Framework nur fuer eine Tabelle waere ein grosses Paket
 * im Sicherheitsupdate-Pfad einer Anwendung mit Kundendaten. Genutzt werden
 * die vorhandenen Bausteine: Helvetica-Breiten (`PdfStamper::textBreite`)
 * und die WinAnsi-Umsetzung (`PdfSyntax::escapeString`) - sonst stuende
 * statt "Müller" Kauderwelsch im Dokument.
 *
 * Kopfzeile der Tabelle wiederholt sich auf jeder Seite, jede Seite traegt
 * "Seite x von y". Zu lange Zellen werden mit "…" GEKUERZT, nie ueber die
 * Spalte hinaus geschrieben (sonst ueberdeckt der Name die Sparte).
 */
class TabellenPdf
{
    private const BREITE = 841.89;
    private const HOEHE = 595.28;
    private const RAND = 22.0;
    private const SCHRIFT = 7.5;
    private const ZEILE = 13.0;

    /**
     * @param  list<array{0:string,1:float,2?:string}>  $spalten  [Titel, Breite in pt, 'r' = rechtsbuendig]
     * @param  list<list<string>>  $zeilen
     * @param  list<string>|null  $summe  optionale Abschlusszeile (fett)
     */
    public static function erzeuge(string $titel, string $untertitel, array $spalten, array $zeilen, ?array $summe = null): string
    {
        $oben = self::HOEHE - self::RAND;
        $tabelleStart = $oben - 40;           // unter Titel und Untertitel
        $unten = self::RAND + 18;             // ueber der Fusszeile
        $jeSeite = max(1, (int) floor(($tabelleStart - self::ZEILE - $unten) / self::ZEILE));

        $alle = $zeilen;
        $seiten = $alle === [] ? [[]] : array_chunk($alle, $jeSeite);
        // Passt die Summe nicht mehr auf die letzte Seite, bekommt sie eine eigene.
        if ($summe !== null && count(end($seiten)) >= $jeSeite) {
            $seiten[] = [];
        }
        $anzahlSeiten = count($seiten);

        $inhalte = [];
        foreach ($seiten as $nr => $seitenZeilen) {
            $s = '';
            $s .= self::text(self::RAND, $oben - 14, $titel, 13, true);
            $s .= self::text(self::RAND, $oben - 27, $untertitel, 8, false, '0.37 0.42 0.38');

            $y = $tabelleStart;
            // Kopfzeile mit hellem Grund
            $s .= '0.945 0.933 0.898 rg '.PdfSyntax::num(self::RAND).' '.PdfSyntax::num($y - 4).' '
                .PdfSyntax::num(self::BREITE - 2 * self::RAND).' '.PdfSyntax::num(self::ZEILE).' re f'."\n";
            $s .= self::zeile($spalten, array_column($spalten, 0), $y, true);
            $y -= self::ZEILE;

            foreach ($seitenZeilen as $i => $zeile) {
                if ($i % 2 === 1) {
                    $s .= '0.984 0.98 0.965 rg '.PdfSyntax::num(self::RAND).' '.PdfSyntax::num($y - 4).' '
                        .PdfSyntax::num(self::BREITE - 2 * self::RAND).' '.PdfSyntax::num(self::ZEILE).' re f'."\n";
                }
                $s .= self::zeile($spalten, $zeile, $y, false);
                $y -= self::ZEILE;
            }

            if ($summe !== null && $nr === $anzahlSeiten - 1) {
                $s .= '0.878 0.863 0.816 RG 0.6 w '.PdfSyntax::num(self::RAND).' '.PdfSyntax::num($y + self::ZEILE - 4)
                    .' m '.PdfSyntax::num(self::BREITE - self::RAND).' '.PdfSyntax::num($y + self::ZEILE - 4).' l S'."\n";
                $s .= self::zeile($spalten, $summe, $y, true);
            }

            $fuss = 'Seite '.($nr + 1).' von '.$anzahlSeiten;
            $s .= self::text(self::BREITE - self::RAND - PdfStamper::textBreite($fuss, 7), self::RAND, $fuss, 7, false, '0.37 0.42 0.38');
            $s .= self::text(self::RAND, self::RAND, 'Dienstly24 - interne Liste, vertraulich', 7, false, '0.37 0.42 0.38');
            $inhalte[] = $s;
        }

        return self::zusammensetzen($inhalte);
    }

    /** Kuerzt einen Text mit "…" auf die gegebene Breite. */
    public static function kuerzen(string $text, float $breite, float $size = self::SCHRIFT): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (PdfStamper::textBreite($text, $size) <= $breite) {
            return $text;
        }
        $ellipse = '…';
        while ($text !== '' && PdfStamper::textBreite($text.$ellipse, $size) > $breite) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).$ellipse;
    }

    /**
     * @param  list<array{0:string,1:float,2?:string}>  $spalten
     * @param  list<string>  $werte
     */
    private static function zeile(array $spalten, array $werte, float $y, bool $fett): string
    {
        $s = '';
        $x = self::RAND + 4;
        foreach ($spalten as $i => $spalte) {
            $breite = (float) $spalte[1];
            $innen = $breite - 6;
            $wert = self::kuerzen((string) ($werte[$i] ?? ''), $innen);
            if ($wert !== '') {
                $tx = ($spalte[2] ?? '') === 'r' ? $x + $innen - PdfStamper::textBreite($wert, self::SCHRIFT) : $x;
                $s .= self::text($tx, $y, $wert, self::SCHRIFT, $fett);
            }
            $x += $breite;
        }

        return $s;
    }

    private static function text(float $x, float $y, string $text, float $size, bool $fett, string $farbe = '0.086 0.129 0.11'): string
    {
        return 'BT '.$farbe.' rg /'.($fett ? 'F2' : 'F1').' '.PdfSyntax::num($size).' Tf '
            .PdfSyntax::num($x).' '.PdfSyntax::num($y).' Td '.PdfSyntax::escapeString($text).' Tj ET'."\n";
    }

    /** @param  list<string>  $inhalte */
    private static function zusammensetzen(array $inhalte): string
    {
        $objekte = [];
        $objekte[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objekte[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objekte[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $kinder = [];
        $naechste = 5;
        foreach ($inhalte as $inhalt) {
            $seite = $naechste++;
            $strom = $naechste++;
            $kinder[] = $seite.' 0 R';
            $objekte[$seite] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.PdfSyntax::num(self::BREITE).' '.PdfSyntax::num(self::HOEHE).']'
                .' /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$strom.' 0 R >>';
            $daten = function_exists('gzcompress') ? gzcompress($inhalt, 6) : $inhalt;
            $filter = function_exists('gzcompress') ? ' /Filter /FlateDecode' : '';
            $objekte[$strom] = '<< /Length '.strlen($daten).$filter." >>\nstream\n".$daten."\nendstream";
        }
        $objekte[2] = '<< /Type /Pages /Kids ['.implode(' ', $kinder).'] /Count '.count($kinder).' >>';
        ksort($objekte);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objekte as $nr => $koerper) {
            $offsets[$nr] = strlen($pdf);
            $pdf .= $nr." 0 obj\n".$koerper."\nendobj\n";
        }
        $xref = strlen($pdf);
        $anzahl = max(array_keys($objekte)) + 1;
        $pdf .= "xref\n0 ".$anzahl."\n0000000000 65535 f \n";
        for ($i = 1; $i < $anzahl; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size ".$anzahl." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";

        return $pdf;
    }
}
