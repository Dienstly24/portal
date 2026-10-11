<?php

namespace Tests\Feature\Ai;

use App\Models\Contract;
use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Services\Ai\TemplateParsers\DevkKfzPoliceParser;
use Tests\TestCase;

/**
 * DEVK Kfz-Versicherungsschein als ANPASSUNG (Betreiber-Meldung 11.10.2026).
 * Die Fixture ist die echte Textebene (`pdftotext -layout`) mit
 * ausgetauschten Personendaten - Spaltenpositionen unveraendert.
 */
class DevkKfzPoliceParserTest extends TestCase
{
    private function fixture(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/devk_kfz_anpassung.txt'));
    }

    public function test_liest_person_vertrag_und_fahrzeug(): void
    {
        $r = (new DevkKfzPoliceParser)->parse($this->fixture());

        $this->assertNotNull($r);
        $this->assertSame('kfz_vertrag', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('K Omar', $p['first_name']);
        $this->assertSame('Mustafa', $p['last_name']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('Am Musterweg', $p['street']);
        $this->assertSame('7a', $p['house_number']);
        $this->assertSame('24768', $p['zip']);
        $this->assertSame('Rendsburg', $p['city']);
        $this->assertSame('1970-02-01', $p['birth_date']);

        $v = $r['data']['versicherung'];
        $this->assertSame('DEVK Allgemeine Versicherungs-AG', $v['insurer']);
        $this->assertSame('500000001', $v['contract_number']);
        $this->assertSame('Aktiv-Schutz', $v['tariff']);
        $this->assertSame('2027-04-10', $v['end_date']);
        $this->assertSame(172.87, $v['premium_amount']);
        $this->assertSame('quarterly', $v['premium_interval']);
        $this->assertSame(Contract::STAGE_VERTRAG, $v['document_stage']);

        $k = $r['data']['kfz'];
        $this->assertSame('RD XY 123', $k['license_plate']);
        $this->assertSame('TMADB81CABJ000001', $k['vin']);
        $this->assertSame('pkw', $k['vehicle_type']);
        $this->assertSame('HYUNDAI', $k['manufacturer']);
        $this->assertSame('1349', $k['hsn']);
        $this->assertSame('AAB', $k['tsn']);
        $this->assertSame('2011-01-11', $k['first_registration']);
        $this->assertSame(80, $k['power_kw']);
        $this->assertSame('4', $k['sf_liability_class']);
        $this->assertTrue($k['has_teilkasko']);
        $this->assertFalse($k['has_vollkasko']);
        $this->assertSame(150, $k['teilkasko_deductible']);
        $this->assertSame(['schutzbrief', 'werkstattbindung'], $k['extras']);
        $this->assertSame(15000, $k['annual_mileage']);
        $this->assertSame(140434, $k['initial_mileage']);
        $this->assertSame('2024-04-11', $k['acquisition_date']);
        $this->assertSame('versicherungsnehmer', $k['holder_type']);

        $this->assertSame([], $r['data']['bank']);
    }

    /** Das Aenderungsdatum ist NIE der Vertragsbeginn. */
    public function test_aenderungsdatum_wird_nicht_zum_beginn(): void
    {
        $r = (new DevkKfzPoliceParser)->parse($this->fixture());

        $this->assertArrayNotHasKey('start_date', $r['data']['versicherung']);
        $this->assertStringContainsString('Anpassung', $r['summary']);
        $this->assertStringContainsString('Änderung zum 07.10.2026', $r['summary']);
    }

    /** Beim Neuvertrag ist "Beginn der Versicherung" gefuellt und wird gelesen. */
    public function test_neuvertrag_liest_den_beginn(): void
    {
        // Wertezeile wie im Neuvertrag: der Beginn steht unter "Beginn der Versicherung".
        $zeile = str_pad('       Kfz                                     500000001', 88).'10.04.2024';
        $zeile = str_pad(str_pad($zeile, 129).'10.04.2024', 168).'10.04.2027';
        $text = (string) preg_replace('/^\s+Kfz\s+500000001\s.*$/mu', $zeile, $this->fixture(), 1);
        $r = (new DevkKfzPoliceParser)->parse($text);

        $this->assertSame('2024-04-10', $r['data']['versicherung']['start_date'] ?? null);
        $this->assertSame('2027-04-10', $r['data']['versicherung']['end_date']);
    }

    /** Die Vermittlerzeile "CHECK24" sperrt die Police nicht. */
    public function test_check24_vermittlerzeile_blockiert_nicht(): void
    {
        $this->assertStringContainsString('CHECK24', $this->fixture());
        $this->assertNotNull((new DevkKfzPoliceParser)->parse($this->fixture()));
    }

    public function test_ignoriert_fremde_dokumente(): void
    {
        $parser = new DevkKfzPoliceParser;
        $this->assertNull($parser->parse('WGV Versicherungsschein zur Kraftfahrtversicherung Nr. 123'));
        $this->assertNull($parser->parse("Beratungsprotokoll CHECK24\nTarif DEVK Kfz-Versicherung"));
    }

    /** Waechter: die ECHTE Kette aus dem Container waehlt diesen Parser. */
    public function test_kette_aus_dem_container(): void
    {
        $r = app(DocumentTemplateParser::class)->parse($this->fixture());

        $this->assertNotNull($r);
        $this->assertStringStartsWith('DEVK Kfz-Versicherungsschein', $r['summary']);
        $this->assertSame('500000001', $r['data']['versicherung']['contract_number']);
    }
}
