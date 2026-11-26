<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Vertraege und Berichte gehoeren zusammen (Betreiber-Auftrag 26.11.2026):
 * Filter + Kennzahlen in der Vertragsliste, Monatsuebersicht je Sparte mit
 * Namen, und der Weg zwischen beiden.
 */
class VertraegeUndMonatsberichtTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-25 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function kunde(string $name): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(substr(md5($user->id.microtime()), 0, 8)),
        ]);
    }

    private function vertrag(Customer $kunde, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'customer_id' => $kunde->id,
            'type' => 'kfz',
            'insurer' => 'Allianz',
            'status' => 'active',
            'signing_date' => '2026-11-10',
            'premium_amount' => 50,
            'premium_interval' => 'monthly',
        ], $overrides));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function bestand(): void
    {
        $anna = $this->kunde('Anna November');
        $this->vertrag($anna);
        $this->vertrag($anna, ['type' => 'haftpflicht', 'insurer' => 'HUK', 'stage' => Contract::STAGE_ANTRAG]);

        $bernd = $this->kunde('Bernd November');
        $this->vertrag($bernd, ['insurer' => 'DEVK', 'stage' => Contract::STAGE_ANTRAG]);

        $olga = $this->kunde('Olga Oktober');
        $this->vertrag($olga, ['signing_date' => '2026-10-05']);
    }

    // ------------------------------------------------ Vertragsliste

    public function test_sparte_und_abschlussmonat_filtern_die_liste_und_die_kennzahlen(): void
    {
        $this->bestand();

        $antwort = $this->actingAs($this->admin())
            ->get(route('admin.contracts', ['sparte' => 'kfz', 'abschluss' => '2026-11']))
            ->assertOk();

        $antwort->assertSee('Anna November')->assertSee('Bernd November')->assertDontSee('Olga Oktober');
        $this->assertSame(2, $antwort->viewData('kennzahlen')['vertraege']);
        $this->assertSame(2, $antwort->viewData('kennzahlen')['kunden']);
        $this->assertSame(1, $antwort->viewData('kennzahlen')['antraege']);
        $this->assertEqualsWithDelta(1200.0, $antwort->viewData('kennzahlen')['jahresbeitrag'], 0.01);
    }

    public function test_stufe_trennt_antraege_vom_uebrigen_bestand(): void
    {
        $this->bestand();
        $admin = $this->admin();

        $nurAntraege = $this->actingAs($admin)->get(route('admin.contracts', ['stufe' => 'antrag']))->assertOk();
        $this->assertSame(2, $nurAntraege->viewData('kennzahlen')['vertraege']);

        // Altbestand ohne Stufe zaehlt NICHT als Antrag.
        $ohne = $this->actingAs($admin)->get(route('admin.contracts', ['stufe' => 'bestaetigt']))->assertOk();
        $this->assertSame(2, $ohne->viewData('kennzahlen')['vertraege']);
        $this->assertSame(0, $ohne->viewData('kennzahlen')['antraege']);
    }

    public function test_unbekannte_filterwerte_werden_verworfen(): void
    {
        $this->bestand();

        $antwort = $this->actingAs($this->admin())
            ->get(route('admin.contracts', ['sparte' => 'gibtsnicht', 'stufe' => 'x', 'abschluss' => '2026-13']))
            ->assertOk();

        $this->assertSame(['sparte' => null, 'gesellschaft' => null, 'stufe' => null, 'abschluss' => null], $antwort->viewData('filter'));
        $this->assertSame(4, $antwort->viewData('kennzahlen')['vertraege']);
    }

    public function test_reiter_behalten_die_filter_und_der_weg_in_die_berichte_steht_da(): void
    {
        $this->bestand();

        $antwort = $this->actingAs($this->admin())
            ->get(route('admin.contracts', ['sparte' => 'kfz', 'abschluss' => '2026-11']))
            ->assertOk();

        // Escaped vergleichen: im HTML steht "&amp;" zwischen den Parametern.
        $antwort->assertSee(route('admin.contracts', ['sparte' => 'kfz', 'abschluss' => '2026-11', 'gruppe' => Contract::GROUP_PENDING, 'herkunft' => 'eigen']));
        $antwort->assertSee(route('admin.reports', ['sparte' => 'kfz', 'monat' => '2026-11']));
        $antwort->assertSee(route('admin.reports.monat', ['monat' => '2026-11']));
    }

    public function test_mitarbeiter_sieht_in_der_liste_nur_sein_portfolio(): void
    {
        $this->bestand();
        $mitarbeiter = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);

        $antwort = $this->actingAs($mitarbeiter)->get(route('admin.contracts'))->assertOk();

        $this->assertSame(0, $antwort->viewData('kennzahlen')['vertraege']);
        $this->assertSame([], $antwort->viewData('gesellschaften'));
    }

    // ------------------------------------------------ Monatsuebersicht

    public function test_monatsuebersicht_zeigt_je_sparte_zahlen_und_namen(): void
    {
        $this->bestand();

        $antwort = $this->actingAs($this->admin())
            ->get(route('admin.reports.monat', ['monat' => '2026-11']))
            ->assertOk()
            ->assertSee('Anna November')
            ->assertSee('Bernd November')
            ->assertDontSee('Olga Oktober');

        $sparten = collect($antwort->viewData('sparten'))->keyBy('typ');
        $this->assertSame(2, $sparten['kfz']['vertraege']);
        $this->assertSame(2, $sparten['kfz']['kunden']);
        $this->assertSame(1, $sparten['haftpflicht']['vertraege']);

        $zahlen = $antwort->viewData('zahlen');
        $this->assertSame(3, $zahlen['vertraege']);
        // Anna steht in zwei Sparten, in der Summe einmal.
        $this->assertSame(2, $zahlen['kunden']);
        $this->assertSame(1, $zahlen['vor_vertraege']);
        $this->assertSame(2, $zahlen['antraege']);
    }

    public function test_monatsuebersicht_zaehlt_wie_die_berichtsliste(): void
    {
        $this->bestand();
        $admin = $this->admin();

        $monat = $this->actingAs($admin)->get(route('admin.reports.monat', ['monat' => '2026-11']))->assertOk();
        $liste = $this->actingAs($admin)->get(route('admin.reports.liste', [
            'liste' => 'vertraege_neu', 'zeitraum' => 'monatswahl', 'monat' => '2026-11',
        ]))->assertOk();

        $this->assertSame($liste->viewData('gesamt'), $monat->viewData('zahlen')['vertraege']);
    }

    public function test_ungueltiger_monat_faellt_auf_den_aktuellen_zurueck(): void
    {
        $antwort = $this->actingAs($this->admin())
            ->get(route('admin.reports.monat', ['monat' => 'kaputt']))
            ->assertOk();

        $this->assertSame('2026-11', $antwort->viewData('monat')->format('Y-m'));
        $antwort->assertSee('keine Verträge abgeschlossen');
    }

    public function test_export_knopf_nur_fuer_die_verwaltung(): void
    {
        $this->bestand();
        $mitarbeiter = User::factory()->create(['role' => 'employee']);

        $this->actingAs($mitarbeiter)->get(route('admin.reports.monat', ['monat' => '2026-11']))
            ->assertOk()->assertDontSee('format=xlsx', false);
        $this->actingAs($this->admin())->get(route('admin.reports.monat', ['monat' => '2026-11']))
            ->assertOk()->assertSee('format=xlsx', false);
    }
}
