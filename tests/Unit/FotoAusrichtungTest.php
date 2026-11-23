<?php

namespace Tests\Unit;

use App\Services\Ocr\TesseractTextExtractor;
use App\Support\ExifAusrichtung;
use App\Support\FinVerlesen;
use PHPUnit\Framework\TestCase;

/**
 * KI-132: hochkant gespeicherte Handyfotos (EXIF-Orientation) und verlesene FIN.
 */
class FotoAusrichtungTest extends TestCase
{
    /** JPEG 40x20 mit EXIF-Orientation (GD schreibt kein EXIF - Segment von Hand). */
    private function jpegMitAusrichtung(int $orientierung): string
    {
        $bild = imagecreatetruecolor(40, 20);
        ob_start();
        imagejpeg($bild);
        $jpg = (string) ob_get_clean();
        $tiff = "MM\x00\x2a\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientierung)."\x00\x00\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00".$tiff;

        return "\xFF\xD8\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpg, 2);
    }

    public function test_ausrichtung_wird_gelesen_und_angewendet(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('exif-Erweiterung fehlt.');
        }
        $pfad = tempnam(sys_get_temp_dir(), 'exif');
        file_put_contents($pfad, $this->jpegMitAusrichtung(8));

        $this->assertSame(8, ExifAusrichtung::lesen($pfad));
        $bild = ExifAusrichtung::anwenden(imagecreatefromstring((string) file_get_contents($pfad)), 8);
        $this->assertSame([20, 40], [imagesx($bild), imagesy($bild)]);
        @unlink($pfad);
    }

    /** [ohne Fix rot] Die OCR richtet das Foto VOR dem Lesen auf. */
    public function test_ocr_richtet_das_foto_auf(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('exif-Erweiterung fehlt.');
        }
        $dir = sys_get_temp_dir().'/aufr_'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/input.jpg', $this->jpegMitAusrichtung(6));

        $methode = new \ReflectionMethod(TesseractTextExtractor::class, 'aufrichten');
        $ergebnis = $methode->invoke(new TesseractTextExtractor, $dir.'/input.jpg', $dir);

        $this->assertNotSame($dir.'/input.jpg', $ergebnis);
        $this->assertSame([20, 40], array_slice((array) getimagesize($ergebnis), 0, 2));
        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    }

    public function test_fin_ein_verwechselbares_zeichen(): void
    {
        $this->assertTrue(FinVerlesen::einZeichen('WEA0B000X0AB12345', 'WBA0B000X0AB12345'));
        $this->assertFalse(FinVerlesen::einZeichen('WCA0B000X0AB12345', 'WBA0B000X0AB12345'), 'C/B ist kein OCR-Paar.');
        $this->assertFalse(FinVerlesen::einZeichen('WEA0B000X0AB12399', 'WBA0B000X0AB12345'));
        $this->assertFalse(FinVerlesen::einZeichen('WBA0B000X0AB12345', 'WBA0B000X0AB12345'), 'Gleich ist kein Verlesen.');
    }
}
