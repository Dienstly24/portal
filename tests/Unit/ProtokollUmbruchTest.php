<?php

namespace Tests\Unit;

use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\PdfStamper;
use PHPUnit\Framework\TestCase;
use Tests\Support\SignaturPdfFixtures;

/**
 * Das Signaturprotokoll im PDF (A4, 04.10.2026): bis dahin lief eine lange
 * Zeile ueber den rechten Rand, und alles, was nicht auf EINE Seite passte,
 * fiel still weg - bei mehreren Unterzeichnern genau der rechtliche Hinweis
 * mit dem Zustimmungstext.
 */
class ProtokollUmbruchTest extends TestCase
{
    public function test_umbruch_haelt_die_breite_ein_und_verliert_kein_wort(): void
    {
        $text = 'Zustimmungstext: '.str_repeat('Ich bestätige die Bedingungen ausdrücklich. ', 10).'ENDE';
        $zeilen = PdfStamper::umbrechen($text, 9, 470);

        $this->assertGreaterThan(1, count($zeilen));
        foreach ($zeilen as $z) {
            $this->assertLessThanOrEqual(470, PdfStamper::textBreite($z, 9));
        }
        $this->assertSame(preg_replace('/\s+/', ' ', trim($text)), implode(' ', $zeilen));
    }

    public function test_ein_hash_ohne_leerzeichen_wird_hart_geteilt(): void
    {
        $hash = str_repeat('ab12', 40);
        $zeilen = PdfStamper::umbrechen($hash, 9, 120);

        $this->assertSame($hash, implode('', $zeilen));
        foreach ($zeilen as $z) {
            $this->assertLessThanOrEqual(120, PdfStamper::textBreite($z, 9));
        }
    }

    public function test_langes_protokoll_bekommt_weitere_seiten_statt_abgeschnitten_zu_werden(): void
    {
        $abschnitte = [];
        for ($i = 1; $i <= 25; $i++) {
            $abschnitte[] = ['title' => 'Unterzeichner '.$i, 'lines' => ['E-Mail: p'.$i.'@example.com', 'Unterschrieben: 04.10.2026', 'Gerät: Mozilla/5.0']];
        }
        $abschnitte[] = ['title' => 'Rechtlicher Hinweis', 'lines' => ['LETZTE-ZEILE']];

        $stamper = new PdfStamper(PdfDocument::open(SignaturPdfFixtures::pdf()));
        $pdf = $stamper->withProtocolPage('Signaturprotokoll', $abschnitte)->build();

        $doc = PdfDocument::open($pdf);
        $this->assertGreaterThanOrEqual(3, $doc->pageCount(), 'Vertrag + mindestens zwei Protokollseiten');
        $this->assertStringContainsString('(LETZTE-ZEILE)', $pdf);
        $this->assertStringContainsString('Protokoll 2 / ', $pdf);
    }
}
