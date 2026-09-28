<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Nachpruefung vor dem Merge: Schreibweisen, an denen Host-Pruefungen
     * typischerweise scheitern. Geprueft wird, wohin ein BROWSER die
     * Weiterleitung aufloest - er liest in http(s)-Adressen einen
     * Rueckstrich wie einen Schraegstrich. Gemessen: sieben Faelle
     * (protokoll-relativ, Benutzer-, Subdomain-, Fragment-, Abfrage-Trick,
     * drei Schraegstriche, Grossschreibung) leiteten vor KI-032 auf den
     * fremden Host; die uebrigen halten fest, dass die Pruefung auch bei
     * Adressen ohne erkennbaren Host dicht bleibt.
     */
    #[DataProvider('fremdeZiele')]
    public function test_weiterleitung_verlaesst_nie_den_eigenen_host(string $referer): void
    {
        $ort = (string) $this->withHeader('Referer', $referer)
            ->get(route('locale.switch', 'de'))
            ->assertRedirect()
            ->headers->get('Location');

        $wieImBrowser = str_replace('\\', '/', $ort);
        $this->assertMatchesRegularExpression('#^https?://#i', $wieImBrowser, 'Kein http(s)-Ziel: '.$ort);
        $this->assertSame(request()->getHost(), strtolower((string) parse_url($wieImBrowser, PHP_URL_HOST)), 'Fremder Host: '.$ort);
        $this->assertNull(parse_url($wieImBrowser, PHP_URL_USER), 'Zugangsdaten-Trick im Ziel: '.$ort);
    }

    /** @return array<string, array{0: string}> */
    public static function fremdeZiele(): array
    {
        return [
            'protokoll-relativ' => ['//evil.example/x'],
            'benutzer-trick' => ['https://localhost@evil.example/'],
            'subdomain-verwechslung' => ['http://localhost.evil.example/'],
            'drei schraegstriche' => ['///evil.example/x'],
            'schema ohne schraegstriche' => ['https:evil.example'],
            'javascript' => ['javascript:alert(1)'],
            'javascript gemischt' => ['JaVaScRiPt:alert(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'rueckstrich nach schraegstrich' => ['/\\evil.example'],
            'doppelter rueckstrich' => ['\\\\evil.example'],
            'rueckstrich-schraegstrich-mix' => ['/\\/evil.example'],
            'fragment-trick' => ['https://evil.example#@localhost'],
            'abfrage-trick' => ['http://evil.example?localhost'],
            'grossschreibung' => ['http://LOCALHOST.evil.example/'],
        ];
    }

    public function test_eigener_referer_bleibt_das_ziel(): void
    {
        $this->withHeader('Referer', url('/leistungen'))
            ->get(route('locale.switch', 'de'))
            ->assertRedirect(url('/leistungen'));
    }
}
