<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\AdacAutoversicherungParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer die Kfz-Unterlagen der ADAC Autoversicherung
 * (Beitragsinformation/Rechnung): liest Versicherer, Vertragsnummer,
 * Kennzeichen, SF-Klasse (Haftpflicht), Teilkasko, Monatsbeitrag + Beginn.
 * Synthetische Daten, gleiche Struktur wie das Original.
 */
class AdacAutoversicherungParserTest extends TestCase
{
    private function letterText(): string
    {
        return implode("\n", [
            'ADAC Autoversicherung AG',
            'ADAC Autoversicherung AG, 81363 München',
            'Herrn',
            'Max Mustermann',
            'Musterstr. 7 b',
            '12345 Musterstadt',
            'Rechnung und Information zur Beitragsanpassung',
            'Ihre Kfz-Versicherung AD-1234567890 (bitte stets angeben)',
            'Amtl. Kennzeichen: M-AB 123, ADAC-Mitgliedsnummer 111222333',
            'Heute informieren wir Sie über den angepassten Monatsbeitrag. Dieser ist ab dem 01.07.2026 gültig:',
            'Versicherungsumfang        Schadenfreiheitsklasse       Beitragssatz',
            'Kfz-Haftpflichtversicherung        SF 3     SF 4        40%    35%        50,00',
            'Teilkasko                                                                10,00',
            'Gesamtbeitrag (inkl. 19% Versicherungsteuer in Höhe von 9,58 EUR)        60,00',
        ]);
    }

    public function test_parses_all_key_fields(): void
    {
        $r = (new AdacAutoversicherungParser())->parse($this->letterText());

        $this->assertNotNull($r);
        $this->assertSame('kfz_vertrag', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Max', $p['first_name']);
        $this->assertSame('Mustermann', $p['last_name']);
        $this->assertSame('Musterstr.', $p['street']);
        $this->assertSame('7 b', $p['house_number']);
        $this->assertSame('12345', $p['zip']);
        $this->assertSame('Musterstadt', $p['city']);

        $k = $r['data']['kfz'];
        $this->assertSame('M-AB 123', $k['license_plate']);
        $this->assertSame('4', $k['sf_liability_class']); // die NEUE Klasse gilt
        $this->assertTrue($k['has_teilkasko']);

        $v = $r['data']['versicherung'];
        $this->assertSame('ADAC Autoversicherung AG', $v['insurer']);
        $this->assertSame('AD-1234567890', $v['contract_number']);
        $this->assertSame('kfz', $v['sparte']);
        $this->assertSame('2026-07-01', $v['start_date']);
        $this->assertSame(60.0, $v['premium_amount']); // Gesamtbeitrag, NICHT der Steuerbetrag
        $this->assertSame('monthly', $v['premium_interval']);
    }

    public function test_ignores_check24_protocol_that_only_mentions_adac(): void
    {
        // Das CHECK24-Protokoll nennt die ADAC nur als moeglichen Versicherer -
        // es darf NICHT von diesem Parser vereinnahmt werden.
        $protocol = "Vorlaeufiges Beratungsprotokoll zur Kfz-Versicherung - CHECK24\n"
            . "Weiteres Fahrzeug\nVersicherer: ADAC Autoversicherung AG\nSchadenfreiheitsklasse";
        $this->assertNull((new AdacAutoversicherungParser())->parse($protocol));

        $this->assertNull((new AdacAutoversicherungParser())->parse('Irgendein anderes Dokument'));
    }
}
