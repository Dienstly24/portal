<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\DeckungsauftragParser;
use Tests\TestCase;

/**
 * "Deckungsauftrag" der Makler-Vergleichsplattform (Fonds Finanz /
 * Thinksurance) - der verbindliche Auftrag an den Versicherer, z.B. zur
 * Frachtfuehrerhaftpflicht. Synthetische Daten, gleicher Aufbau wie das
 * Original (Spaltenlayout, Kopf je Seite wiederholt, Vermittlerblock).
 */
class DeckungsauftragParserTest extends TestCase
{
    private function dokument(array $ersetzungen = []): string
    {
        $text = implode("\n", [
            ' 06.08.2026',
            '',
            ' Deckungsauftrag zur',
            ' Frachtführerhaftpflicht',
            '',
            'Deckungsauftrag für:                             Ansprechpartner:',
            'Karim Muster Einzelunternehmen                   FondsFinanz',
            'Karim Muster                                     Riesstraße 25',
            'Musterallee 7                                    80992 München',
            '24768 Rendsburg                                  E-Mail: sach@fondsfinanz.de',
            '',
            'Deckungsauftrag zur                                Vorgangsnummer:                06.08.2026',
            'Frachtführerhaftpflicht                            7654321',
            '',
            'Informationen zur Beitragsberechnung',
            'Informationen für Versicherer',
            'Produktname                                                    CargoTrucker (06/2022)',
            'Beginn / Ende                                                  2026-08-07/2027-08-06',
            'Kennzeichen 1                                                  RD-QA 639',
            'Zulässiges Gesamtgewicht 1                                     Bis 3,5 t',
            'Selbstbehalt                                                   150',
            'Gesamtprämie brutto                                            238',
            'RV Nummer                                                      RV-000099999',
            '',
            'Daten zum Deckungsauftrag',
            'Daten des Versicherungsnehmers',
            'Firmenname                                              Karim Muster',
            'Rechtsform                                              Einzelunternehmen',
            'Anschrift                                               Karim Muster Einzelunternehmen',
            '                                                        Karim Muster',
            '                                                        Musterallee 7',
            '                                                        24768 Rendsburg',
            'E-Mail-Adresse                                          karim.muster@example.com',
            'Anrede Ansprechpartner                                  Herr',
            'Name Ansprechpartner                                    Karim Muster',
            '',
            'Gewünschter Versicherungsschutz',
            '',
            'Versicherer                                             Helvetia Versicherungs-AG',
            'Gewerbe                                                 Kurierdienst (Hauptbetriebsart)',
            'Gewählter Tarif                                         Helvetia CargoTrucker (06/2022)',
            'Versicherungssumme                                      2.500.000 €',
            'Gewünschte Zahlungsweise                                Monatlich',
            'Vertragslaufzeit                                        1 Jahr',
            'Gewünschter Versicherungsbeginn                         siehe Risikoangaben',
            'Prämie gemäß Zahlweise                                  19,83 €',
            'Jahresnettoprämie                                       200,00 €',
            'Jahresbruttoprämie                                      237,96 €',
            '',
            'Zahlungsweise',
            '',
            'Zahlungsweise                                           Lastschrift',
            'Name des Kontoinhabers                                  Karim Muster',
            'IBAN                                                    DE08300209005390827311',
            'BIC                                                     CMCIDEDDXXX',
            'Name der Bank                                           TARGOBANK',
            '',
            'Vermittlerinformation',
            'Agenturnummer                                          110.6770',
            'Accountname                                            FondsFinanz',
            'Adresse                                                Riesstraße 25',
            '                                                       80992 München',
            'E-Mail                                                 sach@fondsfinanz.de',
            'Betreut von',
            '                                                       Vermittler Muster',
            '                                                       info@dienstly24.de',
            '',
            'Angaben aus dem Fragebogen',
            'Risikofragen',
            '',
            'Gewünschter Versicherungsschutz',
            '',
            'Gewünschter Versicherungsbeginn                                     11.08.2026',
            '',
            'Hauptfälligkeit                                                     01.01.',
            '',
            'Fahrzeuge',
            '',
            'Fahrzeugart                                                        LKW',
            'Kennzeichen                                                        RD-QA 639',
        ]);

        return str_replace(array_keys($ersetzungen), array_values($ersetzungen), $text);
    }

    public function test_reads_deckungsauftrag(): void
    {
        $r = (new DeckungsauftragParser)->parse($this->dokument());

        $this->assertNotNull($r);
        $this->assertSame('versicherungsvertrag', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Karim', $p['first_name']);
        $this->assertSame('Muster', $p['last_name']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('karim.muster@example.com', $p['email']);
        $this->assertSame('Musterallee', $p['street']);
        $this->assertSame('7', $p['house_number']);
        $this->assertSame('24768', $p['zip']);
        $this->assertSame('Rendsburg', $p['city']);
        // Einzelunternehmer: der "Firmenname" ist die Person selbst und wird
        // NICHT als Firma uebernommen.
        $this->assertArrayNotHasKey('company_name', $p);

        $v = $r['data']['versicherung'];
        $this->assertSame('frachtfuehrerhaftpflicht', $v['sparte']);
        $this->assertSame('Helvetia Versicherungs-AG', $v['insurer']);
        $this->assertSame('Helvetia CargoTrucker (06/2022)', $v['tariff']);
        // Beginn aus den RISIKOANGABEN (der Versicherungsschutz-Abschnitt
        // verweist selbst darauf), nicht aus dem Berechnungszeitraum.
        $this->assertSame('2026-08-11', $v['start_date']);
        $this->assertArrayNotHasKey('end_date', $v);
        $this->assertSame(19.83, $v['premium_amount']);
        $this->assertSame('monthly', $v['premium_interval']);
        // Ein Deckungsauftrag ist ein ANTRAG ohne Vertragsnummer - die
        // Vorgangs-/RV-Nummer steht nur in der Zusammenfassung.
        $this->assertSame('antrag', $v['document_stage']);
        $this->assertArrayNotHasKey('contract_number', $v);
        $this->assertStringContainsString('Vorgangsnummer 7654321', $r['summary']);
        $this->assertStringContainsString('Rahmenvertrag RV-000099999', $r['summary']);
        $this->assertStringContainsString('2026-08-07 bis 2027-08-06', $r['summary']);
        // Das Gewerbe-Fahrzeug steht NUR in der Zusammenfassung - nie in
        // data.kfz (sonst wuerde die Fahrzeug-Identitaet spaetere
        // Kfz-Dokumente diesem Haftpflicht-Vertrag zuordnen).
        $this->assertSame([], $r['data']['kfz']);
        $this->assertStringContainsString('RD-QA 639', $r['summary']);

        $b = $r['data']['bank'];
        $this->assertSame('DE08300209005390827311', $b['iban']);
        $this->assertSame('CMCIDEDDXXX', $b['bic']);
        $this->assertSame('Karim Muster', $b['account_holder']);
    }

    public function test_broker_data_never_becomes_customer_data(): void
    {
        // Ohne VN-E-Mail bleibt die E-Mail leer - die Vermittler-Adresse
        // (sach@fondsfinanz.de) wird NIE uebernommen.
        $r = (new DeckungsauftragParser)->parse($this->dokument([
            'E-Mail-Adresse                                          karim.muster@example.com' => '',
        ]));

        $this->assertNotNull($r);
        $this->assertArrayNotHasKey('email', $r['data']['person']);
        // Auch Anschrift/Ort stammen aus dem VN-Block, nicht vom Vermittler.
        $this->assertSame('Rendsburg', $r['data']['person']['city']);
    }

    public function test_real_company_name_is_taken_with_rechtsform(): void
    {
        $r = (new DeckungsauftragParser)->parse($this->dokument([
            'Firmenname                                              Karim Muster' => 'Firmenname                                              Muster Transporte',
            'Rechtsform                                              Einzelunternehmen' => 'Rechtsform                                              GmbH',
        ]));

        $this->assertSame('Muster Transporte GmbH', $r['data']['person']['company_name']);
        $this->assertSame('Karim', $r['data']['person']['first_name']);
    }

    public function test_foreign_account_holder_is_not_taken(): void
    {
        $r = (new DeckungsauftragParser)->parse($this->dokument([
            'Name des Kontoinhabers                                  Karim Muster' => 'Name des Kontoinhabers                                  Max Fremd',
        ]));

        $this->assertNotNull($r);
        $this->assertSame([], $r['data']['bank']);
        $this->assertStringContainsString('Ohne Bankuebernahme', $r['summary']);
    }

    public function test_calculation_period_used_when_risiko_date_missing(): void
    {
        // Nennen die Risikoangaben KEIN Datum, gilt der Zeitraum der
        // Beitragsberechnung als Beginn/Ende.
        $r = (new DeckungsauftragParser)->parse($this->dokument([
            'Gewünschter Versicherungsbeginn                                     11.08.2026' => '',
        ]));

        $this->assertSame('2026-08-07', $r['data']['versicherung']['start_date']);
        $this->assertSame('2027-08-06', $r['data']['versicherung']['end_date']);
        $this->assertStringNotContainsString('Berechnungszeitraum', $r['summary']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $parser = new DeckungsauftragParser;

        $this->assertNull($parser->parse('Irgendein anderes Dokument'));
        // Die Beratungsdokumentation (Schwesterdokument) hat ihren eigenen
        // Parser und traegt das Wort "Deckungsauftrag" nicht.
        $this->assertNull($parser->parse(
            "BERATUNGSDOKUMENTATION\nVorgangsnummer: 1234567\nVorschlag für:\nKarim Muster"
        ));
    }
}
