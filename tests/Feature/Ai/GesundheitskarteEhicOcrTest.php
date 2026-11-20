<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Services\Ai\TemplateParsers\GesundheitskarteParser;
use Tests\TestCase;

/**
 * EHIC-Rueckseite (BIG direkt gesund), Betreiber-Meldung 20.11.2026: die
 * Karte lief zur bezahlten KI. Der Text unten ist der GEMESSENE OCR-Text
 * (Tesseract, Block-Lesung) mit ersetzten Daten - Aufbau, Rauschen und
 * Lesefehler sind original:
 *  - die Ueberschrift kommt verlesen an ("KRANKENVERSICRERUNGSKARTE"),
 *  - hinter "3. Name" haengt Rauschen ("Bane ee |"),
 *  - Vornamen und Geburtsdatum stehen in EINER Zeile.
 */
class GesundheitskarteEhicOcrTest extends TestCase
{
    private function text(string $titel = 'RC EURUPAISTCHE KRANKENVERSICRERUNGSKARTE'): string
    {
        return implode("\n", [
            $titel,
            'Ihr 24h-Direktservice: +49 (01231 5557-0',
            '*#t.',
            'Mit meiner ‏امع مي‎ teat ‏ونس‎ Ich Hass-Iich rx',
            'bei der BIG versichert bd Biber WED er Acht übertragbar. >',
            '— — ease en',
            '3. Name Bane ee |',
            'MUSTER BEISPIEL',
            '8 Vornamen 00000 5. Geburtsdatum',
            'Max 01/02/1990',
            '& Petsont 1 ¢ Kannnummer des Trägers',
            '| X123456785 103501080 - BIG',
            '1 8. Kennnurmmer de 7 9. Ablaufdatum',
            '> 80276001234567890123 30/09/2030',
        ]);
    }

    public function test_gemessener_ocr_text_wird_gratis_gelesen(): void
    {
        $r = (new GesundheitskarteParser)->parse($this->text());

        $this->assertNotNull($r, 'Die Karte muss trotz verlesener Ueberschrift erkannt werden.');
        $this->assertSame('gesundheitskarte', $r['type']);
        $this->assertSame('Max', $r['data']['person']['first_name']);
        $this->assertSame('Muster Beispiel', $r['data']['person']['last_name']);
        $this->assertSame('1990-02-01', $r['data']['person']['birth_date']);
        $this->assertSame('X123456785', $r['data']['gesundheit']['health_insurance_number']);
        $this->assertSame('BIG', $r['data']['gesundheit']['health_insurance_company']);
    }

    public function test_ohne_ueberschrift_genuegen_die_genormten_ehic_felder(): void
    {
        $r = (new GesundheitskarteParser)->parse($this->text('Tn aU RU PAISUHE'));

        $this->assertNotNull($r);
        $this->assertSame('X123456785', $r['data']['gesundheit']['health_insurance_number']);
    }

    public function test_rauschen_hinter_der_beschriftung_wird_nie_zum_namen(): void
    {
        $r = (new GesundheitskarteParser)->parse($this->text());

        $this->assertStringNotContainsString('Bane', (string) $r['data']['person']['last_name']);
    }

    public function test_echte_kette_aus_dem_container(): void
    {
        $r = app(DocumentTemplateParser::class)->parse($this->text());

        $this->assertNotNull($r);
        $this->assertSame('gesundheitskarte', $r['type']);
    }
}
