<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KI-012 (Teil, Wissensbasis 23.09.2026): Cloudflare Turnstile schuetzt die
 * Registrierung (SEC-1) und laedt dafuer ein Skript von
 * challenges.cloudflare.com - Cloudflare erhaelt damit die IP-Adresse des
 * Besuchers. In der Datenschutzerklaerung stand davon nichts.
 *
 * Wie bei Matomo haengt der Absatz am TATSAECHLICHEN Betrieb: er erscheint
 * genau dann, wenn der Site-Key gesetzt ist, also das Skript geladen wird.
 * Eine Erklaerung, die einen Dienst verschweigt, ist falsch - eine, die
 * einen nicht eingesetzten Dienst nennt, ebenso.
 */
class DatenschutzTurnstileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['website.canonical_host' => 'www.dienstly24.de']);
    }

    public function test_mit_turnstile_nennt_die_erklaerung_cloudflare(): void
    {
        config(['services.turnstile.site_key' => 'site-key-test']);

        $this->get('https://www.dienstly24.de/datenschutz')
            ->assertOk()
            ->assertSee('Cloudflare Turnstile')
            ->assertSee('Art. 6 Abs. 1 lit. f DSGVO', false)
            ->assertSee('Cloudflare, Inc.');
    }

    public function test_ohne_turnstile_steht_dort_nichts_davon(): void
    {
        config(['services.turnstile.site_key' => '']);

        $this->get('https://www.dienstly24.de/datenschutz')
            ->assertOk()
            ->assertDontSee('Cloudflare Turnstile');
    }

    public function test_die_registrierung_laedt_das_beschriebene_skript(): void
    {
        config(['services.turnstile.site_key' => 'site-key-test']);

        // Das Skript, das der Absatz beschreibt, wird wirklich geladen.
        $this->get(route('register'))->assertOk()->assertSee('challenges.cloudflare.com/turnstile', false);
    }
}
