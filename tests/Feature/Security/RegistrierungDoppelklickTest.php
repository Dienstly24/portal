<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Doppelklick auf den Bestaetigungslink der Registrierung
 * (System-Audit 28.09.2026, KI-039).
 */
class RegistrierungDoppelklickTest extends TestCase
{
    use RefreshDatabase;

    public function test_gleichzeitiger_zweiter_aufruf_endet_nicht_im_500er(): void
    {
        $token = PendingRegistration::newToken();
        PendingRegistration::create([
            'email' => 'neu@kunde.de', 'first_name' => 'Nina', 'last_name' => 'Neu',
            'password' => Hash::make('ein-sehr-langes-passwort'),
            'token_hash' => PendingRegistration::hashToken($token),
            'preferred_lang' => 'de', 'send_count' => 1, 'last_sent_at' => now(),
            'expires_at' => PendingRegistration::freshExpiry(),
        ]);

        // Der ZWEITE Aufruf ist schneller: zwischen Pruefung und Anlage legt
        // er das Konto an. Nachgestellt ueber das creating-Ereignis.
        $einmal = false;
        Event::listen('eloquent.creating: '.User::class, function () use (&$einmal) {
            if ($einmal) {
                return;
            }
            $einmal = true;
            DB::table('users')->insert([
                'name' => 'Nina Neu', 'email' => 'neu@kunde.de', 'password' => 'x', 'role' => 'customer',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->get(route('register.verify', $token))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        // (Die nachgestellte Konkurrenz-Zeile liegt im Test in DERSELBEN
        // Transaktion und wird mit zurueckgerollt - im Betrieb gehoert sie
        // dem anderen Aufruf und bleibt stehen.)
        $this->assertSame(0, Customer::count(), 'Der verlorene Aufruf hat eine Akte hinterlassen.');
    }
}
