<?php

namespace Tests\Feature\Ai;

use App\Services\Ocr\TesseractTextExtractor;
use Tests\TestCase;

/**
 * Karten: zweiter OCR-Lauf als EIN Textblock (PSM 6), wenn der erste Lauf
 * fast nichts liefert (Betreiber-Meldung 20.11.2026, an BIG-Karte und
 * girocard gemessen). Geprueft mit einem Ersatz-Tesseract, der je nach
 * --psm anders antwortet - die Auswahl-Regel ist damit unabhaengig von der
 * installierten Tesseract-Version pruefbar.
 */
class OcrKartenNachlesenTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ocr_fake_'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** Ersatz-Tesseract: ohne --psm $ersterLauf, mit --psm 6 $blockLauf; jeder PSM-6-Aufruf hinterlaesst eine Marke. */
    private function fakeTesseract(string $ersterLauf, string $blockLauf): void
    {
        file_put_contents($this->dir.'/erster.txt', $ersterLauf);
        file_put_contents($this->dir.'/block.txt', $blockLauf);
        $script = $this->dir.'/tesseract';
        file_put_contents($script, "#!/bin/sh\n"
            ."case \"\$*\" in *--version*) echo 'tesseract 5'; exit 0;; esac\n"
            ."case \"\$*\" in *'--psm 6'*) touch '{$this->dir}/psm6'; cat '{$this->dir}/block.txt';; *) cat '{$this->dir}/erster.txt';; esac\n");
        chmod($script, 0755);
        config(['services.ocr.enabled' => true, 'services.ocr.tesseract_binary' => $script, 'services.ocr.upscale_below_px' => 0]);
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(20, 10);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    public function test_duenne_karte_nimmt_den_block_lauf(): void
    {
        $this->fakeTesseract("Ihr 24h-Direktservice\n8027600", "3. Name\nMUSTER\nVornamen Geburtsdatum\nMax 01/02/1990\nX123456785 103501080 - BIG");

        $text = app(TesseractTextExtractor::class)->extract($this->png(), 'image/png');

        $this->assertStringContainsString('X123456785', $text);
        $this->assertStringContainsString('MUSTER', $text);
    }

    public function test_bei_gleichstand_bleibt_der_erste_lauf(): void
    {
        $this->fakeTesseract('IBAN Gueltig bis', '| ~ ‏ام‎');

        $text = app(TesseractTextExtractor::class)->extract($this->png(), 'image/png');

        $this->assertSame('IBAN Gueltig bis', $text);
    }

    public function test_langer_text_bekommt_keinen_zweiten_lauf(): void
    {
        $this->fakeTesseract(str_repeat("Sehr geehrte Damen und Herren, dies ist ein Brief.\n", 20), 'egal');

        app(TesseractTextExtractor::class)->extract($this->png(), 'image/png');

        $this->assertFileDoesNotExist($this->dir.'/psm6', 'Ein Brief darf keinen zweiten Lauf kosten.');
    }

    public function test_lesbare_woerter_zaehlt_nur_latein_und_ziffern(): void
    {
        $this->assertSame(2, TesseractTextExtractor::lesbareWoerter('Max 01/02/1990 ‏ام‎ | ~'));
        $this->assertSame(3, TesseractTextExtractor::lesbareWoerter('MUSTER Grüße X123456785'));
    }
}
