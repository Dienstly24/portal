<?php

namespace Tests\Unit;

use App\Services\Ai\HeuristicDocumentClassifier;
use Tests\TestCase;

/**
 * OCR-Fallback ohne KI-Anbieter: konservative Stichwort-/Regex-Erkennung.
 * Schwerpunkt der Tests: lieber ein leeres Feld als eine falsche
 * Kundenangabe aus zusammenhanglosem OCR-Freitext.
 */
class HeuristicDocumentClassifierTest extends TestCase
{
    public function test_returns_null_for_empty_text(): void
    {
        $this->assertNull((new HeuristicDocumentClassifier())->classify('   '));
    }

    public function test_detects_type_by_keyword(): void
    {
        $result = (new HeuristicDocumentClassifier())->classify("BUNDESREPUBLIK DEUTSCHLAND\nPERSONALAUSWEIS\nMax Mustermann");
        $this->assertSame('personalausweis', $result['type']);
        $this->assertSame(40, $result['confidence']);
    }

    public function test_unknown_text_falls_back_to_sonstiges_with_low_confidence(): void
    {
        $result = (new HeuristicDocumentClassifier())->classify('Irgendein Text ohne erkennbare Stichwoerter.');
        $this->assertSame('sonstiges', $result['type']);
        $this->assertSame(20, $result['confidence']);
    }

    public function test_extracts_iban_from_clearly_labeled_line(): void
    {
        $result = (new HeuristicDocumentClassifier())->classify("SEPA-Lastschriftmandat\nIBAN: DE89 3704 0044 0532 0130 00\nKontoinhaber: Erika Beispiel");
        $this->assertSame('sepa_mandat', $result['type']);
        $this->assertSame('DE89370400440532013000', $result['data']['bank']['iban']);
    }

    public function test_extracts_iban_when_line_is_only_the_iban(): void
    {
        $result = (new HeuristicDocumentClassifier())->classify("Rechnung\nDE89370400440532013000\nBetrag: 49,90 EUR");
        $this->assertSame('DE89370400440532013000', $result['data']['bank']['iban']);
    }

    public function test_does_not_fabricate_iban_from_unrelated_running_text(): void
    {
        // Absichtlich KEIN Label "IBAN" und KEINE eigene Zeile mit reinem
        // IBAN-Format - darf keinen falschen Treffer erzeugen, auch wenn
        // durch Zufall 22 alphanumerische Zeichen aneinandergereiht sind.
        $result = (new HeuristicDocumentClassifier())->classify(
            "Dies ist ein laengerer Fließtext ohne jede Bankverbindung, der rein zufaellig viele Buchstaben und Zahlen enthaelt wie AB1234567890 zum Beispiel."
        );
        $this->assertArrayNotHasKey('iban', $result['data']['bank']);
    }

    public function test_extracts_vin_and_license_plate(): void
    {
        $result = (new HeuristicDocumentClassifier())->classify("Zulassungsbescheinigung Teil I\nFIN: WAUZZZ8V5KA123456\nKennzeichen: B-AB 1234");
        $this->assertSame('fahrzeugschein', $result['type']);
        $this->assertSame('WAUZZZ8V5KA123456', $result['data']['kfz']['vin']);
        $this->assertSame('B-AB 1234', $result['data']['kfz']['license_plate']);
    }

    public function test_extracts_email(): void
    {
        $result = (new HeuristicDocumentClassifier())->classify('Kontakt: max.mustermann@example.de fuer Rueckfragen.');
        $this->assertSame('max.mustermann@example.de', $result['data']['person']['email']);
    }

    public function test_rejects_short_code_that_is_not_a_valid_vin(): void
    {
        // Nur 10 Zeichen - die VIN-Regex verlangt genau 17, kein Treffer.
        $result = (new HeuristicDocumentClassifier())->classify("Kurzcode: AB12345678\nSonstiger Text.");
        $this->assertArrayNotHasKey('vin', $result['data']['kfz']);
    }
}
