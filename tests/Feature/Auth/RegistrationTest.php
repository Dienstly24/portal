<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        // Neues Registrierungsformular: Vor-/Nachname + AGB-Zustimmung;
        // legt zusätzlich eine echte Kundenakte an.
        $response = $this->post('/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            // Mindestlaenge 12 (App\Support\PasswordPolicy)
            'password' => 'test-passwort-2026',
            'password_confirmation' => 'test-passwort-2026',
            'agb' => '1',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('portal.dashboard', absolute: false));
    }
}
