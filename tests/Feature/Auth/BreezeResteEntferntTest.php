<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * KI-016 (Wissensbasis, 23.09.2026): die Starter-Seiten "E-Mail bestaetigen"
 * und "Passwort bestaetigen" aus dem Laravel-Breeze-Grundgeruest waren noch
 * per URL erreichbar, obwohl keine einzige Route sie benutzte:
 * `User` implementiert bewusst NICHT `MustVerifyEmail` (die Registrierung ist
 * zweistufig, SEC-1), und keine Route traegt die Middleware
 * `password.confirm`. Tote Funktion sieht wie Funktion aus - und die Seiten
 * waren englisch, ohne Uebersetzung, im fremden Aussehen.
 */
class BreezeResteEntferntTest extends TestCase
{
    use RefreshDatabase;

    public function test_die_toten_routen_existieren_nicht_mehr(): void
    {
        foreach (['verification.notice', 'verification.verify', 'verification.send', 'password.confirm'] as $name) {
            $this->assertFalse(Route::has($name), "Route {$name} existiert noch.");
        }
    }

    public function test_die_adressen_antworten_mit_404(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $this->actingAs($user)->get('/verify-email')->assertNotFound();
        $this->actingAs($user)->get('/confirm-password')->assertNotFound();
    }

    public function test_keine_route_verlangt_eine_passwortbestaetigung(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertNotContains('password.confirm', $route->gatherMiddleware(),
                'Route '.$route->uri().' verlangt password.confirm - die Seite dafuer gibt es nicht mehr.');
        }
    }

    public function test_die_dateien_sind_entfernt(): void
    {
        foreach ([
            'resources/views/auth/verify-email.blade.php',
            'resources/views/auth/confirm-password.blade.php',
            'resources/views/layouts/guest.blade.php',
            'app/View/Components/GuestLayout.php',
            'app/Http/Controllers/Auth/ConfirmablePasswordController.php',
            'app/Http/Controllers/Auth/EmailVerificationPromptController.php',
            'app/Http/Controllers/Auth/EmailVerificationNotificationController.php',
            'app/Http/Controllers/Auth/VerifyEmailController.php',
        ] as $datei) {
            $this->assertFileDoesNotExist(base_path($datei));
        }
    }

    public function test_die_regulaeren_passwortwege_bleiben(): void
    {
        foreach (['password.request', 'password.email', 'password.reset', 'password.store', 'password.update', 'password.forced'] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} fehlt.");
        }
    }
}
