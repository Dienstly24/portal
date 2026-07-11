<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SystemPurgeAndAdminResetTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(string $email, string $role = 'customer'): Customer
    {
        $user = User::factory()->create(['role' => $role, 'email' => $email]);
        return Customer::create(['user_id' => $user->id, 'customer_number' => 'K-' . uniqid()]);
    }

    public function test_deletion_never_removes_staff_account_linked_to_a_customer(): void
    {
        // Fehlkonstellation: ein Kundendatensatz zeigt auf ein Admin-Konto.
        $customer = $this->makeCustomer('boss@dienstly24.de', 'admin');

        app(CustomerDeletionService::class)->delete($customer->fresh(), null);

        // Kunde ist weg, das Admin-Konto bleibt erhalten (kein Aussperren).
        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('users', ['email' => 'boss@dienstly24.de', 'role' => 'admin']);
    }

    public function test_deletion_removes_regular_customer_login(): void
    {
        $customer = $this->makeCustomer('kunde@k.de', 'customer');

        app(CustomerDeletionService::class)->delete($customer->fresh(), null);

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseMissing('users', ['email' => 'kunde@k.de']);
    }

    public function test_admin_set_password_creates_new_admin(): void
    {
        $this->artisan('admin:set-password', ['email' => 'neu@dienstly24.de', 'password' => 'geheim1234'])
            ->assertExitCode(0);

        $user = User::where('email', 'neu@dienstly24.de')->first();
        $this->assertNotNull($user);
        $this->assertSame('admin', $user->role);
        $this->assertTrue(Hash::check('geheim1234', $user->password));
    }

    public function test_admin_set_password_resets_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'chef@dienstly24.de', 'role' => 'admin', 'password' => Hash::make('altesPasswort')]);

        $this->artisan('admin:set-password', ['email' => 'chef@dienstly24.de', 'password' => 'neuesPasswort9'])
            ->assertExitCode(0);

        $this->assertTrue(Hash::check('neuesPasswort9', $user->fresh()->password));
    }

    public function test_admin_set_password_rejects_short_password(): void
    {
        $this->artisan('admin:set-password', ['email' => 'x@dienstly24.de', 'password' => 'kurz'])
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'x@dienstly24.de']);
    }

    public function test_purge_removes_all_customers_but_keeps_staff(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@dienstly24.de']);
        $employee = User::factory()->create(['role' => 'employee', 'email' => 'mitarbeiter@dienstly24.de']);
        $this->makeCustomer('c1@k.de');
        $this->makeCustomer('c2@k.de');
        $this->makeCustomer('c3@k.de');

        $this->assertSame(3, Customer::count());

        $this->artisan('customers:purge', ['--force' => true])->assertExitCode(0);

        $this->assertSame(0, Customer::count());
        // Kunden-Logins weg …
        $this->assertDatabaseMissing('users', ['email' => 'c1@k.de']);
        // … Mitarbeiter und Admin bleiben
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseHas('users', ['id' => $employee->id]);
    }
}
