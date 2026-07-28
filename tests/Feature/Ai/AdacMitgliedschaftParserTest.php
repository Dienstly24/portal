<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\AdacMitgliedschaftParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer die ADAC-Mitgliedschaft (Screenshot "Meine Mitgliedschaft"):
 * Mitgliedsnummer -> Vertragsnummer, Sparte schutzbrief, Stufe intelligent aus
 * Tarifname ODER Jahresbeitrag (54 = Basis, 94 = Plus, 139 = Premium).
 * Unbekannte Betraege ergeben keine Stufe (nicht raten).
 */
class AdacMitgliedschaftParserTest extends TestCase
{
    private function screenshotOcr(string $tarif = 'ADAC Mitgliedschaft', string $beitrag = '54,00 €'): string
    {
        return implode("\n", [
            'Meine Mitgliedschaft',
            '',
            'Tarif                    ' . $tarif,
            'Jahresbeitrag            ' . $beitrag,
            'Mitgliedsnummer          736673274',
            'Mitglied                 Haya Afara',
        ]);
    }

    public function test_basis_membership_from_amount_54(): void
    {
        $r = (new AdacMitgliedschaftParser())->parse($this->screenshotOcr());

        $this->assertNotNull($r);
        $this->assertSame('versicherungsvertrag', $r['type']);

        $v = $r['data']['versicherung'];
        $this->assertSame('ADAC', $v['insurer']);
        $this->assertSame('schutzbrief', $v['sparte']); // Schutzbrief / Mobilclub
        $this->assertSame('basis', $v['subtype']); // 54 EUR -> Basis
        $this->assertSame('736673274', $v['contract_number']); // Mitgliedsnummer
        $this->assertSame(54.0, $v['premium_amount']);
        $this->assertSame('yearly', $v['premium_interval']);
        $this->assertSame('ADAC Mitgliedschaft', $v['tariff']);

        $p = $r['data']['person'];
        $this->assertSame('Haya', $p['first_name']);
        $this->assertSame('Afara', $p['last_name']);
    }

    public function test_plus_and_premium_tiers(): void
    {
        // 94 EUR -> Plus (auch ohne "Plus" im Namen).
        $plus = (new AdacMitgliedschaftParser())->parse($this->screenshotOcr('ADAC Mitgliedschaft', '94,00 €'));
        $this->assertSame('plus', $plus['data']['versicherung']['subtype']);

        // 139 EUR -> Premium.
        $premium = (new AdacMitgliedschaftParser())->parse($this->screenshotOcr('ADAC Mitgliedschaft', '139,00 €'));
        $this->assertSame('premium', $premium['data']['versicherung']['subtype']);

        // Tarifname gewinnt: "Plus-Mitgliedschaft" auch bei fehlendem Beitrag.
        $named = (new AdacMitgliedschaftParser())->parse($this->screenshotOcr('ADAC Plus-Mitgliedschaft', ''));
        $this->assertSame('plus', $named['data']['versicherung']['subtype']);
    }

    public function test_unknown_amount_yields_no_tier(): void
    {
        // 77 EUR ist keine bekannte Stufe -> nicht raten, Stufe bleibt leer.
        $r = (new AdacMitgliedschaftParser())->parse($this->screenshotOcr('ADAC Mitgliedschaft', '77,00 €'));
        $this->assertNotNull($r);
        $this->assertArrayNotHasKey('subtype', $r['data']['versicherung']);
        $this->assertSame('736673274', $r['data']['versicherung']['contract_number']);
    }

    public function test_ignores_adac_car_insurance_and_unrelated(): void
    {
        // Die ADAC-Autoversicherung hat einen eigenen Parser.
        $this->assertNull((new AdacMitgliedschaftParser())->parse(
            "ADAC Autoversicherung AG\nIhre Kfz-Versicherung AD-1234567890\nADAC-Mitgliedsnummer 111222333"
        ));
        $this->assertNull((new AdacMitgliedschaftParser())->parse('Irgendein anderes Dokument'));
        // ADAC + Mitglied, aber ohne Mitgliedsnummer -> normale Analyse.
        $this->assertNull((new AdacMitgliedschaftParser())->parse("ADAC Mitgliedschaft\nWerden Sie Mitglied!"));
    }
}
