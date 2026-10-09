<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Geburtsort ist kein Pflichtfeld mehr (Betreiber-Vorgabe 09.10.2026).
 * Beide Faelle mit Absenden scheitern auf dem alten Stand an
 * "sometimes|required" (ein leeres Feld wird zu null und gilt als fehlend).
 */
class GeburtsortOptionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_beraterwelt_speichert_kunde_ohne_geburtsort(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Max Muster']);
        $kunde = Customer::create(['user_id' => $user->id, 'customer_number' => '2600900', 'birth_place' => 'Damaskus', 'nationality' => 'Deutsch']);

        $this->actingAs($admin)->put(route('admin.customer.update', $kunde->id), [
            'first_name' => 'Max', 'last_name' => 'Muster', 'birth_place' => '', 'nationality' => 'Deutsch',
            'preferred_lang' => 'de', 'customer_type' => 'privat',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNull($kunde->refresh()->birth_place);
    }

    public function test_formular_der_beraterwelt_markiert_geburtsort_nicht_als_pflicht(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Max Muster']);
        $kunde = Customer::create(['user_id' => $user->id, 'customer_number' => '2600901']);

        $html = $this->actingAs($admin)->get(route('admin.customer.edit', $kunde->id))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/name="birth_place"[^>]*required/', $html);
        $this->assertStringNotContainsString('Geburtsort *', $html);
    }

    public function test_portal_profil_ohne_geburtsort_wird_angenommen(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        Customer::create(['user_id' => $user->id, 'customer_number' => '2600902', 'nationality' => 'Deutsch']);

        $this->actingAs($user)->post(route('portal.profile.update'), [
            'birth_place' => '', 'nationality' => 'Deutsch',
        ])->assertSessionHasNoErrors();

        $html = $this->actingAs($user)->get(route('portal.profile'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/name="birth_place"[^>]*required/', $html);
    }
}
