<?php

namespace Tests\Unit;

use App\Models\ContractVehicleDetail as VD;
use App\Models\VehicleSfReference;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * KFZ-Fachregeln der Phase 1 (01.10.2026): SF-Rangfolge, Rueckstufungs-
 * Erkennung, Vorschlag der tatsaechlichen Klasse - und dass JEDE Regel aus
 * config/kfz_rules.php in docs/project-knowledge/KFZ_RULES.md steht
 * (Betreiber-Vorgabe: keine Regel ohne dokumentierte Quelle).
 */
class KfzSfRegelnTest extends TestCase
{
    public function test_rangfolge_m_s_0_halb_dann_aufsteigend(): void
    {
        $this->assertLessThan(VD::sfRank('S'), VD::sfRank('M'));
        $this->assertLessThan(VD::sfRank('0'), VD::sfRank('S'));
        $this->assertLessThan(VD::sfRank('1/2'), VD::sfRank('0'));
        $this->assertLessThan(VD::sfRank('1'), VD::sfRank('1/2'));
        $this->assertLessThan(VD::sfRank('10'), VD::sfRank('9'), 'numerisch, nicht als Text ("10" < "9")');
        $this->assertNull(VD::sfRank('XYZ'));
        $this->assertNull(VD::sfRank(null));
    }

    public function test_rueckstufung_nur_bei_verschlechterung(): void
    {
        $this->assertTrue(VD::isDowngrade('5', '2'));
        $this->assertTrue(VD::isDowngrade('1/2', 'M'));
        $this->assertFalse(VD::isDowngrade('5', '6'), 'Hoeherstufung');
        $this->assertFalse(VD::isDowngrade('5', '5'));
        $this->assertFalse(VD::isDowngrade(null, '2'), 'ohne Vorwert keine Aussage');
        $this->assertFalse(VD::isDowngrade('5', null));
    }

    public function test_vorschlag_tatsaechliche_klasse_nach_fuehrerschein(): void
    {
        $heute = Carbon::parse('2026-10-01');
        $this->assertSame('1/2', VD::suggestRealClass(Carbon::parse('2023-10-01'), $heute), 'genau 3 Jahre');
        $this->assertSame('0', VD::suggestRealClass(Carbon::parse('2023-10-02'), $heute), 'einen Tag zu kurz');
        $this->assertSame('1/2', VD::suggestRealClass(Carbon::parse('2010-05-01'), $heute));
        $this->assertNull(VD::suggestRealClass(null, $heute));
        $this->assertNull(VD::suggestRealClass(Carbon::parse('2027-01-01'), $heute), 'Zukunft: kein Vorschlag');
    }

    public function test_vorschlag_folgt_der_konfiguration(): void
    {
        config(['kfz_rules.rules.SF-VORSCHLAG-FUEHRERSCHEIN.werte.mindestjahre' => 5]);
        $this->assertSame('0', VD::suggestRealClass(Carbon::parse('2023-01-01'), Carbon::parse('2026-10-01')));
    }

    public function test_bezugsgruende_kommen_aus_der_konfiguration(): void
    {
        $this->assertTrue(VehicleSfReference::reasonNeedsReference('zweitwagen'));
        $this->assertTrue(VehicleSfReference::reasonNeedsReference('familie'));
        $this->assertFalse(VehicleSfReference::reasonNeedsReference('sonderaktion'));
        $this->assertFalse(VehicleSfReference::reasonNeedsReference(null));
    }

    public function test_jede_regel_ist_mit_quelle_und_status_dokumentiert(): void
    {
        $doc = (string) file_get_contents(base_path('docs/project-knowledge/KFZ_RULES.md'));
        foreach ((array) config('kfz_rules.rules') as $id => $rule) {
            $this->assertNotEmpty($rule['quelle'] ?? null, "Regel {$id} ohne Quelle");
            $this->assertContains($rule['status'] ?? null, ['geprueft', 'zu_pruefen', 'intern'], "Regel {$id} ohne gueltigen Status");
            $this->assertStringContainsString($id, $doc, "Regel {$id} fehlt in KFZ_RULES.md");
        }
    }
}
