<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zusaetzliche Basic-Auth-Schichten (Betreiber-Bedingung 30.07.2026):
 * /admin bekommt eine zweite Authentifizierungs-Schicht (bis 2FA
 * existiert), Staging-Hosts sind komplett gesperrt + noindex.
 */
class ExtraBasicAuthTest extends TestCase
{
    use RefreshDatabase;

    private function basic(string $cred): array
    {
        return ['Authorization' => 'Basic ' . base64_encode($cred)];
    }

    public function test_admin_requires_second_layer_when_configured(): void
    {
        config(['website.admin_basic_auth' => 'chef:geheim']);
        $admin = User::factory()->create(['role' => 'admin']);

        // Ohne Basic-Auth: 401 samt Challenge - auch mit gueltigem Login.
        $this->actingAs($admin)->get(route('admin.media'))
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate');

        // Falsche Zugangsdaten bleiben draussen.
        $this->actingAs($admin)->withHeaders($this->basic('chef:falsch'))
            ->get(route('admin.media'))
            ->assertStatus(401);

        // Richtige Zugangsdaten + Login: Zugriff wie gewohnt.
        $this->actingAs($admin)->withHeaders($this->basic('chef:geheim'))
            ->get(route('admin.media'))
            ->assertOk();
    }

    public function test_admin_gate_does_not_replace_login(): void
    {
        config(['website.admin_basic_auth' => 'chef:geheim']);

        // Basic-Auth alleine reicht NICHT - ohne Session-Login geht es
        // zur Anmeldung (zweite Schicht ergaenzt, ersetzt nicht).
        $this->withHeaders($this->basic('chef:geheim'))
            ->get(route('admin.media'))
            ->assertRedirect(route('login'));
    }

    public function test_website_and_login_unaffected_by_admin_gate(): void
    {
        config(['website.admin_basic_auth' => 'chef:geheim']);

        $this->get('https://www.dienstly24.de/')->assertOk();
        $this->get('/login')->assertOk();
    }

    public function test_without_config_everything_behaves_as_before(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.media'))->assertOk();
    }

    public function test_staging_host_is_fully_gated_and_noindex(): void
    {
        config([
            'website.extra_hosts' => ['neu.dienstly24.de'],
            'website.staging_hosts' => ['neu.dienstly24.de'],
            'website.staging_basic_auth' => 'preview:pw',
        ]);

        $this->get('https://neu.dienstly24.de/')->assertStatus(401);

        $this->withHeaders($this->basic('preview:pw'))
            ->get('https://neu.dienstly24.de/')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Alle Versicherungen.');

        // Staging-robots.txt sperrt alles - nur der kanonische Host ist offen.
        $this->withHeaders($this->basic('preview:pw'))
            ->get('https://neu.dienstly24.de/robots.txt')
            ->assertOk()
            ->assertDontSee('Sitemap:');
    }
}
