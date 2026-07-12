<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PartnerPortalTest extends TestCase
{
    use RefreshDatabase;

    /** Partner mit Login-User + einem zugeordneten Kunden. */
    private function makePartner(string $email, string $name = 'Partner GmbH'): Partner
    {
        $user = User::factory()->create(['role' => 'partner', 'email' => $email, 'name' => $name]);
        return Partner::create(['name' => $name, 'user_id' => $user->id, 'is_active' => true]);
    }

    private function customerFor(?Partner $partner, string $email): Customer
    {
        $u = User::factory()->create(['role' => 'customer', 'email' => $email]);
        return Customer::create([
            'user_id' => $u->id,
            'partner_id' => $partner?->id,
            'customer_number' => 'C-' . strtoupper(substr(md5($email), 0, 8)),
        ]);
    }

    public function test_partner_can_open_dashboard(): void
    {
        $partner = $this->makePartner('p1@firma.de');
        $this->actingAs($partner->user)->get(route('partner.dashboard'))
            ->assertOk()
            ->assertSee('Partner GmbH');
    }

    public function test_partner_sees_only_own_customers(): void
    {
        $a = $this->makePartner('a@firma.de', 'Alpha');
        $b = $this->makePartner('b@firma.de', 'Beta');
        $mine = $this->customerFor($a, 'mine@k.de');
        $theirs = $this->customerFor($b, 'theirs@k.de');

        $response = $this->actingAs($a->user)->get(route('partner.customers'));
        $response->assertOk();
        $customers = $response->viewData('customers');
        $this->assertTrue($customers->contains('id', $mine->id));
        $this->assertFalse($customers->contains('id', $theirs->id));
    }

    public function test_partner_cannot_open_foreign_customer(): void
    {
        $a = $this->makePartner('a2@firma.de');
        $b = $this->makePartner('b2@firma.de');
        $foreign = $this->customerFor($b, 'foreign@k.de');

        $this->actingAs($a->user)->get(route('partner.customer', $foreign->id))
            ->assertNotFound();
    }

    public function test_partner_sees_only_own_commissions(): void
    {
        $a = $this->makePartner('a3@firma.de');
        $b = $this->makePartner('b3@firma.de');
        Commission::create(['partner_id' => $a->id, 'amount' => 100, 'status' => 'booked', 'statement_date' => now()]);
        Commission::create(['partner_id' => $b->id, 'amount' => 999, 'status' => 'booked', 'statement_date' => now()]);

        $response = $this->actingAs($a->user)->get(route('partner.commissions'));
        $response->assertOk();
        $this->assertSame(1, $response->viewData('commissions')->total());
    }

    public function test_partner_cannot_access_admin_or_customer_portal(): void
    {
        $partner = $this->makePartner('p4@firma.de');
        $this->actingAs($partner->user)->get(route('admin.dashboard'))->assertRedirect(route('partner.dashboard'));
        $this->actingAs($partner->user)->get(route('portal.dashboard'))->assertRedirect(route('partner.dashboard'));
    }

    public function test_customer_and_employee_cannot_access_partner_portal(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $employee = User::factory()->create(['role' => 'employee']);

        $this->actingAs($customer)->get(route('partner.dashboard'))->assertRedirect(route('portal.dashboard'));
        $this->actingAs($employee)->get(route('partner.dashboard'))->assertRedirect(route('admin.dashboard'));
    }

    public function test_partner_role_without_profile_gets_403(): void
    {
        $orphan = User::factory()->create(['role' => 'partner']); // kein Partner-Datensatz
        $this->actingAs($orphan)->get(route('partner.dashboard'))->assertForbidden();
    }

    public function test_partner_can_upload_logo(): void
    {
        Storage::fake('public');
        $partner = $this->makePartner('logo@firma.de');

        $this->actingAs($partner->user)->post(route('partner.profile.update'), [
            'logo' => \Illuminate\Http\UploadedFile::fake()->image('logo.png', 200, 80),
        ])->assertRedirect();

        $partner->refresh();
        $this->assertNotNull($partner->logo_path);
        Storage::disk('public')->assertExists($partner->logo_path);
    }

    public function test_create_partner_login_command(): void
    {
        $partner = Partner::create(['name' => 'CLI Partner', 'is_active' => true]);

        $this->artisan('partner:create-login', [
            'partner_id' => $partner->id,
            'email' => 'cli@firma.de',
            'password' => 'geheim1234',
        ])->assertExitCode(0);

        $partner->refresh();
        $this->assertNotNull($partner->user_id);
        $this->assertSame('partner', $partner->user->role);
    }

    public function test_admin_can_assign_customer_to_partner(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $partner = $this->makePartner('assign@firma.de');
        $customer = $this->customerFor(null, 'unassigned@k.de');

        $this->actingAs($admin)->put(route('admin.customer.update', $customer->id), [
            'first_name' => 'Test', 'last_name' => 'Kunde',
            'preferred_lang' => 'de', 'customer_type' => 'privat',
            'partner_id' => $partner->id,
        ])->assertRedirect();

        $this->assertSame($partner->id, $customer->fresh()->partner_id);
    }
}
