<?php

namespace Tests\Feature;

use App\Mail\CustomerWelcomeMail;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class WelcomeEmailRedesignTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $userAttrs = [], array $custAttrs = []): Customer
    {
        $user = User::factory()->create(array_merge(['role' => 'customer', 'name' => 'Ahmad Albhre', 'email' => 'ahmad@kunde.de'], $userAttrs));
        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'K-' . uniqid(),
            'birth_date' => '1990-01-01',
        ], $custAttrs));
    }

    // ---------------- Neue Mail-Inhalte ----------------

    public function test_welcome_mail_contains_all_redesign_elements(): void
    {
        $mail = new CustomerWelcomeMail($this->customer(), 'birthdate');
        $html = $mail->render();

        // Logo, Hero, persönliche Anrede
        $this->assertStringContainsString('images/logo.png', $html);
        $this->assertStringContainsString('Ihr Kundenportal ist jetzt bereit', $html);
        $this->assertStringContainsString('Hallo Ahmad Albhre', $html);

        // Magic-Login-Button (signierter Link)
        $this->assertStringContainsString('Jetzt automatisch anmelden', $html);
        $this->assertStringContainsString('/magic-login/', $html);
        $this->assertStringContainsString('signature=', $html);

        // Zugangsdaten, Passwort-Box (Regel, nie das echte Datum)
        $this->assertStringContainsString('IHRE ZUGANGSDATEN', $html);
        $this->assertStringContainsString('ahmad@kunde.de', $html);
        $this->assertStringContainsString('Ihr erstes Passwort ist Ihr Geburtsdatum im Format TT.MM.JJJJ', $html);
        $this->assertStringNotContainsString('01.01.1990,', $html); // echtes Datum nie im Klartext-Kontext

        // Steps, Portal-Funktionen, QR, Sicherheit, Support, Footer
        $this->assertStringContainsString('SO STARTEN SIE', $html);
        $this->assertStringContainsString('WAS KÖNNEN SIE IM PORTAL TUN?', $html);
        $this->assertStringContainsString('portal-qr.png', $html);
        $this->assertStringContainsString('niemals per E-Mail oder Telefon nach Ihrem Passwort', $html);
        $this->assertStringContainsString('info@dienstly24.de', $html);
        $this->assertStringContainsString('Impressum', $html);
        $this->assertStringContainsString('Hinweis zum Datenschutz', $html);
    }

    public function test_subject_is_updated(): void
    {
        $mail = new CustomerWelcomeMail($this->customer(), 'birthdate');
        $this->assertStringContainsString('Ihr Kundenportal ist bereit', $mail->envelope()->subject);
    }

    public function test_greeting_uses_gender_when_available(): void
    {
        $c = $this->customer([], ['gender' => 'male']);
        $html = (new CustomerWelcomeMail($c, 'birthdate'))->render();
        $this->assertStringContainsString('Hallo Herr Albhre', $html);
    }

    // ---------------- Magic Login ----------------

    private function magicUrl(User $user, int $days = 90): string
    {
        return URL::temporarySignedRoute('magic.login', now()->addDays($days), ['user' => $user->id]);
    }

    public function test_magic_link_logs_customer_in_and_redirects_to_profile(): void
    {
        $customer = $this->customer();

        $this->get($this->magicUrl($customer->user))
            ->assertRedirect(route('portal.profile'));

        $this->assertAuthenticatedAs($customer->user->fresh());
        $this->assertNotNull($customer->user->fresh()->first_login_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'magic_login_used']);
    }

    public function test_magic_link_with_invalid_signature_is_rejected(): void
    {
        $customer = $this->customer();
        $url = $this->magicUrl($customer->user);

        $this->get($url . 'tampered')->assertForbidden();
        $this->assertGuest();
    }

    public function test_expired_magic_link_is_rejected(): void
    {
        $customer = $this->customer();
        $url = URL::temporarySignedRoute('magic.login', now()->subMinute(), ['user' => $customer->user->id]);

        $this->get($url)->assertForbidden();
        $this->assertGuest();
    }

    public function test_magic_link_never_works_for_staff_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->get($this->magicUrl($admin))->assertForbidden();
        $this->assertGuest();
    }

    public function test_magic_link_rejected_for_deactivated_customer(): void
    {
        $customer = $this->customer();
        $customer->user->forceFill(['is_active' => false])->save();

        $this->get($this->magicUrl($customer->user))->assertForbidden();
        $this->assertGuest();
    }
}
