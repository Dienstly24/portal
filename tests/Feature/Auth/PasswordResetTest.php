<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Passwort-Reset läuft jetzt über die deutsche PasswordResetMail
 * (Mailable) statt der englischen Framework-Notification.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $this->get('/forgot-password')->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Mail::assertQueued(PasswordResetMail::class, fn ($m) => $m->hasTo($user->email));
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Mail::assertQueued(PasswordResetMail::class, function (PasswordResetMail $mail) {
            // Token aus der Reset-URL der Mail extrahieren
            $token = basename(parse_url($mail->resetUrl, PHP_URL_PATH));
            $this->get('/reset-password/' . $token)->assertStatus(200);
            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Mail::assertQueued(PasswordResetMail::class, function (PasswordResetMail $mail) use ($user) {
            $token = basename(parse_url($mail->resetUrl, PHP_URL_PATH));

            $this->post('/reset-password', [
                'token' => $token,
                'email' => $user->email,
                'password' => 'neues-passwort-1',
                'password_confirmation' => 'neues-passwort-1',
            ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

            return true;
        });
    }
}
