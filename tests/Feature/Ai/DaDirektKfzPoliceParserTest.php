<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\DaDirektKfzPoliceParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer den DA-Direkt-Kfz-Versicherungsschein (Police): liest
 * Versicherer, echte Versicherungsscheinnummer (VSE/...), Kennzeichen,
 * Fahrzeugdaten (FIN/TSN/Hersteller/Erstzulassung), Deckung/Selbstbeteiligung,
 * SF-Klasse (Haftpflicht), Tarif, Beitrag + Beginn. Synthetische Daten, gleiche
 * Struktur wie das Original (Spaltenlayout aus pdftotext -layout).
 */
class DaDirektKfzPoliceParserTest extends TestCase
{
    private function policeText(): string
    {
        return implode("\n", [
            'DA Direkt Versicherung, 60252 Frankfurt am Main',
            'Herrn',
            '',
            'Max Mustermann',
            '',
            'Musterweg 12',
            '',
            '12345 Musterstadt',
            '',
            'Frankfurt, den 20.4.2026',
            '',
            'Ihre Kraftfahrtversicherung Nr. 111.222.333',
            'Amtliches Kennzeichen: M AB 1234',
            '',
            'Kraftfahrtversicherung',
            'Versicherungsschein Nr. VSE/111.222.333/09',
            '',
            'Versicherungsbeginn:                                               01.07.2026 0.00 Uhr',
            'Zahlungsweise:                                                     vierteljährlich',
            'Beitrag gemäß Zahlungsweise:                                       227,53 EUR (inkl. 19%Versicherungsteuer)',
            '',
            'Versichertes Fahrzeug:',
            'PKW zur Eigenverwendung',
            'Amtliches Kennzeichen:              M AB 1234                      Leistung:                     77 KW',
            'Hersteller:                         VW                             Fahrzeug-Identnr.:            WVGZZZ1TZ9W030077',
            'Typschlüssel:                       ABK                            Erstzulassung:                19.02.2009',
            '',
            'Versicherungsumfang:',
            'A)     Haftpflichtversicherung Mein Tarif Basis',
            'B)     Fahrzeugversicherung Mein Tarif Basis (mit Werkstattbindung)',
            'Fahrzeug-Teilversicherung mit 150,00 EUR Selbstbeteiligung',
            'Im Rahmen der Kaskoversicherung beträgt in Mein Tarif Basis bei Zusammenstößen mit Haarwild die',
            'zusätzliche Selbstbeteiligung 500 Euro, unabhängig von der vereinbarten Selbstbeteiligung.',
            '',
            'Beitragssatz:                  54 %, Beitragsklasse: SF 2, schadenfreie Kalenderjahre: 2*',
            '',
            'Jahreskilometerleistung (km p.a.):               9.000',
            'Geburtsdatum VN:                                 29.02.1988',
            'Abweichender Fahrzeughalter:                     behindertes Kind/Elternteil des VN',
            'Erstzulassung:                                   19.02.2009',
            'Den Erstbeitrag buchen wir von Ihrem Konto IBAN DE61****************4598 ab.',
        ]);
    }

    public function test_parses_all_key_fields(): void
    {
        $r = (new DaDirektKfzPoliceParser())->parse($this->policeText());

        $this->assertNotNull($r);
        $this->assertSame('kfz_vertrag', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Max', $p['first_name']);
        $this->assertSame('Mustermann', $p['last_name']);
        $this->assertSame('Musterweg', $p['street']);
        $this->assertSame('12', $p['house_number']);
        $this->assertSame('12345', $p['zip']);
        $this->assertSame('Musterstadt', $p['city']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('1988-02-29', $p['birth_date']);

        $k = $r['data']['kfz'];
        $this->assertSame('M AB 1234', $k['license_plate']);
        $this->assertSame('WVGZZZ1TZ9W030077', $k['vin']);
        $this->assertSame('ABK', $k['tsn']);
        $this->assertSame('VW', $k['manufacturer']);
        $this->assertSame('2009-02-19', $k['first_registration']);
        $this->assertSame('2', $k['sf_liability_class']);
        $this->assertTrue($k['has_teilkasko']);
        $this->assertSame(150, $k['teilkasko_deductible']);
        $this->assertFalse($k['has_vollkasko']);
        $this->assertContains('werkstattbindung', $k['extras']);
        $this->assertSame('abweichender_halter', $k['holder_type']);
        $this->assertSame(9000, $k['annual_mileage']);
        // Die zusaetzliche Haarwild-SB (500) ist NICHT die vereinbarte SB.
        $this->assertArrayNotHasKey('vollkasko_deductible', $k);

        $v = $r['data']['versicherung'];
        $this->assertSame('DA Direkt', $v['insurer']);
        $this->assertSame('111.222.333', $v['contract_number']);
        $this->assertSame('kfz', $v['sparte']);
        $this->assertSame('2026-07-01', $v['start_date']);
        $this->assertSame(227.53, $v['premium_amount']);
        $this->assertSame('quarterly', $v['premium_interval']);
        $this->assertSame('Mein Tarif Basis (mit Werkstattbindung)', $v['tariff']);

        // Weder die maskierte Kunden-IBAN noch die Versicherer-Bankverbindung
        // duerfen uebernommen werden (Bank bleibt leer).
        $this->assertSame([], $r['data']['bank']);
    }

    public function test_ignores_check24_protocol_and_unrelated_documents(): void
    {
        // Das CHECK24-Protokoll nennt DA Direkt nur als moeglichen Tarif -
        // es darf NICHT von diesem Police-Parser vereinnahmt werden.
        $protocol = "Vorlaeufiges Beratungsprotokoll zur Kfz-Versicherung - CHECK24\n"
            . "Gewaehlter Tarif: DA Direkt Komfort Smart\nVersicherungsschein folgt";
        $this->assertNull((new DaDirektKfzPoliceParser())->parse($protocol));

        // Fremder Versicherer / anderes Dokument.
        $this->assertNull((new DaDirektKfzPoliceParser())->parse('Irgendein anderes Dokument'));
        $this->assertNull((new DaDirektKfzPoliceParser())->parse(
            "ADAC Autoversicherung AG\nVersicherungsschein Kraftfahrtversicherung"
        ));
    }
}
