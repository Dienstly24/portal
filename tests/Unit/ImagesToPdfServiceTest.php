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

    /** Minimalen EXIF-APP1-Block mit einem Orientation-Tag in ein JPEG einfuegen. */
    private function makeJpegWithOrientation(int $w, int $h, int $orientation): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 200, 200));
        ob_start();
        imagejpeg($img, null, 85);
        $jpeg = (string) ob_get_clean();
        imagedestroy($img);

        // Minimaler TIFF-Header (Intel/Little-Endian) mit einem IFD-Eintrag: Orientation (Tag 0x0112, SHORT).
        $tiff = "II" . pack('v', 42) . pack('V', 8);
        $tiff .= pack('v', 1);
        $tiff .= pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', $orientation) . pack('v', 0);
        $tiff .= pack('V', 0);

        $exif = "Exif\0\0" . $tiff;
        $app1 = "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;

        return substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
    }

    public function test_corrects_jpeg_exif_orientation(): void
    {
        if (!function_exists('exif_read_data')) {
            $this->markTestSkipped('exif-Erweiterung nicht installiert.');
        }

        // 100x200 (Hochformat), Orientation=6 = "90 Grad im Uhrzeigersinn drehen"
        // -> nach der Korrektur ist das eingebettete Bild 200x100 (Querformat).
        $jpeg = $this->makeJpegWithOrientation(100, 200, 6);
        $pdf = (new ImagesToPdfService())->build([$jpeg]);

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertMatchesRegularExpression('/\/Width 200 \/Height 100/', $pdf);
    }

    public function test_leaves_jpeg_without_orientation_tag_unchanged_dimensions(): void
    {
        $jpeg = $this->makeJpeg(100, 200);
        $pdf = (new ImagesToPdfService())->build([$jpeg]);

        $this->assertMatchesRegularExpression('/\/Width 100 \/Height 200/', $pdf);
    }
}
