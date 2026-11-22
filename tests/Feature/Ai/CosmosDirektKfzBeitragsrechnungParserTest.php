<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Services\Ai\TemplateParsers\CosmosDirektKfzBeitragsrechnungParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer die CosmosDirekt-Kfz-Beitragsrechnung (Betreiber-Meldung
 * 22.11.2026: "Rechnung, 40 % sicher, Kein Kunde gefunden", KI-Freigabe
 * empfohlen). Synthetische Daten, gleiche Struktur wie das Original
 * (pdftotext -layout: Empfaenger links, Werbetext rechts auf derselben Zeile;
 * je Sparte eine Tabelle altes | neues Jahr).
 */
class CosmosDirektKfzBeitragsrechnungParserTest extends TestCase
{
    private function fixture(): string
    {
        $l = fn (string $a, string $b = '') => str_pad('                      '.$a, 110).$b;

        return implode("\n", [
            '                      Diese Beitragsrechnung wird vom Finanzamt anerkannt.',
            '',
            '                      CosmosDirekt · 66101 Saarbrücken',
            '',
            $l('', '               Wir sind immer'),
            $l('Herrn', '               für Sie da!'),
            $l('Max Mustermann'),
            $l('Musterweg 28', '     www.cosmosdirekt.de'),
            $l('92318 Neumarkt I.d.opf.'),
            '',
            $l('', '        Saarbrücken, 18.11.2026'),
            '                      Beitragsrechnung für Ihre Autoversicherung Nr. 123 456 789',
            '                      Amtliches Kennzeichen: NM-AB 1234',
            '',
            '                      Guten Tag Herr Mustermann,',
            '                      wir haben eine gute Nachricht für Sie - Ihre Autoversicherung wird ab 01.01.2027 günstiger.',
            '                                           Ihr neuer Beitrag                         607,62 €',
            '                                                                                        jährlich',
            '',
            'Cosmos Versicherung Aktiengesellschaft      IBAN: DE05 3707 0060 0117 6544 01',
            'Beitragsrechnung',
            'für Ihre Autoversicherung Nr. 123 456 789',
            'Amtliches Kennzeichen: NM-AB 1234',
            '',
            'Am 01.01.2027 wird Ihr Beitrag fällig.',
            '',
            'Kfz-Haftpflichtversicherung Basis-Schutz',
            '                   2026                                                                 2027',
            ' SF-Klasse                         SF 1/2                              SF-Klasse                      SF 1',
            ' Beitragssatz                       50 %                               Beitragssatz                   42 %',
            ' Regionalklasse                           6                            Regionalklasse                    6',
            ' Typklasse                               16                            Typklasse                        16',
            ' Beitrag                      532,03 EUR                               Beitrag                 505,00 EUR',
            '',
            'Teilkaskoversicherung Basis-Schutz',
            'mit Werkstattbindung',
            'Selbstbeteiligung 150 EUR',
            '                   2026                                                                 2027',
            ' Regionalklasse                           9                            Regionalklasse                    9',
            ' Typklasse                               12                            Typklasse                        11',
            ' Beitrag                       68,70 EUR                               Beitrag                  63,62 EUR',
            '',
            'Verkehrs-Rechtsschutzversicherung',
            'für Ihr Fahrzeug NM-AB 1234',
            'Selbstbeteiligung: 150 EUR',
            ' Beitrag                       39,00 EUR                               Beitrag                   39,00 EUR',
            '',
            ' Ihr neuer Beitrag                                                                       607,62 EUR',
            '                                                                                                   jährlich',
            '',
            'Der Beitrag enthält 19 % Versicherungsteuer. Das sind 97,02 EUR.',
            'trag vom Konto IBAN DE39 XXXX XXXX XXXX XXX2 24 mit der Gläubiger-Identifikationsnummer',
            '',
            'Wie hoch ist Ihr Vergleichsbeitrag?',
            'Ihr Vergleichsbeitrag: Kfz-Haftpflichtversicherung: 446,90 EUR; Teilkaskoversicherung: 68,70 EUR.',
            'Der Vergleichsbeitrag ist ein fiktiver Beitrag.',
        ]);
    }

    public function test_liest_kunde_fahrzeug_und_beitrag(): void
    {
        $r = (new CosmosDirektKfzBeitragsrechnungParser)->parse($this->fixture());

        $this->assertNotNull($r);
        $this->assertSame('kfz_vertrag', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Max', $p['first_name']);
        $this->assertSame('Mustermann', $p['last_name']);
        $this->assertSame('Musterweg', $p['street']);
        $this->assertSame('28', $p['house_number']);
        $this->assertSame('92318', $p['zip']);
        $this->assertSame('male', $p['gender']);

        $k = $r['data']['kfz'];
        $this->assertSame('NM-AB 1234', $k['license_plate']);
        // Spalte des NEUEN Jahres, nicht "1/2" aus 2026.
        $this->assertSame('1', $k['sf_liability_class']);
        $this->assertTrue($k['has_teilkasko']);
        $this->assertFalse($k['has_vollkasko']);
        $this->assertSame(150, $k['teilkasko_deductible']);
        $this->assertContains('werkstattbindung', $k['extras']);
        $this->assertContains('verkehrsrechtsschutz', $k['extras']);

        $v = $r['data']['versicherung'];
        $this->assertSame('CosmosDirekt', $v['insurer']);
        $this->assertSame('123456789', $v['contract_number']);
        $this->assertSame('Basis-Schutz', $v['tariff']);
        $this->assertSame(607.62, $v['premium_amount']);
        $this->assertSame('yearly', $v['premium_interval']);
        // Faelligkeit ist kein Vertragsbeginn.
        $this->assertArrayNotHasKey('start_date', $v);

        // Maskierte Kunden-IBAN und Cosmos-IBAN werden nie Bankdaten.
        $this->assertSame([], $r['data']['bank']);

        $this->assertStringContainsString('fällig ab 01.01.2027', $r['summary']);
        $this->assertStringContainsString('Haftpflicht SF 1/2 -> SF 1 (532,03 -> 505,00 EUR)', $r['summary']);
        $this->assertStringContainsString('Vergleichsbeitrag: Kfz-Haftpflichtversicherung: 446,90 EUR', $r['summary']);
    }

    public function test_echte_kette_aus_dem_container_nimmt_diesen_parser(): void
    {
        $r = app(DocumentTemplateParser::class)->parse($this->fixture());

        $this->assertNotNull($r);
        $this->assertStringStartsWith('CosmosDirekt Kfz-Beitragsrechnung', $r['summary']);
    }

    public function test_greift_nicht_bei_fremden_dokumenten(): void
    {
        $parser = new CosmosDirektKfzBeitragsrechnungParser;

        $this->assertNull($parser->parse("Vorlaeufiges Beratungsprotokoll - CHECK24\nTarif: Cosmos Direkt Basis\nBeitragsrechnung für Ihre Autoversicherung Nr. 123 456 789"));
        $this->assertNull($parser->parse("CosmosDirekt\nIhre Risikolebensversicherung Nr. 123 456 789"));
        $this->assertNull($parser->parse('Irgendein anderes Dokument'));
    }
}
