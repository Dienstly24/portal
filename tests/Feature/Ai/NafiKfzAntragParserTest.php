<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\NafiKfzAntragParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer den "Antrag Kraftfahrtversicherung" aus der
 * NAFI-Maklersoftware - ueber alle Gesellschaften hinweg gleich aufgebaut
 * (Beschriftung links, Wert rechtsbuendig). Synthetische Daten, gleiche
 * Struktur wie das Original (pdftotext -layout).
 */
class NafiKfzAntragParserTest extends TestCase
{
    /** Zeile "Beschriftung:" links, Wert rechtsbuendig. */
    private function row(string $label, string $value): string
    {
        return ' '.str_pad($label.':', 60).str_pad($value, 60, ' ', STR_PAD_LEFT);
    }

    private function antragText(string $kasko = 'Ohne Kasko', string $zahler = 'Der Versicherungsnehmer'): string
    {
        return implode("\n", [
            'Itzehoer Versicherung',
            'Itzehoer Platz',
            '25524 Itzehoe',
            'Antrag Kraftfahrtversicherung',
            'Achtung: Antrag wurde bereits ONLINE zum Versicherer gesendet!',
            '',
            ' Versicherungsnehmer',
            $this->row('Anrede, Titel, Vorname, Nachname', 'Herr Ali Mustermann'),
            $this->row('Straße', 'Alte Kieler Landstr. 90'),
            $this->row('Plz, Ort', '24768 Rendsburg'),
            $this->row('E-Mail', 'ali.mustermann@example.com'),
            $this->row('Derzeitiger Status des Versicherungsnehmers', 'Angestellter'),
            $this->row('Geburtsdatum', '08.05.1980'),
            $this->row('Familienstand', 'Verheiratet'),
            $this->row('Staatsangehörigkeit', 'Deutschland'),
            '',
            ' Antragsdaten',
            $this->row('Tarif', 'ITZEHOER COMFORT DRIVE'),
            $this->row('Versicherer / Risikoträger', 'Itzehoer Versicherung'),
            $this->row('Antragsart', 'Neuantrag (z.B. Zweitwagen, keine Vorversicherung)'),
            $this->row('Gewünschter Versicherungsbeginn', '06.08.2026'),
            $this->row('Vertragsablauf (nächste Hauptfälligkeit)', '01.01.2027'),
            // Das Kennzeichen steht hier mit Leerzeichen um den Bindestrich.
            $this->row('Amtliches Kennzeichen', 'RD - AS 1212'),
            $this->row('Fahrgestellnummer', 'ZFA25000001174717'),
            $this->row('Kilometerstand', '350.000 km'),
            $this->row('eVB - Nummer', 'SHXTSZY vom 06.08.2026 11:48:55'),
            '',
            ' Ihr Beitrag (inkl. der derzeit gültigen Versicherungsteuer von 19%): 296,00 EUR',
            $this->row('Beitrag Haftpflicht', '296,00 EUR'),
            $this->row('Zu zahlender Gesamtbeitrag (vierteljährlich)', '296,00 EUR'),
            '',
            ' Zahlungswunsch',
            $this->row('Zahlungsperiode', 'Vierteljährlich'),
            $this->row('Zahlungsart', 'Lastschrift'),
            $this->row('Zahlungspflichtige Person', $zahler),
            $this->row('Bankname', 'Sparkasse Mittelholstein'),
            $this->row('IBAN (SEPA)', 'DE92 2145 0000 0105 7793 34'),
            $this->row('BIC (SEPA)', 'NOLADE21RDB'),
            '',
            ' Fahrzeugdaten',
            $this->row('Wagnis (gemäß GDV)', 'Lkw bis 3,5 t zul. Gesamtgewicht im Werkverkehr'),
            $this->row('HSN / Hersteller', '4136 - FIAT'),
            $this->row('Leistung des Fahrzeugs', '88 kW / 120 PS'),
            $this->row('Gesamtgewicht', '3.500 kg'),
            $this->row('Datum der Erstzulassung', '14.06.2007'),
            $this->row('Zulassung auf den Fahrzeughalter', '06.08.2026'),
            $this->row('Verwendeter Kraftstoff', 'Diesel'),
            '',
            ' Versicherungsumfang',
            $this->row('Deckungssumme', '100 Mio pauschal'),
            $this->row('Gewünschte Kaskoart', $kasko),
            '',
            ' Merkmale',
            $this->row('Fahrzeughalter', 'Versicherungsnehmer'),
            $this->row('Nutzungsart des Fahrzeugs', 'Überwiegend privat'),
            $this->row('Jährliche Fahrleistung', '9.000 km'),
            $this->row('Aktueller Kilometerstand', '350.000 km'),
            '',
            ' Gewünschte weitere Leistungen / Ihre konkreten Anforderungen',
            ' Leistungsbezeichnung                                          Ihre Anforderung',
            ' - Schutzbrief                                                 Ja',
            '',
            'Gewünschte SF-Einstufung',
            $this->row('Berechnete SF-Klasse Haftpflicht', 'SF 1 (70 %)'),
            'Kfz-Antrag vom 06.08.2026 / Seite 5 / Erzeugt durch NAFI-Software',
        ]);
    }

    public function test_reads_person_contract_vehicle_and_bank(): void
    {
        $r = (new NafiKfzAntragParser)->parse($this->antragText());

        $this->assertNotNull($r);
        $this->assertSame('kfz_vertrag', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Ali', $p['first_name']);
        $this->assertSame('Mustermann', $p['last_name']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('1980-05-08', $p['birth_date']);
        $this->assertSame('Alte Kieler Landstr.', $p['street']);
        $this->assertSame('90', $p['house_number']);
        $this->assertSame('24768', $p['zip']);
        $this->assertSame('Rendsburg', $p['city']);
        $this->assertSame('ali.mustermann@example.com', $p['email']);
        $this->assertSame('verheiratet', $p['marital_status']);
        $this->assertSame('Deutschland', $p['nationality']);
        $this->assertSame('Angestellter', $p['occupation']);

        $v = $r['data']['versicherung'];
        $this->assertSame('Itzehoer Versicherung', $v['insurer']);
        $this->assertSame('kfz', $v['sparte']);
        $this->assertSame('ITZEHOER COMFORT DRIVE', $v['tariff']);
        $this->assertSame('2026-08-06', $v['start_date']);
        $this->assertSame('2027-01-01', $v['end_date']);
        $this->assertSame(296.0, $v['premium_amount']);
        $this->assertSame('quarterly', $v['premium_interval']);

        $k = $r['data']['kfz'];
        // "RD - AS 1212" wird auf die uebliche Schreibweise gebracht.
        $this->assertSame('RD-AS 1212', $k['license_plate']);
        $this->assertSame('ZFA25000001174717', $k['vin']);
        $this->assertSame('lkw', $k['vehicle_type']);
        $this->assertSame('4136', $k['hsn']);
        $this->assertSame('FIAT', $k['manufacturer']);
        $this->assertSame(88, $k['power_kw']);
        $this->assertSame('diesel', $k['fuel_type']);
        $this->assertSame('2007-06-14', $k['first_registration']);
        $this->assertSame('2026-08-06', $k['acquisition_date']);
        $this->assertSame('versicherungsnehmer', $k['holder_type']);
        $this->assertSame(9000, $k['annual_mileage']);
        $this->assertSame(350000, $k['initial_mileage']);
        $this->assertSame('1', $k['sf_liability_class']);
        $this->assertSame(['schutzbrief'], $k['extras']);

        // Zahlungspflichtig ist der Versicherungsnehmer -> sein Konto.
        $this->assertSame('DE92214500000105779334', $r['data']['bank']['iban']);
        $this->assertSame('NOLADE21RDB', $r['data']['bank']['bic']);
    }

    public function test_application_has_no_contract_number(): void
    {
        $r = (new NafiKfzAntragParser)->parse($this->antragText());

        // Ein Antrag hat keine Vertragsnummer; NAFI-Vorgangs-ID und eVB sind
        // keine - sie stehen nur in der Zusammenfassung.
        $this->assertArrayNotHasKey('contract_number', $r['data']['versicherung']);
        $this->assertSame('antrag', $r['data']['versicherung']['document_stage']);
        $this->assertStringContainsString('eVB SHXTSZY', $r['summary']);
        $this->assertStringContainsString('noch kein Versicherungsschein', $r['summary']);
    }

    public function test_coverage_comes_from_the_kasko_field(): void
    {
        $ohne = (new NafiKfzAntragParser)->parse($this->antragText());
        $this->assertFalse($ohne['data']['kfz']['has_teilkasko']);
        $this->assertFalse($ohne['data']['kfz']['has_vollkasko']);

        $voll = (new NafiKfzAntragParser)->parse($this->antragText('Vollkasko mit 500 EUR SB'));
        $this->assertTrue($voll['data']['kfz']['has_vollkasko']);
        $this->assertTrue($voll['data']['kfz']['has_teilkasko']);

        $teil = (new NafiKfzAntragParser)->parse($this->antragText('Teilkasko mit 150 EUR SB'));
        $this->assertTrue($teil['data']['kfz']['has_teilkasko']);
        $this->assertFalse($teil['data']['kfz']['has_vollkasko']);
    }

    public function test_foreign_account_is_not_taken(): void
    {
        // Zahlt ein Dritter, gehoert sein Konto NICHT in die Kundenakte.
        $r = (new NafiKfzAntragParser)->parse($this->antragText(zahler: 'Eine andere Person'));

        $this->assertSame([], $r['data']['bank']);
        // Der Rest wird trotzdem gelesen.
        $this->assertSame('RD-AS 1212', $r['data']['kfz']['license_plate']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $parser = new NafiKfzAntragParser;

        $this->assertNull($parser->parse('Irgendein anderes Dokument'));
        $this->assertNull($parser->parse("Antrag Kraftfahrtversicherung\nohne jedes Fahrzeug"));
        // Fremdes CHECK24-Vergleichsangebot.
        $this->assertNull($parser->parse(
            "Vorlaeufiges Beratungsprotokoll zur Kfz-Versicherung - CHECK24\nAntrag Kraftfahrtversicherung"
        ));
    }
}
