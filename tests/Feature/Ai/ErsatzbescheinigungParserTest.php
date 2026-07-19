<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\ErsatzbescheinigungParser;
use Tests\TestCase;

/**
 * Ersatzbescheinigung fuer die Gesundheitskarte: liest Name, Geburtsdatum,
 * Anschrift, Krankenkasse, Krankenversichertennummer und Mitgliedsbeginn.
 */
class ErsatzbescheinigungParserTest extends TestCase
{
    private function letter(): string
    {
        return implode("\n", [
            'novitas bkk',
            'Herr Unser Zeichen: S455872364',
            'Obaid Alsaaid Datum: 25.06.2026',
            'Wagnerstr. 119/1',
            '89077 Ulm',
            'Ersatzbescheinigung für Ihre Gesundheitskarte',
            'Die Ersatzbescheinigung ist bis zum 23.10.2026 gültig.',
            'Name, Vorname des Versicherten Geburtsdatum',
            'Alsaaid, Obaid 03.08.2005',
            'Beginn der Mitgliedschaft Krankenversichertennummer',
            '01.04.2026 S455872364',
            'Krankenkasse Institutionskennzeichen',
            'novitas bkk 104491707',
            'Krankenkassennummer Status',
            '02407',
        ]);
    }

    public function test_parses_ersatzbescheinigung(): void
    {
        $r = (new ErsatzbescheinigungParser())->parse($this->letter());
        $this->assertNotNull($r);
        $this->assertSame('gesundheitskarte', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Alsaaid', $p['last_name']);
        $this->assertSame('Obaid', $p['first_name']);
        $this->assertSame('2005-08-03', $p['birth_date']);
        $this->assertSame('Wagnerstr.', $p['street']);
        $this->assertSame('119/1', $p['house_number']);
        $this->assertSame('89077', $p['zip']);
        $this->assertSame('Ulm', $p['city']);

        $h = $r['data']['gesundheit'];
        $this->assertSame('S455872364', $h['health_insurance_number']);
        $this->assertSame('novitas bkk', $h['health_insurance_company']);
        $this->assertSame('gesetzlich', $h['health_insurance_type']);

        $this->assertSame('2026-04-01', $r['data']['versicherung']['start_date']);
    }

    public function test_ignores_non_ersatzbescheinigung(): void
    {
        $this->assertNull((new ErsatzbescheinigungParser())->parse('Beitrittserklärung zur Krankenversicherung'));
    }
}
