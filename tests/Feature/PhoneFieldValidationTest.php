<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Support\GermanPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Telefon/Mobil-Validierung: eine Mobilnummer gehoert ins Feld "Mobil", eine
 * Festnetznummer ins Feld "Telefon". Nur eindeutige Verwechslungen werden
 * abgewiesen (klare Meldung, wohin die Nummer gehoert).
 */
class PhoneFieldValidationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_helper_classifies_german_numbers(): void
    {
        $this->assertTrue(GermanPhone::isMobile('0176 1234567'));
        $this->assertTrue(GermanPhone::isMobile('+49 152 12345678'));
        $this->assertFalse(GermanPhone::isMobile('040 123456'));

        $this->assertTrue(GermanPhone::isLandline('040 123456'));
        $this->assertTrue(GermanPhone::isLandline('+49 30 901820'));
        $this->assertFalse(GermanPhone::isLandline('0176 1234567'));
    }

    public function test_create_rejects_mobile_in_phone_field(): void
    {
        $this->actingAs($this->admin())->post(route('admin.customers.store'), [
            'first_name' => 'Max', 'last_name' => 'Test', 'email' => 'm@example.com',
            'phone' => '0176 1234567', // Mobilnummer im Festnetz-Feld
        ])->assertSessionHasErrors('phone');
    }

    public function test_create_rejects_landline_in_mobile_field(): void
    {
        $this->actingAs($this->admin())->post(route('admin.customers.store'), [
            'first_name' => 'Max', 'last_name' => 'Test', 'email' => 'm2@example.com',
            'mobile' => '040 123456', // Festnetznummer im Mobil-Feld
        ])->assertSessionHasErrors('mobile');
    }

    public function test_create_accepts_correct_assignment(): void
    {
        $this->actingAs($this->admin())->post(route('admin.customers.store'), [
            'first_name' => 'Max', 'last_name' => 'Richtig', 'email' => 'ok@example.com',
            'phone' => '040 123456', 'mobile' => '0176 1234567',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Customer::count());
    }

    public function test_update_rejects_swapped_numbers(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => 'c@example.com']);
        $customer = Customer::create(['user_id' => $user->id, 'customer_number' => 'C-PH']);

        $this->actingAs($this->admin())->put(route('admin.customer.update', $customer->id), [
            'first_name' => 'C', 'last_name' => 'Ph', 'preferred_lang' => 'de', 'customer_type' => 'privat',
            'mobile' => '030 987654', // Festnetz im Mobil-Feld
        ])->assertSessionHasErrors('mobile');
    }
}
