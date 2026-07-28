<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Betreuer direkt aus der Kundenliste zuweisen (Popover je Zeile) statt ueber
 * Checkbox + Massen-Zuweisung, plus Filter "ohne Betreuer".
 */
class CustomerBetreuerAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'admin', array $attrs = []): User
    {
        return User::factory()->create(array_merge(['role' => $role], $attrs));
    }

    private function customer(string $name = 'Nour Abuzayda'): Customer
    {
        $user = User::factory()->create([
            'role' => 'customer', 'name' => $name, 'email' => 'kunde-' . uniqid() . '@kunde.de',
        ]);

        return Customer::create(['user_id' => $user->id, 'customer_number' => 'K-' . uniqid()]);
    }

    public function test_admin_weist_betreuer_direkt_aus_der_liste_zu(): void
    {
        $admin = $this->staff('admin');
        $employee = $this->staff('employee', ['name' => 'Omar Alshouli']);
        $customer = $this->customer();

        $this->actingAs($admin)
            ->post(route('admin.customers.betreuer', $customer->id), ['betreuer' => [$employee->id]])
            ->assertRedirect();

        $this->assertTrue($customer->fresh()->betreuer->contains('id', $employee->id));
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'customer_reassigned',
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
        ]);
    }

    public function test_auswahl_ersetzt_bisherige_betreuer_und_leere_auswahl_entfernt_alle(): void
    {
        $admin = $this->staff('admin');
        $alt = $this->staff('employee', ['name' => 'Alt Betreuer']);
        $neu = $this->staff('employee', ['name' => 'Neu Betreuer']);
        $customer = $this->customer();
        $customer->betreuer()->sync([$alt->id]);

        $this->actingAs($admin)
            ->post(route('admin.customers.betreuer', $customer->id), ['betreuer' => [$neu->id]]);

        $ids = $customer->fresh()->betreuer->pluck('id')->all();
        $this->assertEquals([$neu->id], $ids);

        $this->actingAs($admin)->post(route('admin.customers.betreuer', $customer->id), []);
        $this->assertCount(0, $customer->fresh()->betreuer);
    }

    public function test_nicht_auswaehlbare_zuweisung_bleibt_erhalten(): void
    {
        // Ein Admin kann ueber die Sichtbarkeit im Neukunden-Bericht zugewiesen
        // sein; im Popover der Kundenliste steht er nicht zur Auswahl und darf
        // durch eine Zuweisung dort nicht still verloren gehen.
        $admin = $this->staff('admin');
        $employee = $this->staff('employee');
        $customer = $this->customer();
        $customer->betreuer()->sync([$admin->id]);

        $this->actingAs($admin)
            ->post(route('admin.customers.betreuer', $customer->id), ['betreuer' => [$employee->id]]);

        $ids = $customer->fresh()->betreuer->pluck('id')->all();
        $this->assertContains($admin->id, $ids);
        $this->assertContains($employee->id, $ids);
    }

    public function test_mitarbeiter_darf_keine_betreuer_setzen(): void
    {
        $employee = $this->staff('employee', ['can_see_all_customers' => true]);
        $customer = $this->customer();

        $this->actingAs($employee)
            ->post(route('admin.customers.betreuer', $customer->id), ['betreuer' => [$employee->id]])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertCount(0, $customer->fresh()->betreuer);
    }

    public function test_liste_zeigt_zuweisung_und_filter_ohne_betreuer(): void
    {
        $admin = $this->staff('admin');
        $employee = $this->staff('employee', ['name' => 'Omar Alshouli']);
        $mitBetreuer = $this->customer('Mit Betreuer');
        $mitBetreuer->betreuer()->sync([$employee->id]);
        $ohneBetreuer = $this->customer('Ohne Betreuer');

        $this->actingAs($admin)->get(route('admin.customers'))
            ->assertOk()
            ->assertSee('Omar Alshouli')
            ->assertSee(route('admin.customers.betreuer', $ohneBetreuer->id));

        $this->actingAs($admin)->get(route('admin.customers', ['betreuer' => 'ohne']))
            ->assertOk()
            ->assertSee('Ohne Betreuer')
            ->assertDontSee('Mit Betreuer');
    }
}
