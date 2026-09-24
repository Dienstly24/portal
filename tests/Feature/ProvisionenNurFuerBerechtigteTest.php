<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\User;
use App\Models\VermittlerSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Betreiber-Vorgabe 23.09.2026: "Provisionen sieht nur der Admin - keine
 * Mitarbeiter, keine Kunden."
 *
 * Gemeldet am Screenshot der Vertragsakte: die Box "Vermittler / Abrechnung"
 * zeigte den Provisionsbetrag (75,00 EUR) JEDEM, der den Vertrag oeffnen
 * durfte - also auch Mitarbeitern und Support. Dazu reichte an mehreren
 * Stellen die ROLLE manager statt des Rechts `provisionen-verwalten`.
 * Jetzt gilt ueberall das eine Recht (Admin oder ausdruecklich vergeben).
 */
class ProvisionenNurFuerBerechtigteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function vertragMitAbrechnung(): Contract
    {
        $kundeUser = User::factory()->create(['role' => 'customer', 'name' => 'Kunde Provision']);
        $kunde = Customer::create(['user_id' => $kundeUser->id, 'customer_number' => 'C-PROV0001']);
        $vertrag = Contract::create([
            'customer_id' => $kunde->id, 'type' => 'kfz', 'insurer' => 'ADAC', 'status' => 'active',
            'reference_number' => '1417-2871-8270-55', 'vermittler_id' => '9786068',
        ]);
        VermittlerSettlement::create([
            'contract_id' => $vertrag->id, 'vermittler_id' => '9786068',
            'reference_number' => '1417-2871-8270-55', 'produkt' => 'Kfz-Versicherung Abschluss',
            'provision' => 4711.11, 'status_code' => '1',
        ]);

        return $vertrag;
    }

    private function personal(string $rolle, bool $recht = false): User
    {
        return User::factory()->create([
            'role' => $rolle, 'can_see_all_customers' => true, 'can_manage_contracts' => true,
            'can_manage_commissions' => $recht,
        ]);
    }

    public function test_vertragsakte_zeigt_den_provisionsbetrag_nur_berechtigten(): void
    {
        $vertrag = $this->vertragMitAbrechnung();
        $url = route('admin.contract.edit', $vertrag->id);

        foreach (['employee', 'support', 'manager'] as $rolle) {
            $this->actingAs($this->personal($rolle))->get($url)
                ->assertOk()
                ->assertDontSee('4.711,11')
                ->assertDontSee('Vermittler / Abrechnung');
        }

        $this->actingAs($this->personal('admin'))->get($url)
            ->assertOk()->assertSee('Vermittler / Abrechnung')->assertSee('4.711,11');
        // Das Recht wird einzeln vergeben - wer es hat, sieht die Box.
        $this->actingAs($this->personal('employee', true))->get($url)
            ->assertOk()->assertSee('4.711,11');
    }

    public function test_kunde_sieht_im_portal_keinen_provisionsbetrag(): void
    {
        $vertrag = $this->vertragMitAbrechnung();
        $kunde = $vertrag->customer->user;

        $this->actingAs($kunde)->get(route('portal.contracts.show', $vertrag->id))
            ->assertDontSee('4.711,11')
            ->assertDontSee('9786068');
    }

    public function test_provisionsseiten_sind_ohne_recht_gesperrt_auch_fuer_manager(): void
    {
        $seiten = [
            '/admin/provisionen', '/admin/provisionen/dashboard', '/admin/provisionen/bericht',
            '/admin/provisionen/saetze', '/admin/commissions', '/admin/vermittler-abrechnung',
            '/admin/vermittler-abrechnung/bericht', '/admin/provisionsmanagement',
            '/admin/interne-provisionen',
        ];

        foreach (['employee', 'support', 'manager'] as $rolle) {
            $user = $this->personal($rolle);
            foreach ($seiten as $seite) {
                $status = $this->actingAs($user)->get($seite)->getStatusCode();
                $this->assertContains($status, [302, 403], "$rolle erreicht $seite (HTTP $status)");
                $this->assertNotSame(200, $status);
            }
        }

        $admin = $this->personal('admin');
        foreach ($seiten as $seite) {
            $this->actingAs($admin)->get($seite)->assertOk();
        }
        $this->actingAs($this->personal('manager', true))->get('/admin/provisionen')->assertOk();
    }

    public function test_menue_und_berichte_verweisen_ohne_recht_nicht_auf_provisionen(): void
    {
        $manager = $this->personal('manager');

        $this->actingAs($manager)->get(route('admin.reports.neukunden'))
            ->assertOk()
            ->assertDontSee('Provisionen für diesen Zeitraum')
            ->assertDontSee(route('admin.provisions'), false);
        $this->actingAs($manager)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee(route('admin.provisionsmanagement.dashboard'), false)
            ->assertDontSee(route('admin.commissions'), false);

        $this->actingAs($this->personal('admin'))->get(route('admin.reports.neukunden'))
            ->assertOk()->assertSee('Provisionen für diesen Zeitraum');
    }

    public function test_saetze_bleiben_erhalten_wenn_ein_manager_ohne_recht_speichert(): void
    {
        $manager = $this->personal('manager');
        $mitarbeiter = User::factory()->create([
            'role' => 'employee', 'provision_fixed' => 25, 'provision_percent' => 10,
        ]);
        $partner = Partner::create(['name' => 'Partner GmbH', 'provision_fixed' => 30, 'provision_percent' => 5]);

        // Das Formular zeigt die Felder nicht - und sie werden nicht verlangt.
        $this->actingAs($manager)->get(route('admin.partners.show', $partner->id))
            ->assertOk()->assertDontSee('Provision je Neuvertrag')->assertDontSee('Provisionshistorie');

        $this->actingAs($manager)->put(route('admin.employees.update', $mitarbeiter->id), [
            'name' => 'Umbenannt', 'role' => 'employee',
        ]);
        $this->actingAs($manager)->put(route('admin.partners.update', $partner->id), [
            'name' => 'Partner Neu GmbH',
        ]);

        $mitarbeiter->refresh();
        $partner->refresh();
        $this->assertSame('Umbenannt', $mitarbeiter->name);
        $this->assertEquals(25, $mitarbeiter->provision_fixed);
        $this->assertEquals(10, $mitarbeiter->provision_percent);
        $this->assertSame('Partner Neu GmbH', $partner->name);
        $this->assertEquals(30, $partner->provision_fixed);
        $this->assertEquals(5, $partner->provision_percent);

        // Mit dem Recht werden sie wie bisher gespeichert.
        $this->actingAs($this->personal('admin'))->put(route('admin.employees.update', $mitarbeiter->id), [
            'name' => 'Umbenannt', 'role' => 'employee', 'provision_fixed' => '40',
        ]);
        $this->assertEquals(40, $mitarbeiter->refresh()->provision_fixed);
    }
}
