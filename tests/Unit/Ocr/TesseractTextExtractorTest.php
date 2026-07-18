<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\TesseractTextExtractor;
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
        $this->assertFalse((new TesseractTextExtractor())->isAvailable());
    }

    public function test_unavailable_with_nonexistent_binary_path(): void
    {
        config(['services.ocr.enabled' => true, 'services.ocr.tesseract_binary' => '/no/such/tesseract-binary']);
        $this->assertFalse((new TesseractTextExtractor())->isAvailable());
    }

    public function test_extract_returns_empty_string_when_disabled(): void
    {
        config(['services.ocr.enabled' => false]);
        $text = (new TesseractTextExtractor())->extract($this->makeTextImage('TEST'), 'image/jpeg');
        $this->assertSame('', $text);
    }

    public function test_extract_returns_empty_string_for_unreadable_input_instead_of_throwing(): void
    {
        config(['services.ocr.enabled' => true]);
        if (!$this->tesseractInstalledForRealCheck()) {
            $this->markTestSkipped('tesseract-Binary auf diesem System nicht installiert.');
        }
        $text = (new TesseractTextExtractor())->extract('kein-bild', 'image/jpeg');
        $this->assertSame('', $text);
    }

    public function test_real_tesseract_reads_image_text(): void
    {
        config(['services.ocr.enabled' => true]);
        if (!$this->tesseractInstalledForRealCheck()) {
            $this->markTestSkipped('tesseract-Binary auf diesem System nicht installiert.');
        }

        $extractor = new TesseractTextExtractor();
        $this->assertTrue($extractor->isAvailable());

        $text = $extractor->extract($this->makeTextImage('RECHNUNG'), 'image/jpeg');
        $this->assertStringContainsStringIgnoringCase('RECHNUNG', $text);
    }

    public function test_real_tesseract_reads_pdf_via_pdftoppm(): void
    {
        config(['services.ocr.enabled' => true]);
        if (!$this->tesseractInstalledForRealCheck() || !$this->pdftoppmInstalledForRealCheck()) {
            $this->markTestSkipped('tesseract/pdftoppm-Binary auf diesem System nicht installiert.');
        }

        $pdf = (new \App\Services\Pdf\ImagesToPdfService())->build([$this->makeTextImage('RECHNUNG')]);
        $text = (new TesseractTextExtractor())->extract($pdf, 'application/pdf');
        $this->assertStringContainsStringIgnoringCase('RECHNUNG', $text);
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
            $process = new \Symfony\Component\Process\Process([$binary, ...$args]);
            $process->setTimeout(5);
            $process->run();
            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }
}
