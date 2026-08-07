<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\InterlloydPoliceParser;
use Tests\TestCase;

/**
 * Versicherungsschein der Interlloyd Versicherungs-AG (z.B. Betriebs-
 * haftpflicht "BHV Business Secure" eines Paketdienstes). Synthetische
 * Daten, gleicher Aufbau wie das Original (Spaltenlayout, Makler rechts).
 */
class InterlloydPoliceParserTest extends TestCase
{
    private function scheinText(): string
    {
        return implode("\n", [
            '                                                         Versicherungsschein',
            '    Versicherungsnehmer                                  BHV Business Secure',
            '                                                         Nr.         : 000782243',
            '    Herrn',
            '    Karim Muster Test                                    Kunden-Nr. : 100660029',
            '    MUSTER Einzelunternehmen                             Es betreut Sie',
            '    Musterweg 71                                         Fonds Finanz',
            '    45964 Gladbeck                                       Maklerservice GmbH',
            '                                                         Riesstr. 25',
            '                                                         80992 München',
            '                                                         E-Mail: sach@fondsfinanz.de',
            '',
            'Versicherungsbeginn: 19.06.2026   Ablauf:   1.01.2028, jeweils 0:00 Uhr',
            '',
            'Zahlungsweise       : vierteljährlich',
            'Jahresprämie ohne Vers.-Steuer:          183,04 EUR',
            'Jahresprämie mit Vers.-Steuer :          217,82 EUR',
            'Prämie gemäß Zahlungsweise     :          57,18 EUR',
            '',
            'Düsseldorf, 16.06.2026',
            'Interlloyd VERSICHERUNGS-AG',
            '   Anlage zum Versicherungsschein Nr. 000782243',
            '',
            '                             Deckungsumfang - BHV Business Secure',
            'Risikoort: Musterweg 71,   45964 Gladbeck',
            'Fuhrbetriebe, Frachtführung                                    183,04',
            'pauschal Pers-,Sach-,Vermögens        10.000.000   EUR   2,0-fach maximiert',
            '',
            'Betriebsart: Paketdienst/ Paketzusteller (ohne Lager)',
        ]);
    }

    public function test_reads_bhv_police(): void
    {
        $r = (new InterlloydPoliceParser())->parse($this->scheinText());

        $this->assertNotNull($r);
        $this->assertSame('versicherungspolice', $r['type']);

        $p = $r['data']['person'];
        // Letzter Namensteil = Nachname (wie beim Allianz-Schein).
        $this->assertSame('Karim Muster', $p['first_name']);
        $this->assertSame('Test', $p['last_name']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('Musterweg', $p['street']);
        $this->assertSame('71', $p['house_number']);
        $this->assertSame('45964', $p['zip']);
        $this->assertSame('Gladbeck', $p['city']);
        $this->assertSame('MUSTER Einzelunternehmen', $p['company_name']);

        $v = $r['data']['versicherung'];
        $this->assertSame('Interlloyd Versicherungs-AG', $v['insurer']);
        // Die Vertragsnummer - NICHT die Kunden-Nr. (100660029).
        $this->assertSame('000782243', $v['contract_number']);
        $this->assertSame('betriebshaftpflicht', $v['sparte']);
        $this->assertSame('BHV Business Secure', $v['tariff']);
        $this->assertSame('2026-06-19', $v['start_date']);
        // Einstelliger Tag ("1.01.2028") wird korrekt gelesen.
        $this->assertSame('2028-01-01', $v['end_date']);
        // Der tatsaechlich wiederkehrende Brutto-Betrag gemaess Zahlungsweise.
        $this->assertSame(57.18, $v['premium_amount']);
        $this->assertSame('quarterly', $v['premium_interval']);
        $this->assertSame('vertrag', $v['document_stage']);

        // Kunden-Nr./Betriebsart in der Zusammenfassung, Makler bleibt draussen.
        $this->assertStringContainsString('Kunden-Nr. beim Versicherer: 100660029', $r['summary']);
        $this->assertStringContainsString('Paketdienst', $r['summary']);
        $this->assertStringNotContainsString('Fonds Finanz', $r['summary']);
        $this->assertSame([], $r['data']['kfz']);
        $this->assertSame([], $r['data']['bank']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $parser = new InterlloydPoliceParser();

        $this->assertNull($parser->parse('Irgendein anderes Dokument'));
        // Ohne Vertragsnummer lieber die normale Analyse.
        $this->assertNull($parser->parse("Interlloyd Versicherungs-AG\nVersicherungsschein\nohne Nummer"));
    }
}
