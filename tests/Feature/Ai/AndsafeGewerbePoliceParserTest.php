<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\AndsafeGewerbePoliceParser;
use Tests\TestCase;

/**
 * Versicherungsschein der andsafe AG (Online-Gewerbeversicherer der
 * Provinzial-Gruppe), z.B. die Betriebshaftpflicht eines Handwerksbetriebs.
 * Synthetische Daten, gleicher Aufbau wie das Original (digitales PDF,
 * Beschriftung links, Wert rechts, mehrzeilige Werte eingerueckt).
 */
class AndsafeGewerbePoliceParserTest extends TestCase
{
    private function scheinText(array $ersetzungen = []): string
    {
        $text = implode("\n", [
            'andsafe AG, Provinzial-Allee 1, 48159 Münster',
            '',
            'Karim Muster',
            'Musterplatz 3',
            '21073 Hamburg',
            '                                                        Münster, den 24.07.2026',
            '',
            'Versicherungsschein',
            'Versicherung                              andsafe Betriebshaftpflichtversicherung',
            '',
            'Vertragsnummer                            BH260738644',
            '',
            'Versicherungsnehmer:in                    Karim Muster',
            '                                          Musterplatz 3',
            '                                          21073 Hamburg',
            '',
            'Kontakt-E-Mail-Adresse                    karim.muster@example.com',
            '',
            'Antragsdatum                              24.07.2026',
            '',
            'Versicherungsbeginn                       25.07.2026 0:00 Uhr',
            '',
            'Versicherungsablauf                       25.07.2027 0:00 Uhr',
            '                                          Der Vertrag verlängert sich über dieses Ablaufdatum hinaus je-',
            '                                          weils um ein Jahr.',
            '',
            '/ Versicherungsumfang',
            '',
            'Hauptgewerbe                              Küchenmontage',
            '',
            'Mitversicherte Gewerbe                    Möbelhandel (mit Montage)',
            '',
            'Jahresumsatz                              150.000 €',
            '',
            'Versicherungssumme                        5.000.000 € pauschal für Personen-, Sach- und daraus resul-',
            '                                          tierende Vermögensschäden',
            '',
            'andsafe Aktiengesellschaft',
            'Postanschrift:       Handelsregister: Registergericht Amtsgericht Münster',
            'Provinzial-Allee 1   Vorstand: Dr. Christian Brandt',
            '48159 Münster        Vorsitzende des Aufsichtsrats: Nina Schmal',
            'E: info@andsafe.de   Bankverbindung: Helaba, IBAN DE95 3005 0000 0003 3400 15, BIC WELADEDD',
            '',
            'Selbstbeteiligung je Schadenfall          Sie haben keine Selbstbeteiligung gewählt.',
            '',
            '/ Optionale Einschlüsse',
            '',
            'Privathaftpflichtversicherung             Versicherungsschutz bei Personen-, Sach- oder Vermögens-',
            '                                          schäden für einen Geschäftsführer vereinbart.',
            '',
            'Nettobeitrag                              41,16 €',
            '',
            '/ Beitrag',
            '',
            'Beitrag                                   288,12 €',
            '',
            'Versicherungssteuer (zzgl. 19%)           54,74 €',
            '',
            'Jahresbeitrag                             342,86 €',
            '',
            '/ Beitragsrechnung',
            '',
            'Vereinbarte Zahlungsweise                 Monatlich',
            '',
            'Zeitraum                                  25.07.2026 - 25.08.2026',
            '',
            'Betriebshaftpflichtversicherung           24,01 €',
            '',
            'Versicherungssteuer (zzgl. 19%)           4,56 €',
            '',
            'Gesamtforderung                           28,57 €',
            '',
            'Von folgendem Konto erfolgt die Abbuchung      DEXXXXXXXXXXXXXXXX2807',
            '',
            'Ihre Mandatsreferenz                      BH2607386440001',
        ]);

        return str_replace(array_keys($ersetzungen), array_values($ersetzungen), $text);
    }

    public function test_reads_betriebshaftpflicht_police(): void
    {
        $r = (new AndsafeGewerbePoliceParser)->parse($this->scheinText());

        $this->assertNotNull($r);
        $this->assertSame('versicherungspolice', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Karim', $p['first_name']);
        $this->assertSame('Muster', $p['last_name']);
        $this->assertSame('Musterplatz', $p['street']);
        $this->assertSame('3', $p['house_number']);
        $this->assertSame('21073', $p['zip']);
        $this->assertSame('Hamburg', $p['city']);
        $this->assertSame('karim.muster@example.com', $p['email']);

        $v = $r['data']['versicherung'];
        $this->assertSame('andsafe AG', $v['insurer']);
        $this->assertSame('BH260738644', $v['contract_number']);
        // Sparte AUS DEM FELD "Versicherung" - der optionale Einschluss
        // "Privathaftpflichtversicherung" darf sie nicht kippen.
        $this->assertSame('betriebshaftpflicht', $v['sparte']);
        $this->assertSame('andsafe Betriebshaftpflichtversicherung', $v['tariff']);
        $this->assertSame('2026-07-25', $v['start_date']);
        $this->assertSame('2027-07-25', $v['end_date']);
        // Der wiederkehrende BRUTTO-Betrag laut Beitragsrechnung - nicht der
        // Jahresbeitrag (342,86) und nicht der Nettobeitrag des optionalen
        // Bausteins (41,16).
        $this->assertSame(28.57, $v['premium_amount']);
        $this->assertSame('monthly', $v['premium_interval']);
        $this->assertSame('vertrag', $v['document_stage']);

        // KEINE Bankdaten: die Kunden-IBAN ist maskiert, die vollstaendige
        // IBAN im Brieffuss gehoert der Gesellschaft.
        $this->assertSame([], $r['data']['bank']);
        $this->assertStringNotContainsString('DE95', $r['summary']);

        // Gewerbe-Angaben stehen in der Zusammenfassung; die Silbentrennung
        // des PDF wird aufgehoben.
        $this->assertStringContainsString('Hauptgewerbe: Küchenmontage', $r['summary']);
        $this->assertStringContainsString('Mitversichert: Möbelhandel (mit Montage)', $r['summary']);
        $this->assertStringContainsString('daraus resultierende Vermögensschäden', $r['summary']);
        $this->assertStringContainsString('Jahresbeitrag (brutto): 342,86 €', $r['summary']);
        $this->assertStringContainsString('Optionaler Einschluss: Privathaftpflichtversicherung', $r['summary']);
    }

    public function test_insurer_contact_never_becomes_customer_data(): void
    {
        // Ohne Kunden-E-Mail bleibt das Feld leer - info@andsafe.de wird NIE
        // uebernommen, ebenso wenig die Anschrift der Gesellschaft.
        $r = (new AndsafeGewerbePoliceParser)->parse($this->scheinText([
            'Kontakt-E-Mail-Adresse                    karim.muster@example.com' => '',
        ]));

        $this->assertNotNull($r);
        $this->assertArrayNotHasKey('email', $r['data']['person']);
        $this->assertSame('21073', $r['data']['person']['zip']);
        $this->assertNotSame('48159', $r['data']['person']['zip'] ?? null);
    }

    public function test_yearly_payment_uses_the_yearly_amount(): void
    {
        $r = (new AndsafeGewerbePoliceParser)->parse($this->scheinText([
            'Vereinbarte Zahlungsweise                 Monatlich' => 'Vereinbarte Zahlungsweise                 Jährlich',
            'Gesamtforderung                           28,57 €' => 'Gesamtforderung                           342,86 €',
        ]));

        $this->assertSame(342.86, $r['data']['versicherung']['premium_amount']);
        $this->assertSame('yearly', $r['data']['versicherung']['premium_interval']);
    }

    public function test_unknown_product_leaves_sparte_empty(): void
    {
        // Lieber leer als falsch zugeordnet - der Mitarbeiter waehlt.
        $r = (new AndsafeGewerbePoliceParser)->parse($this->scheinText([
            'Versicherung                              andsafe Betriebshaftpflichtversicherung' => 'Versicherung                              andsafe Spezialdeckung Neu',
        ]));

        $this->assertNotNull($r);
        $this->assertArrayNotHasKey('sparte', $r['data']['versicherung']);
        $this->assertSame('andsafe Spezialdeckung Neu', $r['data']['versicherung']['tariff']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $parser = new AndsafeGewerbePoliceParser;

        $this->assertNull($parser->parse('Irgendein anderes Dokument'));
        // Interlloyd-Schein (eigener Parser) traegt kein "andsafe".
        $this->assertNull($parser->parse(
            "Versicherungsschein\nInterlloyd VERSICHERUNGS-AG\nNr. : 000782243\nVertragsnummer"
        ));
    }
}
