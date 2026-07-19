<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Keine Dummy-/Platzhalter-E-Mail: fehlt die echte Adresse, bleibt das Feld
 * leer (NULL). Der Eingang/die Kundenakte markiert das sichtbar, damit der
 * Mitarbeiter die echte E-Mail nachtragen kann.
 */
class NoDummyEmailTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_manual_create_without_email_leaves_it_empty(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.customers.store'), [
            'first_name' => 'Max',
            'last_name' => 'Ohnemail',
            // kein email-Feld
        ]);
        $response->assertRedirect();

        $customer = Customer::firstOrFail();
        $this->assertNull($customer->user->email);
        $this->assertFalse($customer->user->hasRealEmail());
    }

    public function test_manual_create_with_email_keeps_it(): void
    {
        $this->actingAs($this->admin())->post(route('admin.customers.store'), [
            'first_name' => 'Erika',
            'last_name' => 'Mitmail',
            'email' => 'erika@example.com',
        ])->assertRedirect();

        $this->assertSame('erika@example.com', Customer::firstOrFail()->user->email);
    }

    public function test_edit_view_flags_missing_email(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => null, 'name' => 'Leer Mail']);
        $customer = Customer::create(['user_id' => $user->id, 'customer_number' => 'C-NOMAIL']);

        $this->actingAs($this->admin())->get(route('admin.customer.edit', $customer->id))
            ->assertOk()
            ->assertSee('E-Mail fehlt', false);
    }

    public function test_editing_can_add_real_email_later(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => null, 'name' => 'Leer Mail']);
        $customer = Customer::create(['user_id' => $user->id, 'customer_number' => 'C-ADD']);

        $this->actingAs($this->admin())->put(route('admin.customer.update', $customer->id), [
            'first_name' => 'Leer',
            'last_name' => 'Mail',
            'email' => 'neu@example.com',
            'preferred_lang' => 'de',
            'customer_type' => 'privat',
        ])->assertRedirect();

        $this->assertSame('neu@example.com', $user->fresh()->email);
        $this->assertTrue($user->fresh()->hasRealEmail());
    }
}
