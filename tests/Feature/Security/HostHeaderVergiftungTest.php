<?php

namespace Tests\Feature\Security;

use App\Mail\PasswordResetMail;
use App\Mail\RegistrationVerificationMail;
use App\Models\User;
use App\Support\VertrauterHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Audit 12.11.2026: Links in Mails, die ein NICHT angemeldeter Besucher
 * ausloest, folgten dem Host-Header der Anfrage. `POST /forgot-password`
 * mit `Host: angreifer.example` und der Kennung des Opfers verschickte eine
 * echte Dienstly24-Mail, deren Reset-Link samt gueltigem Token auf den
 * fremden Host zeigte.
 *
 * Gegenprobe aus der Rolle des Angreifers; beide Faelle scheitern ohne
 * `App\Support\VertrauterHost`.
 */
class HostHeaderVergiftungTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_folgt_nicht_einem_fremden_host_header(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'kunde@example.org', 'role' => 'customer']);

        $this->post('http://angreifer.example/forgot-password', ['identifier' => $user->email]);

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) {
            $this->assertStringNotContainsString('angreifer.example', $mail->resetUrl);
            $this->assertStringStartsWith('https://portal.dienstly24.de/reset-password/', $mail->resetUrl);

            return true;
        });
    }

    public function test_personal_bekommt_den_link_auf_die_beraterwelt(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'berater@example.org', 'role' => 'employee']);

        $this->post('http://angreifer.example/forgot-password', ['identifier' => $user->email]);

        Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail) => str_starts_with($mail->resetUrl, 'https://admin.dienstly24.de/reset-password/'));
    }

    public function test_auf_dem_eigenen_host_bleibt_der_link_unveraendert(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'kunde2@example.org', 'role' => 'customer']);
        $eigener = parse_url((string) config('app.url'), PHP_URL_HOST);

        $this->post('/forgot-password', ['identifier' => $user->email]);

        Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail) => parse_url($mail->resetUrl, PHP_URL_HOST) === $eigener);
    }

    public function test_bestaetigungslink_der_registrierung_folgt_nicht_einem_fremden_host(): void
    {
        $this->get('http://angreifer.example/login');

        $this->assertFalse(VertrauterHost::anfrageIstVertraut());
        $url = VertrauterHost::route('register.verify', ['token' => 'abc']);
        $this->assertSame('https://portal.dienstly24.de/register/bestaetigen/abc', $url);
        $this->assertTrue(class_exists(RegistrationVerificationMail::class));
    }
}
