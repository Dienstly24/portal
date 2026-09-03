<?php

namespace Tests\Feature\Security;

use App\Support\TrustedProxies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Sicherheits-Regressionstests fuer die Proxy-Kette (Audit SEC-2).
 *
 * Abnahmekriterium: Ein Angreifer, der DIREKT den Origin erreicht, darf
 * mit `X-Forwarded-For: beliebige-ip` keinen frischen Rate-Limit-Eimer
 * bekommen - und keine erfundene IP in ActivityLog oder in die
 * DSGVO-Einwilligungsnachweise schreiben.
 *
 * Der Test bildet den Angriff nach: die Anfrage kommt von einer
 * Absender-Adresse, die NICHT in der Trusted-Proxy-Liste steht, und
 * behauptet per Header eine andere.
 */
class ProxySpoofingTest extends TestCase
{
    use RefreshDatabase;

    /** Die Liste ist explizit - nicht mehr "allen vertrauen". */
    public function test_trusted_proxies_are_not_a_wildcard(): void
    {
        $this->assertNotSame('*', TrustedProxies::resolve(),
            'trustProxies steht wieder auf "*" - damit darf jeder seine Client-IP erfinden.');
        $this->assertFalse(TrustedProxies::trustsEveryone());
    }

    /** Cloudflare-Ranges und Loopback sind enthalten. */
    public function test_default_list_covers_cloudflare_and_loopback(): void
    {
        $liste = TrustedProxies::defaults();

        $this->assertContains('127.0.0.1', $liste);
        $this->assertContains('::1', $liste);
        // Stichprobe aus den veroeffentlichten Cloudflare-Ranges.
        $this->assertContains('104.16.0.0/13', $liste);
        $this->assertContains('2606:4700::/32', $liste);
    }

    /**
     * KERNTEST: gefaelschter X-Forwarded-For von einem NICHT
     * vertrauenswuerdigen Absender aendert die erkannte Client-IP nicht.
     */
    public function test_spoofed_forwarded_header_does_not_change_client_ip(): void
    {
        $angreifer = '203.0.113.66';

        $gesehen = $this->ipHinterHeader(
            absender: $angreifer,
            behauptet: '1.2.3.4',
        );

        $this->assertSame($angreifer, $gesehen,
            'Der X-Forwarded-For eines nicht vertrauenswuerdigen Absenders wurde geglaubt.');
    }

    /** Ein ECHTER Proxy (Loopback-nginx) darf die Client-IP melden. */
    public function test_trusted_proxy_may_report_the_client_ip(): void
    {
        $gesehen = $this->ipHinterHeader(
            absender: '127.0.0.1',
            behauptet: '198.51.100.7',
        );

        $this->assertSame('198.51.100.7', $gesehen,
            'Der Header eines eingetragenen Proxys wurde ignoriert - dann sitzen alle Nutzer in einem Eimer.');
    }

    /**
     * Der Rate-Limit-Eimer laesst sich nicht per Header wechseln.
     *
     * Genau dieses Verhalten war der Befund: mit 'trustProxies(at: "*")'
     * bekam jeder neue Header-Wert einen frischen Eimer und die Bremse
     * war wirkungslos.
     */
    public function test_spoofed_header_cannot_reset_the_rate_limit(): void
    {
        Mail::fake();

        $status = [];
        for ($i = 0; $i < 9; $i++) {
            $status[] = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.66'])
                ->withHeaders(['X-Forwarded-For' => '10.0.0.' . $i])
                ->post('/register', [
                    'first_name' => 'Bot',
                    'last_name' => 'Netz',
                    'email' => 'bot' . $i . '@example.com',
                    'password' => 'test-passwort-2026',
                    'password_confirmation' => 'test-passwort-2026',
                    'agb' => '1',
                ])->getStatusCode();
        }

        $this->assertContains(429, $status,
            'Mit gefaelschtem X-Forwarded-For liess sich die Registrierungs-Bremse umgehen.');
    }

    /** Dasselbe fuer die Anmeldung. */
    public function test_spoofed_header_cannot_reset_the_login_rate_limit(): void
    {
        $status = [];
        for ($i = 0; $i < 25; $i++) {
            $status[] = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
                ->withHeaders(['X-Forwarded-For' => '10.1.0.' . $i])
                ->post('/login', [
                    'email' => 'niemand' . $i . '@example.com',
                    'password' => 'falsches-passwort',
                ])->getStatusCode();
        }

        $this->assertContains(429, $status,
            'Mit gefaelschtem X-Forwarded-For liess sich die Login-Bremse umgehen.');
    }

    /** Und fuer "Passwort vergessen" (Mail-Bombing / Adress-Probing). */
    public function test_spoofed_header_cannot_reset_the_password_reset_rate_limit(): void
    {
        Mail::fake();

        $status = [];
        for ($i = 0; $i < 12; $i++) {
            $status[] = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.88'])
                ->withHeaders(['X-Forwarded-For' => '10.2.0.' . $i])
                ->post('/forgot-password', ['email' => 'opfer' . $i . '@example.com'])
                ->getStatusCode();
        }

        $this->assertContains(429, $status,
            'Mit gefaelschtem X-Forwarded-For liess sich die Reset-Bremse umgehen.');
    }

    /**
     * Ermittelt, welche IP die Anwendung bei gegebenem Absender und
     * Header sieht. Eine Wegwerf-Route ist hier ehrlicher als das
     * Nachbauen der Symfony-Interna: geprueft wird, was ein Controller
     * tatsaechlich bekommt.
     */
    private function ipHinterHeader(string $absender, string $behauptet): string
    {
        \Illuminate\Support\Facades\Route::middleware('web')
            ->get('/_test/ip', fn (\Illuminate\Http\Request $r) => $r->ip());

        return $this->withServerVariables(['REMOTE_ADDR' => $absender])
            ->withHeaders(['X-Forwarded-For' => $behauptet])
            ->get('/_test/ip')
            ->getContent();
    }
}
