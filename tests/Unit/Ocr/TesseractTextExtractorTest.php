<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\TesseractTextExtractor;
use App\Services\Pdf\ImagesToPdfService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Reale Tesseract-/pdftoppm-Aufrufe laufen nur, wenn die Binaries auf
 * dieser Maschine tatsaechlich vorhanden sind (markTestSkipped sonst) -
 * die OCR-Stufe ist ein optionales Server-Paket, kein Composer-Paket, und
 * darf die CI nicht rot machen, wenn der Runner es nicht installiert hat.
 * Das Konfigurations-/Verfuegbarkeits-Verhalten (Standard AUS) wird
 * unabhaengig davon immer geprueft.
 */
class TesseractTextExtractorTest extends TestCase
{
    private function makeTextImage(string $text): string
    {
        $img = imagecreatetruecolor(500, 150);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $white);
        imagestring($img, 5, 10, 10, $text, $black);
        ob_start();
        imagejpeg($img, null, 95);
        $binary = (string) ob_get_clean();
        imagedestroy($img);
        return $binary;
    }

    public function test_disabled_by_default_even_if_binary_exists(): void
    {
        config(['services.ocr.enabled' => false]);
        $this->assertFalse((new TesseractTextExtractor)->isAvailable());
    }

    public function test_unavailable_with_nonexistent_binary_path(): void
    {
        config(['services.ocr.enabled' => true, 'services.ocr.tesseract_binary' => '/no/such/tesseract-binary']);
        $this->assertFalse((new TesseractTextExtractor)->isAvailable());
    }

    public function test_extract_returns_empty_string_when_disabled(): void
    {
        config(['services.ocr.enabled' => false]);
        $text = (new TesseractTextExtractor)->extract($this->makeTextImage('TEST'), 'image/jpeg');
        $this->assertSame('', $text);
    }

    public function test_extract_returns_empty_string_for_unreadable_input_instead_of_throwing(): void
    {
        config(['services.ocr.enabled' => true]);
        if (! $this->tesseractInstalledForRealCheck()) {
            $this->markTestSkipped('tesseract-Binary auf diesem System nicht installiert.');
        }
        $text = (new TesseractTextExtractor)->extract('kein-bild', 'image/jpeg');
        $this->assertSame('', $text);
    }

    public function test_real_tesseract_reads_image_text(): void
    {
        config(['services.ocr.enabled' => true]);
        if (! $this->tesseractInstalledForRealCheck()) {
            $this->markTestSkipped('tesseract-Binary auf diesem System nicht installiert.');
        }

        $extractor = new TesseractTextExtractor;
        $this->assertTrue($extractor->isAvailable());

        $text = $extractor->extract($this->makeTextImage('RECHNUNG'), 'image/jpeg');
        $this->assertStringContainsStringIgnoringCase('RECHNUNG', $text);
    }

    /**
     * Lehre 16.08.2026 (Bildschirmfoto eines Vertriebsportals): kleine
     * Screenshots kommen mit ~150 dpi und feiner Schrift - Tesseract
     * verwechselt darin aehnliche Zeichen ("NOLADE21RDB" -> "NOLADE2IRDB").
     * Kleine Bilder werden deshalb vor der OCR verdoppelt. Geprueft wird das
     * Ergebnis (feine Schrift wird lesbar), nicht die interne Umsetzung.
     */
    public function test_small_screenshot_is_upscaled_before_ocr(): void
    {
        // Geprueft wird die Vorstufe direkt (ohne Tesseract), damit der Test
        // ueberall deterministisch laeuft: kleine Bilder werden verdoppelt,
        // grosse bleiben unveraendert, Abschalten wird respektiert.
        $dir = sys_get_temp_dir().'/dienstly_upscale_test_'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);

        try {
            $klein = $dir.'/klein.png';
            $img = imagecreatetruecolor(400, 200);
            imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
            imagepng($img, $klein);
            imagedestroy($img);

            $extractor = new TesseractTextExtractor;
            $aufruf = function (string $pfad) use ($extractor, $dir): string {
                $method = new \ReflectionMethod($extractor, 'upscaleIfSmall');
                $method->setAccessible(true);
                return (string) $method->invoke($extractor, $pfad, $dir);
            };

            config(['services.ocr.upscale_below_px' => 2600]);
            $gross = $aufruf($klein);
            $this->assertNotSame($klein, $gross, 'Kleines Bild muss vergroessert werden.');
            $this->assertSame([800, 400], array_slice((array) getimagesize($gross), 0, 2));

            // Abschaltbar - dann bleibt das Original.
            config(['services.ocr.upscale_below_px' => 0]);
            $this->assertSame($klein, $aufruf($klein));

            // Grosse Aufnahmen (Handyfotos) bleiben unveraendert: Skalieren
            // bringt dort nichts und kostet nur Rechenzeit.
            config(['services.ocr.upscale_below_px' => 300]);
            $this->assertSame($klein, $aufruf($klein));
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }

    public function test_real_tesseract_reads_pdf_via_pdftoppm(): void
    {
        config(['services.ocr.enabled' => true]);
        if (! $this->tesseractInstalledForRealCheck() || ! $this->pdftoppmInstalledForRealCheck()) {
            $this->markTestSkipped('tesseract/pdftoppm-Binary auf diesem System nicht installiert.');
        }

        $pdf = (new ImagesToPdfService)->build([$this->makeTextImage('RECHNUNG')]);
        $text = (new TesseractTextExtractor)->extract($pdf, 'application/pdf');
        $this->assertStringContainsStringIgnoringCase('RECHNUNG', $text);
    }

    public function test_respects_max_pages_limit_to_stay_within_time_budget(): void
    {
        config([
            'services.ocr.enabled' => true,
            'services.ocr.languages' => 'eng',
            'services.ocr.max_pages' => 1,
        ]);
        if (! $this->tesseractInstalledForRealCheck() || ! $this->pdftoppmInstalledForRealCheck()) {
            $this->markTestSkipped('tesseract/pdftoppm-Binary auf diesem System nicht installiert.');
        }

        // 3-seitiges PDF, jede Seite mit eindeutigem Marker.
        $pages = [];
        foreach (['ALPHAONE', 'BRAVOTWO', 'CHARLIETHREE'] as $marker) {
            $pages[] = $this->makeTextImage($marker);
        }
        $pdf = (new ImagesToPdfService)->build($pages);

        $text = (new TesseractTextExtractor)->extract($pdf, 'application/pdf');

        // Nur die erste Seite wird OCR-verarbeitet (max_pages=1) -> Marker der
        // dritten Seite darf NICHT auftauchen. Schuetzt vor Zeit-/Timeout-
        // Explosion bei vielseitigen PDFs auf schwacher Hardware.
        $this->assertStringContainsStringIgnoringCase('ALPHAONE', $text);
        $this->assertStringNotContainsStringIgnoringCase('CHARLIETHREE', $text);
    }

    public function test_pdf_pages_are_separated_by_form_feed(): void
    {
        config([
            'services.ocr.enabled' => true,
            'services.ocr.languages' => 'eng',
        ]);
        if (! $this->tesseractInstalledForRealCheck() || ! $this->pdftoppmInstalledForRealCheck()) {
            $this->markTestSkipped('tesseract/pdftoppm-Binary auf diesem System nicht installiert.');
        }

        $pdf = (new ImagesToPdfService)->build([
            $this->makeTextImage('ALPHAONE'),
            $this->makeTextImage('BRAVOTWO'),
        ]);

        $text = (new TesseractTextExtractor)->extract($pdf, 'application/pdf');

        // Seiten sind wie bei pdftotext mit Form-Feed getrennt - nur so gilt
        // die Regel "Auftrag = nur die Formularseite" (ReadsDocumentPages)
        // auch fuer SCANS; sonst wuerde der Rechtstext der Folgeseiten die
        // Vorlagen-Parser wieder verstummen lassen.
        $this->assertStringContainsString("\f", $text);
        [$first] = explode("\f", $text, 2);
        $this->assertStringContainsStringIgnoringCase('ALPHAONE', $first);
        $this->assertStringNotContainsStringIgnoringCase('BRAVOTWO', $first);
    }

    private function tesseractInstalledForRealCheck(): bool
    {
        return $this->binaryUsable('tesseract', ['--version']);
    }

    private function pdftoppmInstalledForRealCheck(): bool
    {
        return $this->binaryUsable('pdftoppm', ['-v']);
    }

    private function binaryUsable(string $binary, array $args): bool
    {
        try {
            $process = new Process([$binary, ...$args]);
            $process->setTimeout(5);
            $process->run();
            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }
}
