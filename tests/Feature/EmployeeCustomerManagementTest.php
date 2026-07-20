<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractVehicleDetail;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Mitarbeiter-Detailseite (admin.employees.show): sichtbare, durchsuchbare
 * Liste der zugewiesenen Kunden sowie smarte Mehrfach-Zuweisung und Entfernen.
 */
class EmployeeCustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function employee(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'employee', 'can_see_all_customers' => false, 'access_level' => 'limited',
        ], $attrs));
    }

    private function customer(array $userAttrs = [], array $customerAttrs = []): Customer
    {
        $user = User::factory()->create(array_merge([
            'role' => 'customer', 'name' => 'Max Mustermann', 'email' => 'kunde-' . uniqid() . '@kunde.de',
        ], $userAttrs));

        // fresh() -> id kommt als String aus der DB (nicht als UUID-Objekt),
        // damit attach()/Vergleiche mit den Pivot-IDs sauber matchen.
        return Customer::create(array_merge([
            'user_id' => $user->id, 'customer_number' => 'K-' . uniqid(),
        ], $customerAttrs))->fresh();
    }

    // ---------- Anzeige ----------

    public function test_show_page_lists_assigned_customer_names(): void
    {
        $emp = $this->employee();
        $assigned = $this->customer(['name' => 'Zugewiesen Kunde']);
        $other = $this->customer(['name' => 'Fremder Kunde']);
        $emp->assignedCustomers()->attach($assigned->id);

        $res = $this->actingAs($this->admin)->get(route('admin.employees.show', $emp->id));
        $res->assertOk()
            ->assertSee('Zugewiesen Kunde')
            ->assertDontSee('Fremder Kunde');
    }

    public function test_show_page_search_filters_assigned_by_any_field(): void
    {
        $emp = $this->employee();
        $a = $this->customer(['name' => 'Anna Assigned'], ['address_zip' => '24768', 'address_city' => 'Rendsburg']);
        $b = $this->customer(['name' => 'Bodo Assigned'], ['address_zip' => '10115', 'address_city' => 'Berlin']);
        $emp->assignedCustomers()->attach([$a->id, $b->id]);

        // Suche per PLZ innerhalb des Portfolios
        $res = $this->actingAs($this->admin)->get(route('admin.employees.show', [$emp->id, 'q' => '24768']));
        $res->assertOk()->assertSee('Anna Assigned')->assertDontSee('Bodo Assigned');
    }

    public function test_show_page_search_by_plate_within_portfolio(): void
    {
        $emp = $this->employee();
        $a = $this->customer(['name' => 'Kennzeichen Kunde']);
        $contract = Contract::create([
            'customer_id' => $a->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active', 'contract_number' => 'V-' . uniqid(),
        ]);
        ContractVehicleDetail::create(['contract_id' => $contract->id, 'license_plate' => 'RD-XX 777']);
        $b = $this->customer(['name' => 'Anderer Kunde']);
        $emp->assignedCustomers()->attach([$a->id, $b->id]);

        $res = $this->actingAs($this->admin)->get(route('admin.employees.show', [$emp->id, 'q' => 'RD-XX 777']));
        $res->assertOk()->assertSee('Kennzeichen Kunde')->assertDontSee('Anderer Kunde');
    }

    public function test_search_does_not_leak_customers_outside_portfolio(): void
    {
        $emp = $this->employee();
        $assigned = $this->customer(['name' => 'Innen Portfolio'], ['address_city' => 'Rendsburg']);
        $outside = $this->customer(['name' => 'Aussen Portfolio'], ['address_city' => 'Rendsburg']);
        $emp->assignedCustomers()->attach($assigned->id);

        $res = $this->actingAs($this->admin)->get(route('admin.employees.show', [$emp->id, 'q' => 'Rendsburg']));
        $res->assertOk()->assertSee('Innen Portfolio')->assertDontSee('Aussen Portfolio');
    }

    // ---------- Zuweisen (Mehrfach) ----------

    public function test_assign_multiple_customers_at_once(): void
    {
        $emp = $this->employee();
        $c1 = $this->customer(['name' => 'Eins']);
        $c2 = $this->customer(['name' => 'Zwei']);
        $c3 = $this->customer(['name' => 'Drei']);

        $this->actingAs($this->admin)->post(route('admin.employees.assign_customers', $emp->id), [
            'customer_ids' => [$c1->id, $c2->id, $c3->id],
        ])->assertRedirect();

        $this->assertEqualsCanonicalizing(
            [$c1->id, $c2->id, $c3->id],
            $emp->assignedCustomers()->pluck('customers.id')->all()
        );
    }

    public function test_assign_does_not_detach_existing_and_is_idempotent(): void
    {
        $emp = $this->employee();
        $existing = $this->customer(['name' => 'Bestehend']);
        $neu = $this->customer(['name' => 'Neu']);
        $emp->assignedCustomers()->attach($existing->id);

        // Bestehenden erneut + neuen mitschicken -> keine Dublette, bestehender bleibt
        $this->actingAs($this->admin)->post(route('admin.employees.assign_customers', $emp->id), [
            'customer_ids' => [$existing->id, $neu->id],
        ])->assertRedirect();

        $ids = $emp->assignedCustomers()->pluck('customers.id')->all();
        $this->assertEqualsCanonicalizing([$existing->id, $neu->id], $ids);
        $this->assertCount(2, $ids);
    }

    public function test_assign_requires_at_least_one_customer(): void
    {
        $emp = $this->employee();
        $this->actingAs($this->admin)->post(route('admin.employees.assign_customers', $emp->id), [
            'customer_ids' => [],
        ])->assertSessionHasErrors('customer_ids');
    }

    // ---------- Entfernen ----------

    public function test_unassign_removes_single_customer(): void
    {
        $emp = $this->employee();
        $a = $this->customer(['name' => 'Bleibt']);
        $b = $this->customer(['name' => 'Geht']);
        $emp->assignedCustomers()->attach([$a->id, $b->id]);

        $this->actingAs($this->admin)->delete(route('admin.employees.unassign_customer', [$emp->id, $b->id]))
            ->assertRedirect();

        $this->assertEquals([$a->id], $emp->assignedCustomers()->pluck('customers.id')->all());
    }

    // ---------- Zugriff ----------

    public function test_manager_cannot_open_admin_detail_page(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($manager)->get(route('admin.employees.show', $otherAdmin->id))->assertForbidden();
    }

    public function test_search_endpoint_returns_address_field(): void
    {
        $this->customer(['name' => 'Adress Kunde'], ['address_zip' => '24768', 'address_city' => 'Rendsburg', 'address_street' => 'Hauptstrasse', 'address_house_number' => '5']);

        $res = $this->actingAs($this->admin)->getJson(route('admin.employees.customer-search', ['q' => 'Adress Kunde']));
        $res->assertOk();
        $this->assertStringContainsString('Rendsburg', $res->json()[0]['address']);
    }
}
