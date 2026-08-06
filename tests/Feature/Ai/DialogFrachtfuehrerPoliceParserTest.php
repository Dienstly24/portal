<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\DialogFrachtfuehrerPoliceParser;
use Tests\TestCase;

/**
 * Versicherungsschein der Dialog Versicherung AG zur Frachtfuehrerhaftungs-
 * versicherung (Verkehrshaftungsschutz). Synthetische Daten, gleicher Aufbau
 * wie das Original.
 */
class DialogFrachtfuehrerPoliceParserTest extends TestCase
{
    private function scheinText(): string
    {
        return implode("\n", [
            '         1. Kopie für 00800295148',
            '          Dialog Versicherung AG, 81718 München',
            '                                                                        Ihr Ansprechpartner zum Vertrag:',
            '                                                                        Verena Muster',
            '          *2-GK-79.319.065-4 800/295148*                                Telefon: (0 89) 51 21-57 75',
            '          Firma                                                         service@dialog-versicherung.de',
            '          MUSTER                                                        Sie erreichen uns Montag bis',
            '          Karim Muster Test                                             Freitag von 8 bis 18 Uhr',
            '          Musterweg 71                                                  17.06.2026',
            '          45964 Gladbeck',
            '',
            '         Versicherungsschein                                       Ausfertigungstag: 17.06.2026',
            '         Verkehrshaftungsschutz-Nr. 2-GK-79.319.065-4',
            '         Versicherungsnehmer: MUSTER',
            '',
            '         Versicherungsvertrag:                                     Ausfertigungsgrund:',
            '         Frachtführerhaftungsversicherung                          Neuantrag',
            '',
            '           Beginn des Vertrags:             19.06.2026 12.00 Uhr   Ablauf des Vertrags: 01.01.2028 12.00 Uhr',
            '',
            '           Versichertes Fahrzeug',
            '           Einschluss:                      19.06.2026',
            '           Amtl. Kennzeichen:               DU-KA 684',
            '           Fahrzeugart:                     LKW',
            '           Zulässiges Gewicht:              3,50 Tonnen',
            '',
            '           Jahresbeitrag netto                                     210,00 EUR',
            '',
            '           Selbstbehalt',
            '           Der Selbstbehalt des Versicherungsnehmers gemäß Ziffer 5.1',
            '           der AVB Frachtführer 2010 / 2016 beträgt:                   250 EUR',
            '',
            '           Jahresbeitrag                                           223,01 EUR',
            '           Darin sind berücksichtigt',
            '             .   19,00 % Versicherungsteuer',
            '',
            '           Der Beitrag ist vierteljährlich im Voraus zu zahlen.',
        ]);
    }

    public function test_reads_frachtfuehrer_police(): void
    {
        $r = (new DialogFrachtfuehrerPoliceParser())->parse($this->scheinText());

        $this->assertNotNull($r);
        $this->assertSame('versicherungspolice', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Karim Muster', $p['first_name']);
        $this->assertSame('Test', $p['last_name']);
        $this->assertSame('Musterweg', $p['street']);
        $this->assertSame('71', $p['house_number']);
        $this->assertSame('45964', $p['zip']);
        $this->assertSame('Gladbeck', $p['city']);
        $this->assertSame('MUSTER', $p['company_name']);

        $v = $r['data']['versicherung'];
        $this->assertSame('Dialog Versicherung AG', $v['insurer']);
        $this->assertSame('2-GK-79.319.065-4', $v['contract_number']);
        $this->assertSame('frachtfuehrerhaftpflicht', $v['sparte']);
        $this->assertSame('2026-06-19', $v['start_date']);
        $this->assertSame('2028-01-01', $v['end_date']);
        // BRUTTO-Jahresbeitrag - nicht die "netto"-Zeile (210,00).
        $this->assertSame(223.01, $v['premium_amount']);
        $this->assertSame('yearly', $v['premium_interval']);
        $this->assertSame('vertrag', $v['document_stage']);

        // Das versicherte Fahrzeug steht NUR in der Zusammenfassung - nie in
        // data.kfz (dasselbe Fahrzeug hat eine eigene Kfz-Versicherung).
        $this->assertSame([], $r['data']['kfz']);
        $this->assertStringContainsString('DU-KA 684', $r['summary']);
        $this->assertStringContainsString('Selbstbehalt 250 EUR', $r['summary']);
        $this->assertStringContainsString('vierteljaehrlich', $r['summary']);
        $this->assertStringContainsString('Neuantrag', $r['summary']);
        // Der Service-Kontakt der Versicherung wird nie Kundendatum.
        $this->assertArrayNotHasKey('email', $p);
        $this->assertArrayNotHasKey('phone', $p);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $parser = new DialogFrachtfuehrerPoliceParser();

        $this->assertNull($parser->parse('Irgendein anderes Dokument'));
        // Der Fonds-Finanz-Deckungsauftrag zur selben Sparte hat seinen
        // eigenen Parser und nennt die Dialog Versicherung nicht.
        $this->assertNull($parser->parse(
            "Deckungsauftrag zur Frachtführerhaftpflicht\nVorgangsnummer: 1234567"
        ));
    }
}
