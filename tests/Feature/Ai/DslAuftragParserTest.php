<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\DslAuftragParser;
use Tests\TestCase;

/**
 * Parser fuer die DSL-/Internet-Auftragsbestaetigung (z.B. CHECK24
 * "Ihr DSL Anschluss"): liest Kundendaten + Tarif gratis aus dem Auftrag.
 */
class DslAuftragParserTest extends TestCase
{
    private function auftragText(): string
    {
        return implode("\n", [
            'Ihr DSL Anschluss',
            'Ihre Kundendaten',
            'Adresse           Abdulsattar Mousa',
            '                  Kolberger Str. 13',
            '                  24768 Rendsburg',
            'Handynummer für Rückfragen    0152 13973931',
            'E-Mail            abdalstarbkur@icloud.com',
            'Geburtsdatum      23.02.1979',
            'IBAN              DE4622**********2425',
            'Anschlusstermin   schnellstmöglich',
            'Anbieter          Telekom',
            'Tarif             Magenta Zuhause L',
            'Max. Download     100 MBit/s',
            'Mindestlaufzeit   24 Monate',
            'Kündigungsfrist   1 Monat',
            'Durchschnitt pro Monat   34,79 €',
            'Auftragsnummer: 17485672',
        ]);
    }

    public function test_parses_dsl_auftrag(): void
    {
        $r = (new DslAuftragParser())->parse($this->auftragText());
        $this->assertNotNull($r);
        $this->assertSame('internetvertrag', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Abdulsattar', $p['first_name']);
        $this->assertSame('Mousa', $p['last_name']);
        $this->assertSame('1979-02-23', $p['birth_date']);
        $this->assertSame('Kolberger Str.', $p['street']);
        $this->assertSame('13', $p['house_number']);
        $this->assertSame('24768', $p['zip']);
        $this->assertSame('Rendsburg', $p['city']);
        $this->assertSame('015213973931', $p['phone']);
        $this->assertSame('abdalstarbkur@icloud.com', $p['email']);

        $v = $r['data']['versicherung'];
        $this->assertSame('internet', $v['sparte']);
        $this->assertSame('Telekom', $v['insurer']);
        $this->assertSame('Magenta Zuhause L', $v['tariff']);
        $this->assertSame('17485672', $v['contract_number']);
        $this->assertSame(34.79, $v['premium_amount']);
        $this->assertSame('monthly', $v['premium_interval']);

        // Maskierte IBAN wird NICHT als Bankverbindung uebernommen.
        $this->assertArrayNotHasKey('iban', $r['data']['bank']);
    }

    public function test_ignores_non_dsl_documents(): void
    {
        $this->assertNull((new DslAuftragParser())->parse('Irgendein Dokument ohne Tarif und Anbieter.'));
        // Eine Kfz-Police (Anbieter, aber kein Internet-Marker) nicht anfassen.
        $this->assertNull((new DslAuftragParser())->parse("Anbieter: ADAC\nMindestlaufzeit 12 Monate\nKfz-Haftpflicht"));
    }
}
