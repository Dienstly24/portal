<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerChangeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The Breeze self-service profile (/profile, incl. account deletion) was
 * removed when the customer portal was built; profile changes go through
 * an approval workflow instead. These tests cover the actual flow.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_profile_page_is_displayed(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $this->actingAs($user)
            ->get(route('portal.profile'))
            ->assertOk();
    }

    public function test_profile_update_creates_pending_approval_request(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $this->actingAs($user)
            ->post(route('portal.profile.update'), ['phone' => '+49 40 123456'])
            ->assertSessionHas('success');

        $request = CustomerChangeRequest::first();
        $this->assertNotNull($request);
        $this->assertSame('profile', $request->type);
        $this->assertSame('+49 40 123456', $request->new_data['phone']);
        $this->assertSame('pending', $request->status);
    }

    public function test_address_entered_in_admin_appears_in_portal_profile(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        // Adresse ueber das Adminportal erfassen (strukturierte Formularfelder).
        $this->actingAs($admin)->post(route('admin.customers.store'), [
            'first_name' => 'Anna', 'last_name' => 'Adresse',
            'email' => 'anna@adresse.de', 'birth_date' => '1990-01-01',
            'street' => 'Musterweg', 'street_nr' => '12', 'plz' => '20095', 'city' => 'Hamburg',
        ])->assertRedirect();

        $customer = Customer::whereHas('user', fn ($q) => $q->where('email', 'anna@adresse.de'))->firstOrFail();

        // Strukturierte Felder sind gefuellt (genau die, die das Portal liest).
        $this->assertSame('Musterweg', $customer->address_street);
        $this->assertSame('12', $customer->address_house_number);
        $this->assertSame('20095', $customer->address_zip);
        $this->assertSame('Hamburg', $customer->address_city);

        // Und die Werte erscheinen im Kundenportal (nicht leer).
        $this->actingAs($customer->user)
            ->get(route('portal.profile'))
            ->assertOk()
            ->assertSee('Musterweg')
            ->assertSee('20095')
            ->assertSee('Hamburg');
    }

    public function test_backfill_command_sets_birthdate_password(): void
    {
        // Importierter Kunde: zufaelliges Passwort, Geburtsdatum vorhanden.
        $user = User::factory()->create(['role' => 'customer', 'portal_password_set_at' => null]);
        $customer = Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'TEST-1',
            'birth_date' => '1995-01-01',
        ]);

        $this->artisan('portal:birthdate-password', ['email' => $user->email])
            ->assertSuccessful();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('01.01.1995', $user->fresh()->password));
        $this->assertNotNull($user->fresh()->portal_password_set_at);
    }

    public function test_staff_cannot_access_the_customer_portal_profile(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);

        $this->actingAs($employee)
            ->get(route('portal.profile'))
            ->assertRedirect(route('admin.dashboard'));
    }
}
