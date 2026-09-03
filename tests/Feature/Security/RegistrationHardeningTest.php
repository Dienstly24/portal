<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Sicherheits-Regressionstests der Selbst-Registrierung (Audit SEC-1).
 *
 * Diese Tests halten die Eigenschaften fest, die vor SEC-1 fehlten. Sie
 * sind bewusst als "was darf NICHT passieren" formuliert - ein Test, der
 * nur den Gutfall prueft, faellt beim Rueckbau einer Schutzschicht nicht
 * um.
 */
class RegistrationHardeningTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Turnstile / CAPTCHA
    // ------------------------------------------------------------------

    /** Ist Turnstile konfiguriert, scheitert eine Anmeldung OHNE Token. */
    public function test_registration_without_captcha_token_fails(): void
    {
        $this->turnstileEinschalten();
        Mail::fake();

        $response = $this->post('/register', $this->angaben());

        $response->assertSessionHasErrors('cf-turnstile-response');
        $this->assertDatabaseCount('pending_registrations', 0);
        $this->assertDatabaseCount('users', 0);
        Mail::assertNothingSent();
    }

    /** Ein von Cloudflare ABGELEHNTES Token zaehlt nicht. */
    public function test_registration_with_rejected_captcha_token_fails(): void
    {
        $this->turnstileEinschalten();
        Http::fake([
            '*challenges.cloudflare.com*' => Http::response([
                'success' => false,
                'error-codes' => ['invalid-input-response'],
            ]),
        ]);
        Mail::fake();

        $this->post('/register', $this->angaben(['cf-turnstile-response' => 'gefaelscht']))
            ->assertSessionHasErrors('cf-turnstile-response');

        $this->assertDatabaseCount('pending_registrations', 0);
    }

    /** Die Pruefung laeuft SERVERSEITIG - ein Token allein genuegt nicht. */
    public function test_captcha_is_verified_server_side(): void
    {
        $this->turnstileEinschalten();
        Http::fake([
            '*challenges.cloudflare.com*' => Http::response(['success' => true]),
        ]);
        Mail::fake();

        $this->post('/register', $this->angaben(['cf-turnstile-response' => 'gueltig']))
            ->assertRedirect(route('register.pending'));

        // Beweis, dass wirklich bei Cloudflare nachgefragt wurde: ohne den
        // Server-Aufruf waere das Widget bloss Zierde.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'challenges.cloudflare.com')
            && $request['secret'] === 'test-secret');
    }

    /**
     * Faellt Cloudflare aus, wird ABGELEHNT - nicht durchgewunken.
     * Sonst waere "den Dienst stoeren" der Weg, den Bot-Schutz
     * abzuschalten.
     */
    public function test_captcha_outage_rejects_instead_of_passing(): void
    {
        $this->turnstileEinschalten();
        Http::fake(['*challenges.cloudflare.com*' => Http::response('', 500)]);
        Mail::fake();

        $this->post('/register', $this->angaben(['cf-turnstile-response' => 'egal']))
            ->assertSessionHasErrors('cf-turnstile-response');

        $this->assertDatabaseCount('pending_registrations', 0);
    }

    // ------------------------------------------------------------------
    // Kundennummer und Kundenakte
    // ------------------------------------------------------------------

    /** Eine unbestaetigte Registrierung verbraucht KEINE Kundennummer. */
    public function test_unconfirmed_registration_consumes_no_customer_number(): void
    {
        Mail::fake();

        // Zehn Anmeldungen, keine davon bestaetigt. Die Bremse ist hier
        // aus - geprueft wird die NUMMERNVERGABE, nicht das Limit.
        $this->ohneBremse();
        for ($i = 0; $i < 10; $i++) {
            $this->post('/register', $this->angaben([
                'email' => 'bot' . $i . '@example.com',
            ]));
        }

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('users', 0);

        // Danach registriert sich ein echter Kunde und bestaetigt.
        $token = $this->vormerken('echt@example.com');
        $this->get(route('register.verify', ['token' => $token]));

        // Er bekommt die ERSTE Nummer des Jahres - die Bots haben nichts
        // verbraucht.
        $nummer = Customer::first()->customer_number;
        $this->assertSame(now()->format('y') . '00001', $nummer);
    }

    /** Ohne Bestaetigung entsteht keine endgueltige Kundenakte. */
    public function test_unconfirmed_registration_creates_no_customer(): void
    {
        Mail::fake();
        $this->post('/register', $this->angaben());

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseHas('pending_registrations', ['email' => 'test@example.com']);
    }

    // ------------------------------------------------------------------
    // Kein Zustand vor der Bestaetigung
    // ------------------------------------------------------------------

    /** Vor der Bestaetigung gibt es keine angemeldete Sitzung. */
    public function test_no_authenticated_state_before_verification(): void
    {
        Mail::fake();
        $this->post('/register', $this->angaben());

        $this->assertGuest();

        // Und das Portal bleibt verschlossen.
        $this->get(route('portal.dashboard'))->assertRedirect(route('login'));
    }

    /** Vor der Bestaetigung ist auch kein Login moeglich (kein Konto). */
    public function test_no_login_possible_before_verification(): void
    {
        Mail::fake();
        $this->post('/register', $this->angaben());

        $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'test-passwort-2026',
        ]);

        $this->assertGuest();
    }

    /** Das Klartext-Passwort steht nie in der Vormerkung. */
    public function test_pending_registration_stores_no_plaintext_password(): void
    {
        Mail::fake();
        $this->post('/register', $this->angaben());

        $pending = PendingRegistration::first();
        $this->assertNotSame('test-passwort-2026', $pending->password);
        $this->assertTrue(password_verify('test-passwort-2026', $pending->password));
    }

    /** Auch das Token liegt nur als Hash in der Datenbank. */
    public function test_pending_registration_stores_only_a_token_hash(): void
    {
        $token = $this->vormerken();

        $pending = PendingRegistration::first();
        $this->assertNotSame($token, $pending->token_hash);
        $this->assertSame(hash('sha256', $token), $pending->token_hash);
    }

    // ------------------------------------------------------------------
    // "Erneut senden" laesst sich nicht zum Mail-Bombing missbrauchen
    // ------------------------------------------------------------------

    /** Der Zaehler je Vormerkung deckelt den Versand dauerhaft. */
    public function test_resend_cannot_be_used_to_flood_a_mailbox(): void
    {
        Mail::fake();
        $this->post('/register', $this->angaben());
        $this->assertSame(1, PendingRegistration::first()->send_count);

        // Fuenfzig Anlaeufe. Wartezeit und Bremse werden bewusst
        // ausgehebelt, damit hier NUR der Zaehler geprueft wird - er ist
        // die Schicht, die auch ein Botnetz mit vielen IPs nicht umgeht.
        $this->ohneBremse();
        for ($i = 0; $i < 50; $i++) {
            PendingRegistration::query()->update(['last_sent_at' => now()->subHour()]);

            $this->post(route('register.resend'), ['email' => 'test@example.com']);
        }

        // Trotzdem hoechstens MAX_SENDS Mails insgesamt.
        $this->assertSame(
            PendingRegistration::MAX_SENDS,
            PendingRegistration::first()->send_count
        );
        Mail::assertSentCount(PendingRegistration::MAX_SENDS);
    }

    /** Kurz hintereinander gibt es keine zweite Mail (Wartezeit). */
    public function test_resend_has_a_cooldown(): void
    {
        Mail::fake();
        $this->post('/register', $this->angaben());

        $this->post(route('register.resend'), ['email' => 'test@example.com']);

        Mail::assertSentCount(1);
    }

    /** "Erneut senden" verraet nicht, ob es die Adresse gibt. */
    public function test_resend_does_not_disclose_whether_an_address_exists(): void
    {
        Mail::fake();
        $this->post('/register', $this->angaben());

        $this->ohneBremse();
        $bekannt = $this->post(route('register.resend'), ['email' => 'test@example.com']);
        $unbekannt = $this->post(route('register.resend'), ['email' => 'gibtesnicht@example.com']);

        $this->assertSame($bekannt->getStatusCode(), $unbekannt->getStatusCode());
        $this->assertSame(
            $bekannt->getSession()->get('status'),
            $unbekannt->getSession()->get('status')
        );
    }

    /** Wiederholtes "Registrieren" legt keine zweite Vormerkung an. */
    public function test_repeated_registration_does_not_stack_pending_entries(): void
    {
        Mail::fake();

        $this->ohneBremse();
        for ($i = 0; $i < 3; $i++) {
            $this->post('/register', $this->angaben());
        }

        $this->assertDatabaseCount('pending_registrations', 1);
    }

    // ------------------------------------------------------------------
    // Rate-Limit
    // ------------------------------------------------------------------

    /** Die Registrierung ist je IP gebremst. */
    public function test_registration_is_rate_limited_per_ip(): void
    {
        Mail::fake();

        $status = [];
        for ($i = 0; $i < 9; $i++) {
            $status[] = $this->post('/register', $this->angaben([
                'email' => 'nutzer' . $i . '@example.com',
            ]))->getStatusCode();
        }

        $this->assertContains(429, $status, 'Die Registrierung war nicht gebremst.');
    }

    /** Auch je Adresse - gegen ein Botnetz mit vielen IPs. */
    public function test_registration_is_rate_limited_per_email(): void
    {
        Mail::fake();

        // Jede Anfrage kommt von einer ANDEREN IP - der IP-Eimer kann
        // also nicht der Grund sein, wenn gebremst wird.
        $status = [];
        for ($i = 0; $i < 6; $i++) {
            $status[] = $this->withServerVariables([
                'REMOTE_ADDR' => '203.0.113.' . (10 + $i),
            ])->post('/register', $this->angaben())->getStatusCode();
        }

        $this->assertContains(429, $status, 'Der Adress-Eimer hat nicht gegriffen.');
    }

    /** Der Aufraeum-Befehl gibt eine blockierte Adresse wieder frei. */
    public function test_cleanup_releases_an_expired_address(): void
    {
        Mail::fake();
        $this->post('/register', $this->angaben());

        PendingRegistration::query()->update(['expires_at' => now()->subDay()]);

        $this->artisan('registrierungen:aufraeumen')->assertExitCode(0);

        $this->assertDatabaseCount('pending_registrations', 0);
    }

    // ------------------------------------------------------------------
    // Hilfen
    // ------------------------------------------------------------------

    /**
     * Schaltet die Route-Bremse ab.
     *
     * Noetig, weil RateLimiter::clear() den Schluessel eines BENANNTEN
     * Limiters nicht trifft (Laravel hasht Limiter-Name + by()-Wert). In
     * Tests, die etwas anderes als die Bremse pruefen, muss sie deshalb
     * ganz aus dem Weg - sonst prueft der Test am Ende nur noch, dass
     * nach fuenf Anfragen Schluss ist.
     */
    private function ohneBremse(): self
    {
        return $this->withoutMiddleware(
            \Illuminate\Routing\Middleware\ThrottleRequests::class
        );
    }

    private function turnstileEinschalten(): void
    {
        config()->set('services.turnstile.secret_key', 'test-secret');
        config()->set('services.turnstile.site_key', 'test-site');
    }

    /** @return array<string,mixed> */
    private function angaben(array $ueberschreiben = []): array
    {
        return array_merge([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'password' => 'test-passwort-2026',
            'password_confirmation' => 'test-passwort-2026',
            'agb' => '1',
        ], $ueberschreiben);
    }

    private function vormerken(string $email = 'test@example.com'): string
    {
        $token = null;
        Mail::fake();
        $this->post('/register', $this->angaben(['email' => $email]));

        Mail::assertSent(\App\Mail\RegistrationVerificationMail::class, function ($mail) use (&$token) {
            if (preg_match('#/register/bestaetigen/([A-Za-z0-9]+)#', $mail->verifyUrl, $m)) {
                $token = $m[1];
            }

            return true;
        });

        return (string) $token;
    }
}
