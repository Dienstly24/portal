<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Services\Ai\TemplateParsers\BankkarteParser;
use Tests\TestCase;

/**
 * Bankkarte (girocard) gratis lesen statt per KI (Betreiber-Meldung
 * 20.11.2026: eine Bankkarte lief in die KI-Analyse).
 */
class BankkarteParserTest extends TestCase
{
    private function karte(string $iban = 'DE89 3704 0044 0532 0130 00'): string
    {
        return "Sparkasse Musterstadt\ngirocard\n{$iban}\nMAX JONAS MUSTERMANN\n12/28\nDebit Mastercard";
    }

    public function test_liest_iban_und_karteninhaber_von_der_girocard(): void
    {
        $r = (new BankkarteParser)->parse($this->karte());

        $this->assertNotNull($r);
        $this->assertSame('sepa_mandat', $r['type']);
        $this->assertSame('DE89370400440532013000', $r['data']['bank']['iban']);
        $this->assertSame('Max Jonas Mustermann', $r['data']['bank']['account_holder']);
        $this->assertSame('Mustermann', $r['data']['person']['last_name']);
        $this->assertStringContainsString('Sparkasse Musterstadt', $r['summary']);
        $this->assertStringContainsString('12/28', $r['summary']);
    }

    public function test_ocr_verwechslung_in_der_iban_wird_nur_mit_gueltiger_pruefziffer_repariert(): void
    {
        // "O" statt "0" - Pruefziffer stimmt nach Ruecksetzung.
        $r = (new BankkarteParser)->parse($this->karte('DE89 37O4 0044 0532 0130 00'));
        $this->assertSame('DE89370400440532013000', $r['data']['bank']['iban']);

        // Falsche Pruefziffer -> Parser greift NICHT (keine geratene IBAN).
        $this->assertNull((new BankkarteParser)->parse($this->karte('DE88 3704 0044 0532 0130 00')));
    }

    public function test_greift_nicht_bei_sepa_mandat_oder_langem_schreiben(): void
    {
        $mandat = "SEPA-Lastschriftmandat\nIch ermaechtige ... girocard\nDE89 3704 0044 0532 0130 00\nMax Mustermann";
        $this->assertNull((new BankkarteParser)->parse($mandat));

        $lang = $this->karte()."\n".str_repeat('Lorem ipsum dolor sit amet. ', 40);
        $this->assertNull((new BankkarteParser)->parse($lang));

        // Ohne Kartenmerkmal (z.B. blosser Zettel mit IBAN) nicht zustaendig.
        $this->assertNull((new BankkarteParser)->parse("MAX MUSTERMANN\nDE89 3704 0044 0532 0130 00"));
    }

    public function test_unsicherer_name_wird_nicht_geraten(): void
    {
        $text = "Sparkasse\ngirocard\nDE89 3704 0044 0532 0130 00\nMAX MUSTERMANN\nERIKA MUSTERFRAU";
        $r = (new BankkarteParser)->parse($text);

        $this->assertSame('DE89370400440532013000', $r['data']['bank']['iban']);
        $this->assertArrayNotHasKey('account_holder', $r['data']['bank']);
        $this->assertSame([], $r['data']['person']);
    }

    /**
     * Gemessener OCR-Text der Karte (20.11.2026, Daten ersetzt): Logos sind
     * Grafiken und kommen NICHT als "girocard" an; Rauschen aus dem
     * Kartenmotiv ("NER TR") sieht aus wie ein zweiter Name. Massgeblich ist
     * die Zeile direkt ueber "IBAN".
     */
    public function test_gemessener_ocr_text_ohne_logo_wort_mit_motiv_rauschen(): void
    {
        $text = implode("\n", [
            '5 Musterstadt A| IV!',
            '- ——~ ae — ‏ام‎',
            'u (a a ar -. |',
            'ee ca',
            'NER TR',
            'Max Jonas Mustermann',
            'IBAN - Gültig bis',
            'DE89 3704 0044 0532 0130 00. 12/28',
        ]);

        $r = (new BankkarteParser)->parse($text);

        $this->assertNotNull($r);
        $this->assertSame('DE89370400440532013000', $r['data']['bank']['iban']);
        $this->assertSame('Max Jonas Mustermann', $r['data']['bank']['account_holder']);
    }

    /** Der Server-Text: Name verstuemmelt, "8" als "B" gelesen - IBAN ja, Name NIE geraten. */
    public function test_verstuemmelter_name_wird_nicht_uebernommen(): void
    {
        $text = "Jonas Mustermann 9 شفع ورور\n\nIBAN = Gültig bis\n\nDEB9 3704 0044 0532 0130 00. 12/23";

        $r = (new BankkarteParser)->parse($text);

        $this->assertNotNull($r);
        $this->assertSame('DE89370400440532013000', $r['data']['bank']['iban']);
        $this->assertArrayNotHasKey('account_holder', $r['data']['bank']);
        $this->assertSame([], $r['data']['person']);
    }

    public function test_echte_kette_aus_dem_container_liest_die_karte_gratis(): void
    {
        $r = app(DocumentTemplateParser::class)->parse($this->karte());

        $this->assertNotNull($r);
        $this->assertSame('sepa_mandat', $r['type']);
        $this->assertSame('DE89370400440532013000', $r['data']['bank']['iban']);
    }
}
