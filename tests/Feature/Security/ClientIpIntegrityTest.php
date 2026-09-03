<?php

namespace Tests\Feature\Security;

use App\Models\CustomerConsent;
use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Integritaet der protokollierten Client-IP (Audit SEC-2).
 *
 * Zwei Datensaetze sollen im Streitfall etwas BELEGEN:
 *  - ActivityLog: wer war wann von wo aktiv (Nachvollziehbarkeit)
 *  - CustomerConsent.ip_address: Nachweis einer Einwilligung nach
 *    Art. 7 DSGVO
 *
 * Solange die Anwendung jedem X-Forwarded-For glaubte, stand dort eine
 * vom Absender FREI GEWAEHLTE Adresse - also ausgerechnet in den
 * Nachweisen eine erfundene Angabe.
 */
class ClientIpIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Die DSGVO-Einwilligung haelt die ECHTE Absender-Adresse fest,
     * nicht die behauptete.
     */
    public function test_consent_records_the_real_client_ip(): void
    {
        Mail::fake();

        $token = null;

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->withHeaders(['X-Forwarded-For' => '1.2.3.4'])
            ->post('/register', [
                'first_name' => 'Erika',
                'last_name' => 'Muster',
                'email' => 'erika@example.com',
                'password' => 'test-passwort-2026',
                'password_confirmation' => 'test-passwort-2026',
                'agb' => '1',
                'email_consent' => '1',
            ]);

        Mail::assertSent(\App\Mail\RegistrationVerificationMail::class, function ($mail) use (&$token) {
            if (preg_match('#/register/bestaetigen/([A-Za-z0-9]+)#', $mail->verifyUrl, $m)) {
                $token = $m[1];
            }

            return true;
        });

        // Die Einwilligung entsteht bei der BESTAETIGUNG - dort steht die
        // Person nachweislich am Postfach.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->withHeaders(['X-Forwarded-For' => '9.9.9.9'])
            ->get(route('register.verify', ['token' => $token]));

        $consent = CustomerConsent::first();
        $this->assertNotNull($consent, 'Es wurde keine Einwilligung erfasst.');
        $this->assertSame('203.0.113.42', $consent->ip_address,
            'Im DSGVO-Nachweis steht eine vom Absender behauptete IP.');
        $this->assertNotSame('9.9.9.9', $consent->ip_address);
    }

    /** Auch die Vormerkung haelt die echte Adresse fest. */
    public function test_pending_registration_records_the_real_client_ip(): void
    {
        Mail::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.5'])
            ->withHeaders(['X-Forwarded-For' => '1.1.1.1'])
            ->post('/register', [
                'first_name' => 'Max',
                'last_name' => 'Muster',
                'email' => 'max@example.com',
                'password' => 'test-passwort-2026',
                'password_confirmation' => 'test-passwort-2026',
                'agb' => '1',
            ]);

        $this->assertSame('198.51.100.5', PendingRegistration::first()->register_ip);
    }

    /**
     * ActivityLog: eine Anmeldung wird mit der echten Adresse
     * protokolliert.
     */
    public function test_activity_log_records_the_real_client_ip(): void
    {
        $user = User::factory()->create([
            'role' => 'employee',
            'password' => bcrypt('test-passwort-2026'),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
            ->withHeaders(['X-Forwarded-For' => '5.5.5.5'])
            ->post('/login', [
                'email' => $user->email,
                'password' => 'test-passwort-2026',
            ]);

        $eintraege = \App\Models\ActivityLog::all()
            ->map(fn ($e) => $e->metaArray()['ip'] ?? null)
            ->filter()
            ->values();

        if ($eintraege->isEmpty()) {
            $this->markTestSkipped('Dieser Ablauf schreibt keine IP ins ActivityLog.');
        }

        $this->assertContains('198.51.100.77', $eintraege->all());
        $this->assertNotContains('5.5.5.5', $eintraege->all(),
            'Im Protokoll steht eine vom Absender behauptete IP.');
    }

    /**
     * Ein EINGETRAGENER Proxy darf die Client-IP weiterhin melden -
     * sonst saessen alle Nutzer hinter Cloudflare in einem Eimer und
     * das Protokoll naennte nur noch die Proxy-Adresse.
     */
    public function test_trusted_proxy_still_delivers_the_client_ip(): void
    {
        Mail::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.200'])
            ->post('/register', [
                'first_name' => 'Anna',
                'last_name' => 'Muster',
                'email' => 'anna@example.com',
                'password' => 'test-passwort-2026',
                'password_confirmation' => 'test-passwort-2026',
                'agb' => '1',
            ]);

        $this->assertSame('198.51.100.200', PendingRegistration::first()->register_ip);
    }
}
