<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\GesundheitskarteParser;
use Tests\TestCase;

/**
 * Rueckseite der Gesundheitskarte (EHIC): Name, Vornamen, Geburtsdatum und
 * die Persoenliche Kennnummer (Krankenversichertennummer). Traeger-Kennnummer
 * und Karten-Kennnummer werden bewusst NICHT uebernommen.
 */
class GesundheitskarteParserTest extends TestCase
{
    private function card(string $name, string $vorname, string $birth, string $kvnr, string $traeger, string $karte): string
    {
        return implode("\n", [
            'EUROPÄISCHE KRANKENVERSICHERUNGSKARTE',
            '3. Name', $name,
            '4. Vornamen', $vorname,
            '6. Persönliche Kennnummer', $kvnr,
            '8. Kennnummer der Karte', $karte,
            '5. Geburtsdatum', $birth,
            '7. Kennnummer des Trägers', $traeger,
            '9. Ablaufdatum', '30/06/2030',
        ]);
    }

    public function test_parses_ehic_card(): void
    {
        $r = (new GesundheitskarteParser())->parse(
            $this->card('ALALI', 'Mohammad', '23/03/2005', 'F883686827', '109303301 - IKK SW', '80276001340003435015')
        );
        $this->assertNotNull($r);
        $this->assertSame('gesundheitskarte', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('ALALI', $p['last_name']);
        $this->assertSame('Mohammad', $p['first_name']);
        $this->assertSame('2005-03-23', $p['birth_date']);

        $h = $r['data']['gesundheit'];
        $this->assertSame('F883686827', $h['health_insurance_number']);
        $this->assertSame('gesetzlich', $h['health_insurance_type']);

        // Traeger-/Karten-Kennnummer duerfen NIRGENDS auftauchen.
        $json = json_encode($r);
        $this->assertStringNotContainsString('109303301', $json);
        $this->assertStringNotContainsString('80276001340003435015', $json);
    }

    public function test_parses_second_ehic_card(): void
    {
        $r = (new GesundheitskarteParser())->parse(
            $this->card('KARAOGLAN', 'Güner', '08/08/1977', 'U905252417', '109938503 - BAHN-BKK', '80276001920001978734')
        );
        $p = $r['data']['person'];
        $this->assertSame('KARAOGLAN', $p['last_name']);
        $this->assertSame('Güner', $p['first_name']);
        $this->assertSame('1977-08-08', $p['birth_date']);
        $this->assertSame('U905252417', $r['data']['gesundheit']['health_insurance_number']);
    }

    public function test_ignores_non_card_documents(): void
    {
        $this->assertNull((new GesundheitskarteParser())->parse('Irgendein anderes Dokument'));
    }
}
