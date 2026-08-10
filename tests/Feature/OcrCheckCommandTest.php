<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Rauchtest fuer den Diagnose-Befehl `ocr:check`. Das konkrete Ergebnis
 * (0/1) haengt von den auf dem Runner installierten Systembinaries ab -
 * geprueft wird daher nur, dass der Befehl sauber laeuft und die
 * Konfiguration samt der konfigurierten OCR-Sprachen ausgibt.
 */
class OcrCheckCommandTest extends TestCase
{
    public function test_ocr_check_runs_and_reports_configuration(): void
    {
        config(['services.ocr.languages' => 'deu+eng+ara']);

        $exit = Artisan::call('ocr:check');
        $output = Artisan::output();

        $this->assertContains($exit, [0, 1], 'Der Befehl soll sauber mit 0 oder 1 enden, nicht werfen.');
        $this->assertStringContainsString('OCR_LANGUAGES', $output);
        $this->assertStringContainsString('deu+eng+ara', $output);
    }
}
