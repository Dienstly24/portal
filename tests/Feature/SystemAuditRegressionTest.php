<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5((string) $user->id), 0, 8)),
        ]);
    }

    private function restrictedEmployee(): User
    {
        return User::factory()->create([
            'role' => 'employee',
            'can_see_all_customers' => false,
        ]);
    }

    /** Regression for audit M1 (IDOR). */
    public function test_restricted_employee_cannot_open_foreign_customer_edit(): void
    {
        $employee = $this->restrictedEmployee();
        $foreign = $this->makeCustomer();

        $this->actingAs($employee)
            ->get(route('admin.customer.edit', $foreign->id))
            ->assertForbidden();
    }

    public function test_restricted_employee_can_open_assigned_customer_edit(): void
    {
        $employee = $this->restrictedEmployee();
        $assigned = $this->makeCustomer();
        $employee->assignedCustomers()->attach((string) $assigned->id);

        $this->actingAs($employee)
            ->get(route('admin.customer.edit', $assigned->id))
            ->assertOk();
    }

    public function test_admin_can_open_any_customer_edit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();

        $this->actingAs($admin)
            ->get(route('admin.customer.edit', $customer->id))
            ->assertOk();
    }

    /** Regression for audit M4: customer-selected priority was silently dropped. */
    public function test_portal_ticket_keeps_selected_priority(): void
    {
        $customerUser = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customerUser)->post(route('portal.tickets.store'), [
            'type' => 'other',
            'subject' => 'Testanfrage',
            'description' => 'Beschreibung',
            'priority' => 'hoch',
        ])->assertRedirect(route('portal.tickets'));

        $this->assertDatabaseHas('tickets', [
            'subject' => 'Testanfrage',
            'priority' => 'hoch',
        ]);
    }

    /** Regression for audit C4: role column rejected 'manager'. */
    public function test_user_role_can_be_set_to_manager(): void
    {
        $user = User::factory()->create(['role' => 'employee']);
        $user->update(['role' => 'manager']);

        $this->assertSame('manager', $user->fresh()->role);
    }

    /** Regression for audit M2: deactivated users kept working sessions. */
    public function test_deactivated_user_is_logged_out_on_next_request(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'is_active' => false]);

        $this->actingAs($employee)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
