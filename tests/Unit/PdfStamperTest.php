<?php

namespace Tests\Unit;

use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\PdfException;
use App\Services\Pdf\PdfStamp;
use App\Services\Pdf\PdfStamper;
use PHPUnit\Framework\TestCase;

/**
 * Die PDF-Ebene des Signatur-Moduls.
 *
 * WAS HIER ABGESICHERT WIRD, und warum genau das: die Beweiskraft des
 * unterschriebenen Dokuments haengt an DREI Eigenschaften, und alle drei
 * sind unsichtbar, wenn man das PDF nur ansieht.
 *
 *  1. Das Original bleibt Byte fuer Byte erhalten (Fortschreibung statt
 *     Neuaufbau). Bricht das, ist die Aussage "am Vertragstext wurde nichts
 *     geaendert" nicht mehr belegbar - und niemand wuerde es merken.
 *  2. Seiten aus KOMPRIMIERTEN Objekt-Stroemen werden gefunden. Bei
 *     modernen PDFs (1.5+) liegt der Seitenbaum regelmaessig dort; wer nur
 *     den Klartext liest, findet bei genau diesen Dateien keine Seite.
 *  3. Die Seitendrehung wird gerechnet. Sonst steht die Unterschrift auf
 *     jedem quer eingescannten Vertrag am falschen Rand - und zwar erst im
 *     fertigen Dokument, nie im Editor.
 */
class PdfStamperTest extends TestCase
{
    /** Ein minimales PDF mit klassischer Querverweistabelle. */
    private function klassischesPdf(int $rotate = 0): string
    {
        $objekte = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 600 800] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >>'
                .($rotate !== 0 ? ' /Rotate '.$rotate : '').' >>',
            4 => "<< /Length 44 >>\nstream\nBT /F1 12 Tf 72 700 Td (Vertragstext) Tj ET\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objekte as $nummer => $koerper) {
            $offsets[$nummer] = strlen($pdf);
            $pdf .= $nummer." 0 obj\n".$koerper."\nendobj\n";
        }
        $start = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objekte) + 1)."\n0000000000 65535 f \n";
        foreach ($objekte as $nummer => $koerper) {
            $pdf .= sprintf("%010d %05d n \n", $offsets[$nummer], 0);
        }
        $pdf .= "trailer\n<< /Size ".(count($objekte) + 1)." /Root 1 0 R >>\nstartxref\n".$start."\n%%EOF\n";

        return $pdf;
    }

    /**
     * Ein PDF, dessen Seitenbaum in einem komprimierten Objekt-Strom liegt
     * und dessen Querverweistabelle selbst ein Strom ist (PDF 1.5).
     */
    private function objektStromPdf(): string
    {
        $inhalt = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 400 500] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 5 0 R >>',
        ];
        $paare = '';
        $koerper = '';
        foreach ($inhalt as $nummer => $text) {
            $paare .= $nummer.' '.strlen($koerper).' ';
            $koerper .= $text.' ';
        }
        $first = strlen($paare);
        $strom = gzcompress($paare.$koerper);

        $pdf = "%PDF-1.5\n";
        $offsets = [];

        $offsets[4] = strlen($pdf);
        $pdf .= "4 0 obj\n<< /Type /ObjStm /N 3 /First ".$first.' /Filter /FlateDecode /Length '
            .strlen($strom)." >>\nstream\n".$strom."\nendstream\nendobj\n";

        $seiteninhalt = "BT (X) Tj ET\n";
        $offsets[5] = strlen($pdf);
        $pdf .= "5 0 obj\n<< /Length ".strlen($seiteninhalt)." >>\nstream\n".$seiteninhalt."endstream\nendobj\n";

        $xrefOffset = strlen($pdf);
        $daten = chr(0).pack('N', 0).pack('n', 65535);
        foreach ([1, 2, 3] as $index => $nummer) {
            $daten .= chr(2).pack('N', 4).pack('n', $index);
        }
        $daten .= chr(1).pack('N', $offsets[4]).pack('n', 0);
        $daten .= chr(1).pack('N', $offsets[5]).pack('n', 0);
        $daten .= chr(1).pack('N', $xrefOffset).pack('n', 0);
        $pdf .= "6 0 obj\n<< /Type /XRef /Size 7 /W [1 4 2] /Root 1 0 R /Length ".strlen($daten)
            ." >>\nstream\n".$daten."\nendstream\nendobj\n";
        $pdf .= "startxref\n".$xrefOffset."\n%%EOF\n";

        return $pdf;
    }

    private function unterschriftsBild(): string
    {
        $bild = imagecreatetruecolor(120, 50);
        imagesavealpha($bild, true);
        imagealphablending($bild, false);
        imagefill($bild, 0, 0, imagecolorallocatealpha($bild, 255, 255, 255, 127));
        imagealphablending($bild, true);
        imageline($bild, 5, 40, 60, 8, imagecolorallocate($bild, 10, 10, 60));
        imageline($bild, 60, 8, 115, 42, imagecolorallocate($bild, 10, 10, 60));
        ob_start();
        imagepng($bild);
        $png = (string) ob_get_clean();
        imagedestroy($bild);

        return $png;
    }

    public function test_seiten_und_groesse_werden_gelesen(): void
    {
        $dokument = PdfDocument::open($this->klassischesPdf());

        $this->assertSame(1, $dokument->pageCount());
        $seite = $dokument->page(0);
        $this->assertSame(600.0, $seite->width());
        $this->assertSame(800.0, $seite->height());
        $this->assertSame(0, $seite->rotate);
    }

    public function test_seiten_aus_komprimierten_objekt_stroemen_werden_gefunden(): void
    {
        $dokument = PdfDocument::open($this->objektStromPdf());

        $this->assertSame(1, $dokument->pageCount(), 'Seitenbaum im ObjStm nicht gefunden');
        $this->assertSame(400.0, $dokument->page(0)->width(), 'MediaBox wird nicht vom Elternknoten geerbt');
    }

    public function test_kein_pdf_wird_abgelehnt(): void
    {
        $this->expectException(PdfException::class);
        PdfDocument::open('Das ist eine Textdatei.');
    }

    public function test_das_original_bleibt_byte_fuer_byte_erhalten(): void
    {
        $original = $this->klassischesPdf();
        $stamper = new PdfStamper(PdfDocument::open($original));
        $stamper->add(PdfStamp::image(0, $this->unterschriftsBild(), 60, 600, 180, 50));
        $ergebnis = $stamper->build();

        $this->assertGreaterThan(strlen($original), strlen($ergebnis));
        $this->assertSame($original, substr($ergebnis, 0, strlen($original)),
            'Die Fortschreibung hat das Original veraendert - damit ist die Unversehrtheit nicht mehr belegbar.');
    }

    public function test_der_stempel_landet_im_seiteninhalt_und_in_den_ressourcen(): void
    {
        $stamper = new PdfStamper(PdfDocument::open($this->klassischesPdf()));
        $stamper->add(PdfStamp::image(0, $this->unterschriftsBild(), 60, 600, 180, 50));
        $stamper->add(PdfStamp::text(0, 'Max Mustermann', 60, 660, 180, 14, 11));
        $ergebnis = $stamper->build();

        // Anzeige-Koordinate y=600 bei 800 pt Seitenhoehe und 50 pt Feldhoehe
        // ergibt in PDF-Koordinaten 800 - 600 - 50 = 150.
        $this->assertStringContainsString('180 0 0 50 60 150 cm', $ergebnis);
        $this->assertStringContainsString('(Max Mustermann) Tj', $ergebnis);
        $this->assertStringContainsString('/XObject', $ergebnis);
        $this->assertStringContainsString('/D24Font', $ergebnis);
        // Der urspruengliche Seiteninhalt bleibt in der Kette.
        $this->assertStringContainsString('4 0 R', $ergebnis);
    }

    public function test_gedrehte_seite_bekommt_eine_transformationsmatrix(): void
    {
        $stamper = new PdfStamper(PdfDocument::open($this->klassischesPdf(90)));
        $stamper->add(PdfStamp::text(0, 'Quer', 10, 10, 100, 20, 10));

        // Bei /Rotate 90 zeigt der Betrachter die Seite quer; ohne diese
        // Matrix stuende die Unterschrift um 90 Grad verdreht am Rand.
        $this->assertStringContainsString('0 1 -1 0 600 0 cm', $stamper->build());
    }

    public function test_das_anzeige_mass_folgt_der_drehung(): void
    {
        $seite = PdfDocument::open($this->klassischesPdf(270))->page(0);

        $this->assertSame(800.0, $seite->displayWidth());
        $this->assertSame(600.0, $seite->displayHeight());
    }

    public function test_die_protokollseite_wird_angehaengt(): void
    {
        $stamper = new PdfStamper(PdfDocument::open($this->klassischesPdf()));
        $stamper->withProtocolPage('Signaturprotokoll', [
            ['title' => 'Unterzeichner 1: Max Mustermann', 'lines' => ['E-Mail: max@example.com']],
        ]);
        $ergebnis = $stamper->build();

        $neu = PdfDocument::open($ergebnis);
        $this->assertSame(2, $neu->pageCount(), 'Die Protokollseite fehlt im fertigen Dokument.');
        $this->assertStringContainsString('(Signaturprotokoll) Tj', $ergebnis);
        $this->assertStringContainsString('(E-Mail: max@example.com) Tj', $ergebnis);
    }

    public function test_umlaute_werden_fuer_die_standardschrift_umgesetzt(): void
    {
        $stamper = new PdfStamper(PdfDocument::open($this->klassischesPdf()));
        $stamper->add(PdfStamp::text(0, 'Jürgen Müller', 10, 10, 200, 14, 10));
        $ergebnis = $stamper->build();

        // Helvetica/WinAnsi kennt kein UTF-8: das "ü" muss als EIN Byte
        // (0xFC) dastehen, sonst steht im Dokument Kauderwelsch.
        $this->assertStringContainsString('(J'.chr(0xFC).'rgen M'.chr(0xFC).'ller) Tj', $ergebnis);
    }
}
