<?php

namespace Tests\Feature\Auth;

use App\Mail\RegistrationVerificationMail;
use App\Models\Customer;
use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Selbst-Registrierung, ZWEISTUFIG seit Audit SEC-1.
 *
 * Die frueheren Erwartungen dieses Tests ("nach dem POST ist man
 * angemeldet") beschrieben genau das Verhalten, das SEC-1 beseitigt hat:
 * ein oeffentlicher POST erzeugte Konto, Kundenakte und Kundennummer,
 * ohne dass die E-Mail-Adresse je bestaetigt war.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get('/register')->assertStatus(200);
    }

    /** Schritt 1 legt NUR eine Vormerkung an - sonst nichts. */
    public function test_registration_only_creates_a_pending_entry(): void
    {
        Mail::fake();

        $response = $this->post('/register', $this->gueltigeAngaben());

        $response->assertRedirect(route('register.pending'));

        // Kein Konto, keine Kundenakte, keine Sitzung.
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customers', 0);

        $this->assertDatabaseHas('pending_registrations', [
            'email' => 'test@example.com',
            'first_name' => 'Test',
        ]);

        Mail::assertSent(RegistrationVerificationMail::class);
    }

    /** Schritt 2 legt Konto, Kundenakte UND Kundennummer an. */
    public function test_confirmation_creates_the_account(): void
    {
        $token = $this->vormerkenMitToken();

        $response = $this->get(route('register.verify', ['token' => $token]));

        $response->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticated();

        $user = User::where('email', 'test@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('customer', $user->role);
        // Bestaetigt: der Zeitstempel steht.
        $this->assertNotNull($user->email_verified_at);

        $customer = Customer::where('user_id', $user->id)->first();
        $this->assertNotNull($customer);
        $this->assertNotEmpty($customer->customer_number);

        // Die Vormerkung ist verbraucht.
        $this->assertDatabaseCount('pending_registrations', 0);
    }

    /** Das Passwort aus Schritt 1 gilt nach der Bestaetigung. */
    public function test_password_from_step_one_works_after_confirmation(): void
    {
        $token = $this->vormerkenMitToken();
        $this->get(route('register.verify', ['token' => $token]));
        $this->post('/logout');

        $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'test-passwort-2026',
        ]);

        $this->assertAuthenticated();
    }

    public function test_invalid_token_creates_nothing(): void
    {
        $this->vormerkenMitToken();

        $this->get(route('register.verify', ['token' => str_repeat('x', 64)]))
            ->assertRedirect(route('register'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_expired_token_creates_nothing(): void
    {
        $token = $this->vormerkenMitToken();

        PendingRegistration::query()->update(['expires_at' => now()->subMinute()]);

        $this->get(route('register.verify', ['token' => $token]))
            ->assertRedirect(route('register'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        // Die abgelaufene Vormerkung gibt die Adresse wieder frei.
        $this->assertDatabaseCount('pending_registrations', 0);
    }

    /** Ein Token laesst sich nicht zweimal einloesen. */
    public function test_token_cannot_be_used_twice(): void
    {
        $token = $this->vormerkenMitToken();

        $this->get(route('register.verify', ['token' => $token]));
        $this->post('/logout');

        $this->get(route('register.verify', ['token' => $token]))
            ->assertRedirect(route('register'));

        $this->assertSame(1, User::count());
        $this->assertSame(1, Customer::count());
    }

    /** Gibt es das Konto schon (Einladung, Import), entsteht kein zweites. */
    public function test_existing_account_is_not_duplicated(): void
    {
        $token = $this->vormerkenMitToken();

        User::create([
            'name' => 'Schon da',
            'email' => 'test@example.com',
            'password' => bcrypt('etwas-langes-passwort'),
            'role' => 'customer',
        ]);

        $this->get(route('register.verify', ['token' => $token]))
            ->assertRedirect(route('login'));

        $this->assertSame(1, User::where('email', 'test@example.com')->count());
        $this->assertGuest();
    }

    /** Der Honeypot bleibt eine eigene Schicht. */
    public function test_honeypot_blocks_bots(): void
    {
        $this->post('/register', $this->gueltigeAngaben(['website' => 'https://spam.example']))
            ->assertStatus(422);

        $this->assertDatabaseCount('pending_registrations', 0);
    }

    /** @return array<string,mixed> */
    private function gueltigeAngaben(array $ueberschreiben = []): array
    {
        return array_merge([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            // Mindestlaenge 12 (App\Support\PasswordPolicy)
            'password' => 'test-passwort-2026',
            'password_confirmation' => 'test-passwort-2026',
            'agb' => '1',
        ], $ueberschreiben);
    }

    /**
     * Legt eine Vormerkung an und liefert das KLARTEXT-Token aus der
     * verschickten Mail (gespeichert ist nur der Hash).
     */
    private function vormerkenMitToken(): string
    {
        $token = null;

        Mail::fake();
        $this->post('/register', $this->gueltigeAngaben());

        Mail::assertSent(RegistrationVerificationMail::class, function ($mail) use (&$token) {
            if (preg_match('#/register/bestaetigen/([A-Za-z0-9]+)#', $mail->verifyUrl, $m)) {
                $token = $m[1];
            }

            return true;
        });

        $this->assertNotNull($token, 'Kein Bestaetigungstoken in der Mail gefunden.');

        return $token;
    }
}
