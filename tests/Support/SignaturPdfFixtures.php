<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * PDF-Bauformen fuer die Testmatrix des Signatur-Moduls (Betreiber-Auftrag
 * 04.10.2026, A2).
 *
 * WARUM ERZEUGT STATT ALS DATEIEN EINGECHECKT: jede Bauform ist hier als
 * Absicht lesbar ("CropBox kleiner als MediaBox", "Inhalt als indirektes
 * Array") - eine eingecheckte Binaerdatei sagt nicht, WAS an ihr besonders
 * ist, und niemand merkt, wenn eine spaetere Fassung die Besonderheit
 * verliert. Die Bauformen stammen aus den echten Befunden vom 03.10.2026
 * (Word/LibreOffice: Ressourcen per Referenz; Scanner: indirektes
 * /Contents) und aus der Auftragsliste.
 *
 * Jede Seite traegt Text und eine schwarze Formularlinie - so wie ein
 * echter Vertrag; ein leeres Blatt wuerde manche Fehler gar nicht zeigen.
 */
final class SignaturPdfFixtures
{
    /** Name => Beschreibung - die Liste, ueber die die Matrix laeuft. */
    public const BAUFORMEN = [
        'standard' => 'Querverweistabelle, Ressourcen inline',
        'drehung90' => '/Rotate 90',
        'drehung180' => '/Rotate 180',
        'drehung270' => '/Rotate 270',
        'cropbox' => 'CropBox kleiner als MediaBox (Rand abgeschnitten)',
        'mediabox_versatz' => 'MediaBox beginnt nicht bei 0 0',
        'contents_indirekt' => '/Contents ist ein indirektes Array (U2), Ressourcen per Referenz (KI-062)',
        'acroform' => 'vorhandene Formularfelder (/AcroForm + Widget)',
        'pdfa' => 'PDF/A-Kennzeichnung (XMP-Metadaten + OutputIntent)',
        'inkrementell' => 'bereits einmal fortgeschrieben (zweiter Querverweis-Abschnitt mit /Prev)',
        'scan' => 'reines Bild-PDF wie vom Scanner, keine Textebene',
        'zwoelf_seiten' => '12 Seiten, Felder auf Seite 11',
        'objektstroeme' => 'Querverweis-Strom + komprimierte Objekt-Stroeme (PDF 1.5, via qpdf)',
    ];

    public static function bauform(string $name): string
    {
        return match ($name) {
            'standard' => self::pdf(),
            'drehung90' => self::pdf(drehung: 90),
            'drehung180' => self::pdf(drehung: 180),
            'drehung270' => self::pdf(drehung: 270),
            'cropbox' => self::pdf(mediaBox: [0, 0, 612, 792], cropBox: [50, 60, 560, 740]),
            'mediabox_versatz' => self::pdf(mediaBox: [100, 100, 695, 942]),
            'contents_indirekt' => self::pdf(contentsIndirekt: true, ressourcenAlsReferenz: true),
            'acroform' => self::pdf(acroform: true),
            'pdfa' => self::pdf(pdfa: true),
            'inkrementell' => self::inkrementell(),
            'scan' => self::pdf(scan: true),
            'zwoelf_seiten' => self::pdf(seiten: 12),
            'objektstroeme' => self::objektstroeme(),
            default => throw new \InvalidArgumentException('Unbekannte Bauform '.$name),
        };
    }

    /** Seite, auf die die Matrix ihre Felder setzt. */
    public static function zielseite(string $name): int
    {
        return $name === 'zwoelf_seiten' ? 11 : 1;
    }

    /**
     * @param  array{0: int, 1: int, 2: int, 3: int}  $mediaBox
     * @param  array{0: int, 1: int, 2: int, 3: int}|null  $cropBox
     */
    public static function pdf(
        int $seiten = 1,
        int $drehung = 0,
        array $mediaBox = [0, 0, 595, 842],
        ?array $cropBox = null,
        bool $contentsIndirekt = false,
        bool $ressourcenAlsReferenz = false,
        bool $acroform = false,
        bool $pdfa = false,
        bool $scan = false,
    ): string {
        [$mx, $my] = [$mediaBox[0], $mediaBox[1]];
        $inhalt = $scan
            ? 'q '.($mediaBox[2] - $mx).' 0 0 '.($mediaBox[3] - $my).' '.$mx.' '.$my." cm /Scan Do Q\n"
            : 'BT /F1 14 Tf '.($mx + 72).' '.($my + 760)." Td (Vertrag \\374ber Leistungen) Tj ET\n"
                .'0 0 0 RG 3 w '.($mx + 100).' '.($my + 200).' m '.($mx + 500).' '.($my + 200)." l S\n";

        $objekte = [];
        $katalog = '<< /Type /Catalog /Pages 2 0 R';
        $objekte[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $ressourcen = $scan
            ? '<< /XObject << /Scan 4 0 R >> /ProcSet [/PDF /ImageB] >>'
            : '<< /Font << /F1 3 0 R >> /ProcSet [/PDF /Text] >>';
        if ($scan) {
            $objekte[4] = self::scanBild();
        }
        if ($ressourcenAlsReferenz) {
            $objekte[9] = $ressourcen;
        }
        if ($acroform) {
            $objekte[20] = '<< /Type /Annot /Subtype /Widget /FT /Tx /T (Ort) /Rect [100 300 300 320] /F 4 /DA (/Helv 10 Tf 0 g) /V (Hamburg) /P 10 0 R >>';
            $katalog .= ' /AcroForm << /Fields [20 0 R] /DA (/Helv 0 Tf 0 g) /DR << /Font << /Helv 3 0 R >> >> >>';
        }
        if ($pdfa) {
            $xmp = '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
                .'<rdf:Description rdf:about="" xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/"><pdfaid:part>2</pdfaid:part><pdfaid:conformance>B</pdfaid:conformance></rdf:Description>'
                .'</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
            $objekte[30] = '<< /Type /Metadata /Subtype /XML /Length '.strlen($xmp).">>\nstream\n".$xmp."\nendstream";
            $katalog .= ' /Metadata 30 0 R /OutputIntents [<< /Type /OutputIntent /S /GTS_PDFA1 /OutputConditionIdentifier (sRGB IEC61966-2.1) >>]';
        }
        $objekte[1] = $katalog.' >>';

        $kids = [];
        $naechste = 10;
        for ($i = 0; $i < $seiten; $i++) {
            $seite = $naechste++;
            $strom = $naechste++;
            $text = str_replace('Vertrag', 'Vertrag Seite '.($i + 1), $inhalt);
            $objekte[$strom] = '<< /Length '.strlen($text)." >>\nstream\n".$text.'endstream';
            $contents = $strom.' 0 R';
            if ($contentsIndirekt) {
                $array = $naechste++;
                $objekte[$array] = '['.$strom.' 0 R]';
                $contents = $array.' 0 R';
            }
            $objekte[$seite] = '<< /Type /Page /Parent 2 0 R /MediaBox ['.implode(' ', $mediaBox).']'
                .($cropBox !== null ? ' /CropBox ['.implode(' ', $cropBox).']' : '')
                .' /Rotate '.$drehung
                .' /Resources '.($ressourcenAlsReferenz ? '9 0 R' : $ressourcen)
                .' /Contents '.$contents
                .($acroform && $i === 0 ? ' /Annots [20 0 R]' : '').' >>';
            $kids[] = $seite.' 0 R';
        }
        $objekte[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$seiten.' >>';

        return self::datei($objekte);
    }

    /**
     * Ein PDF, das bereits einmal fortgeschrieben wurde - wie nach einer
     * frueheren Bearbeitung in einem anderen Programm. Der zweite Abschnitt
     * ersetzt den Seiteninhalt; der Stempler muss auf der NEUEN Fassung
     * aufsetzen.
     */
    public static function inkrementell(): string
    {
        $basis = self::pdf();
        preg_match('/startxref\s+(\d+)/', $basis, $m);
        $prev = (int) $m[1];
        $neu = "BT /F1 14 Tf 72 760 Td (Vertrag - geaenderte Fassung) Tj ET\n0 0 0 RG 3 w 100 200 m 500 200 l S\n";
        $anhang = '';
        $offsets = [];
        $start = strlen($basis);
        $offsets[11] = $start + strlen($anhang);
        $anhang .= "11 0 obj\n<< /Length ".strlen($neu)." >>\nstream\n".$neu."endstream\nendobj\n";
        $xref = $start + strlen($anhang);
        $anhang .= "xref\n11 1\n".sprintf("%010d 00000 n \n", $offsets[11]);
        $anhang .= "trailer\n<< /Size 12 /Root 1 0 R /Prev ".$prev." >>\nstartxref\n".$xref."\n%%EOF\n";

        return $basis.$anhang;
    }

    /** PDF 1.5 mit Querverweis-Strom und Objekt-Stroemen - erzeugt von qpdf. */
    public static function objektstroeme(): string
    {
        return self::qpdf(self::pdf(ressourcenAlsReferenz: true), ['--object-streams=generate']);
    }

    /** Ein Beispielvertrag, verschluesselt (ohne Benutzerpasswort, mit Besitzerpasswort). */
    public static function verschluesselt(): string
    {
        return self::qpdf(self::pdf(), ['--encrypt', '', 'besitzer', '256', '--']);
    }

    /** Verschluesselt von Hand: der Trailer nennt /Encrypt - ohne qpdf. */
    public static function verschluesseltOhneQpdf(): string
    {
        $pdf = self::pdf();

        return str_replace('/Root 1 0 R >>', '/Root 1 0 R /Encrypt << /Filter /Standard /V 1 /R 2 /O (x) /U (y) /P -4 >> >>', $pdf);
    }

    /** Beschaedigt: die Querverweistabelle zeigt an die falschen Stellen. */
    public static function beschaedigt(): string
    {
        $pdf = self::pdf();

        return preg_replace_callback('/(\d{10}) 00000 n/', fn ($m) => sprintf('%010d 00000 n', ((int) $m[1]) + 7), $pdf) ?? $pdf;
    }

    public static function qpdfVerfuegbar(): bool
    {
        try {
            $p = new Process(['qpdf', '--version']);
            $p->run();

            return $p->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{code: int, ausgabe: string}
     */
    public static function qpdfCheck(string $pdf): array
    {
        $datei = tempnam(sys_get_temp_dir(), 'qchk');
        file_put_contents($datei, $pdf);
        $p = new Process(['qpdf', '--check', $datei]);
        $p->run();
        @unlink($datei);

        return ['code' => (int) $p->getExitCode(), 'ausgabe' => $p->getOutput().$p->getErrorOutput()];
    }

    /** @param  list<string>  $argumente */
    private static function qpdf(string $pdf, array $argumente): string
    {
        $ein = tempnam(sys_get_temp_dir(), 'qin');
        $aus = $ein.'.aus.pdf';
        file_put_contents($ein, $pdf);
        // Optionen VOR den Dateinamen; --encrypt endet mit "--".
        $befehl = array_merge(['qpdf'], $argumente, [$ein, $aus]);
        $p = new Process($befehl);
        $p->run();
        $ergebnis = is_file($aus) ? (string) file_get_contents($aus) : '';
        @unlink($ein);
        @unlink($aus);
        if ($ergebnis === '') {
            throw new \RuntimeException('qpdf konnte die Bauform nicht erzeugen: '.$p->getErrorOutput());
        }

        return $ergebnis;
    }

    /** Graustufen-"Scan" mit Rauschen und dunklem Textblock. */
    private static function scanBild(): string
    {
        $w = 200;
        $h = 280;
        $roh = '';
        mt_srand(42);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $text = $y > 20 && $y < 60 && $x > 20 && $x < 180 && ($x + $y) % 3 === 0;
                $roh .= chr($text ? 40 : 235 + mt_rand(0, 15));
            }
        }
        $daten = (string) gzcompress($roh);

        return '<< /Type /XObject /Subtype /Image /Width '.$w.' /Height '.$h
            .' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length '.strlen($daten).">>\nstream\n".$daten."\nendstream";
    }

    /** @param  array<int, string>  $objekte */
    private static function datei(array $objekte): string
    {
        ksort($objekte);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objekte as $nummer => $koerper) {
            $offsets[$nummer] = strlen($pdf);
            $pdf .= $nummer." 0 obj\n".$koerper."\nendobj\n";
        }
        $max = max(array_keys($objekte));
        $start = strlen($pdf);
        $pdf .= "xref\n0 ".($max + 1)."\n0000000000 65535 f \n";
        for ($n = 1; $n <= $max; $n++) {
            $pdf .= isset($offsets[$n]) ? sprintf("%010d 00000 n \n", $offsets[$n]) : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<< /Size ".($max + 1)." /Root 1 0 R >>\nstartxref\n".$start."\n%%EOF\n";

        return $pdf;
    }
}
