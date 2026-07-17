<?php

namespace Tests\Unit;

use App\Services\Pdf\ImagesToPdfService;
use PHPUnit\Framework\TestCase;

class ImagesToPdfServiceTest extends TestCase
{
    private function makeJpeg(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 240, 240, 240));
        ob_start();
        imagejpeg($img, null, 80);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    private function makePng(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 120, 60));
        ob_start();
        imagepng($img);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    public function test_builds_multipage_pdf_from_jpegs(): void
    {
        $pdf = (new ImagesToPdfService())->build([
            $this->makeJpeg(400, 600),
            $this->makeJpeg(600, 400),
            $this->makeJpeg(300, 300),
        ]);

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('/Count 3', $pdf);
        $this->assertStringContainsString('/Filter /DCTDecode', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
        // xref muss auf jedes Objekt zeigen: 3 Objekte je Seite + Catalog + Pages + Frei-Eintrag.
        $this->assertSame(3 * 3 + 2 + 1, preg_match_all('/^\d{10} \d{5} [nf] $/m', $pdf));
    }

    public function test_converts_png_pages_to_jpeg(): void
    {
        $pdf = (new ImagesToPdfService())->build([$this->makePng(200, 260)]);

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('/Count 1', $pdf);
        $this->assertStringContainsString('/Filter /DCTDecode', $pdf); // PNG wurde nach JPEG konvertiert
    }

    public function test_rejects_unreadable_page(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ImagesToPdfService())->build(['kein-bild']);
    }
}
