<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * Sprachumschalter (System-Audit 28.09.2026, KI-032): `back()` folgte dem
 * Referer unbesehen - auch auf einen fremden Host.
 */
class SprachumschalterWeiterleitungTest extends TestCase
{
    public function test_fremder_referer_fuehrt_auf_die_eigene_startseite(): void
    {
        $antwort = $this->withHeader('Referer', 'https://fremd.example.org/falle')
            ->get(route('locale.switch', 'ar'));

        $antwort->assertRedirect(url('/'));
        $this->assertStringNotContainsString('fremd.example.org', (string) $antwort->headers->get('Location'));
    }

    public function test_eigener_referer_bleibt_das_ziel(): void
    {
        $this->withHeader('Referer', url('/leistungen'))
            ->get(route('locale.switch', 'de'))
            ->assertRedirect(url('/leistungen'));
    }
}
