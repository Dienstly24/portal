<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regressionsschutz fuer die im Produktions-Audit bestaetigten Fixes.
 */
class AuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function makeCustomer(string $number): Customer
    {
        return Customer::create([
            'user_id' => $this->user('customer')->id,
            'customer_number' => $number,
        ]);
    }

    public function test_customer_merge_is_admin_only(): void
    {
        $customer = $this->makeCustomer('C-MERGE1');

        // Die Zusammenfuehrung loescht den Duplikat-Datensatz + Login hart -
        // Nicht-Admins (auch Manager/Support/Employee) werden von der
        // role:admin-Middleware weggeleitet (302), kommen also nicht durch.
        foreach (['employee', 'manager', 'support'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('admin.customer.merge', $customer->id))
                ->assertStatus(302);
        }

        // Admin wird NICHT weggeleitet (Middleware laesst durch).
        $adminStatus = $this->actingAs($this->user('admin'))
            ->get(route('admin.customer.merge', $customer->id))->status();
        $this->assertNotSame(302, $adminStatus);
    }

    public function test_family_delete_is_registered_as_delete_not_get(): void
    {
        $route = app('router')->getRoutes()->getByName('admin.customer.family.delete');
        $this->assertNotNull($route);
        $this->assertContains('DELETE', $route->methods());
        $this->assertNotContains('GET', $route->methods());
    }
}
