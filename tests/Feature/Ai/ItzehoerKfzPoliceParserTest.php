<?php

namespace Tests\Feature\Ai;

use App\Models\Contract;
use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Services\Ai\HeuristicDocumentClassifier;
use App\Services\Ai\TemplateParsers\ItzehoerKfzPoliceParser;
use Tests\TestCase;

/**
 * Itzehoer Kfz-Versicherungsschein (Betreiber-Meldung 23.11.2026, KI-129).
 * Fixture = echte Textebene (`pdftotext -layout`) mit ausgetauschten
 * Personen-, Fahrzeug- und Vertragsdaten, Spaltenpositionen unveraendert.
 * Vorher griff kein Parser, und die Heuristik nahm E-Mail und IBAN der
 * ITZEHOER (Brieffuss) als Kundendaten.
 */
class ItzehoerKfzPoliceParserTest extends TestCase
{
    private function fixture(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/itzehoer_kfz_police.txt'));
    }

    public function test_liest_person_vertrag_und_fahrzeug(): void
    {
        $r = (new ItzehoerKfzPoliceParser)->parse($this->fixture());

        $this->assertNotNull($r);
        $this->assertSame('kfz_vertrag', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Omar', $p['first_name']);
        $this->assertSame('Al-Haddad', $p['last_name']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('Am Musterweg', $p['street']);
        $this->assertSame('12', $p['house_number']);
        $this->assertSame('24103', $p['zip']);
        $this->assertSame('Kiel', $p['city']);
        $this->assertSame('1985-02-01', $p['birth_date']);
        $this->assertArrayNotHasKey('email', $p, 'info@itzehoer.de ist keine Kundenadresse.');

        $v = $r['data']['versicherung'];
        $this->assertSame('Itzehoer Versicherungen', $v['insurer']);
        $this->assertSame('2X-12345678-001', $v['contract_number']);
        $this->assertSame('2026-08-11', $v['start_date']);
        $this->assertSame('2026-12-31', $v['end_date']);
        $this->assertSame('COMFORT DRIVE', $v['tariff']);
        $this->assertSame(317.02, $v['premium_amount'], 'Gesamtbeitrag, nicht die Steuer (50,62) und nicht der Erstbeitrag.');
        $this->assertSame('quarterly', $v['premium_interval']);
        $this->assertSame(Contract::STAGE_VERTRAG, $v['document_stage']);

        $k = $r['data']['kfz'];
        $this->assertSame('KI XY 77', $k['license_plate']);
        $this->assertSame('ZFA25000009999999', $k['vin']);
        $this->assertSame('transporter', $k['vehicle_type']);
        $this->assertSame('FIAT', $k['manufacturer']);
        $this->assertSame('4136', $k['hsn']);
        $this->assertSame('ADM', $k['tsn']);
        $this->assertSame(88, $k['power_kw']);
        $this->assertSame('2007-06-14', $k['first_registration']);
        $this->assertSame('2026-08-11', $k['acquisition_date']);
        $this->assertSame(350000, $k['initial_mileage']);
        $this->assertSame(9000, $k['annual_mileage']);
        $this->assertSame('versicherungsnehmer', $k['holder_type']);
        $this->assertFalse($k['has_teilkasko']);
        $this->assertSame(['schutzbrief'], $k['extras']);
    }

    /** Sondereinstufung SF 1 ist die Vertragsklasse, SF 0 die tatsaechliche. */
    public function test_sondereinstufung_und_tatsaechliche_klasse(): void
    {
        $k = (new ItzehoerKfzPoliceParser)->parse($this->fixture())['data']['kfz'];

        $this->assertSame('1', $k['sf_liability_class']);
        $this->assertSame('sondereinstufung', $k['sf_liability_type']);
        $this->assertSame('0', $k['sf_liability_real_class']);
        $this->assertArrayNotHasKey('sf_liability_special_reason', $k, 'Den Grund nennt der Schein nicht - nicht raten.');
    }

    /** Keine Bankdaten: die Kunden-IBAN ist maskiert, die volle gehoert der Itzehoer. */
    public function test_keine_bankdaten(): void
    {
        $r = (new ItzehoerKfzPoliceParser)->parse($this->fixture());

        $this->assertSame([], $r['data']['bank']);
    }

    /** [ohne Fix rot] Die echte Kette greift - vorher landete der Schein in der Heuristik. */
    public function test_echte_kette_aus_dem_container(): void
    {
        $r = app(DocumentTemplateParser::class)->parse($this->fixture());

        $this->assertNotNull($r);
        $this->assertSame('kfz_vertrag', $r['type']);
        $this->assertSame('2X-12345678-001', $r['data']['versicherung']['contract_number']);
    }

    /** Beleg fuer den Ausgangszustand: die Heuristik nimmt die Daten der Itzehoer. */
    public function test_heuristik_haette_die_versichererdaten_genommen(): void
    {
        $h = (new HeuristicDocumentClassifier)->classify($this->fixture());

        $this->assertSame('info@itzehoer.de', $h['data']['person']['email'] ?? null);
    }

    public function test_andere_dokumente_werden_nicht_beansprucht(): void
    {
        $parser = new ItzehoerKfzPoliceParser;

        $this->assertNull($parser->parse("Itzehoer Versicherungen\nAntrag auf Kfz-Versicherung\nVersicherungs-Nr. 2X-1-001"));
        $this->assertNull($parser->parse(str_ireplace('Itzehoe', 'Musterstadt', $this->fixture())));
    }
}
