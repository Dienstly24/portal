<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Support\Matomo;
use Database\Seeders\ServicePageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Waechter fuer die Reichweitenmessung (Betreiber-Entscheidung
 * 18.09.2026: Matomo auf dem eigenen Server statt GA4).
 *
 * Zwei Dinge muessen dauerhaft gelten, und beide wuerden bei einem
 * Verstoss NICHT auffallen:
 *
 * 1. Ohne Einrichtung wird NICHTS ausgeliefert. Ein Messskript, das mit
 *    leerer Adresse rendert, laedt von "/matomo.js" der eigenen Domain -
 *    also 404 auf jeder Seite, ohne sichtbare Folge.
 * 2. Gemessen wird NUR die oeffentliche Website. In Beraterwelt und
 *    Portal stehen Kundendaten in Titeln und Adressen; ein Messwerkzeug
 *    dort waere ein DSGVO-Vorfall, den niemand bemerkt - die Seiten
 *    sehen mit und ohne Messung identisch aus.
 */
class MatomoMessungTest extends TestCase
{
    use RefreshDatabase;

    private function einrichten(string $url = 'https://analytics.dienstly24.de', string $id = '1'): void
    {
        config(['analytics.matomo.url' => $url, 'analytics.matomo.site_id' => $id]);
    }

    public function test_ohne_einrichtung_wird_kein_byte_ausgeliefert(): void
    {
        $this->seed(ServicePageSeeder::class);
        config(['analytics.matomo.url' => null, 'analytics.matomo.site_id' => null]);

        foreach (['/website', '/leistungen', '/leistungen/kfz-versicherung'] as $pfad) {
            $html = $this->get($pfad)->assertOk()->getContent();

            $this->assertStringNotContainsString('matomo.js', $html, $pfad);
            $this->assertStringNotContainsString('_paq', $html, $pfad);
        }

        $this->assertFalse(Matomo::aktiv());
        $this->assertSame('', Matomo::cspOrigin());
    }

    public function test_ohne_einrichtung_bleibt_die_inhaltsrichtlinie_unveraendert(): void
    {
        config(['analytics.matomo.url' => null, 'analytics.matomo.site_id' => null]);

        $richtlinie = (new SecurityHeaders)->policy();

        $this->assertStringContainsString("connect-src 'self'", $richtlinie);
        $this->assertStringNotContainsString('analytics.', $richtlinie);
    }

    public function test_mit_einrichtung_steht_das_skript_auf_der_website(): void
    {
        $this->seed(ServicePageSeeder::class);
        $this->einrichten();

        foreach (['/website', '/leistungen', '/leistungen/kfz-versicherung'] as $pfad) {
            $html = $this->get($pfad)->assertOk()->getContent();

            $this->assertStringContainsString('https:\/\/analytics.dienstly24.de', $html, $pfad);
            $this->assertStringContainsString('matomo.php', $html, $pfad);
            // Ohne Nonce blockiert der Browser das Skript und die
            // Messung bleibt dauerhaft leer - ohne jede Fehlermeldung.
            $this->assertMatchesRegularExpression('#<script nonce="[^"]+">\s*\n\(function#', $html, $pfad);
            $this->assertStringContainsString('_paq', $html, $pfad);
        }
    }

    /**
     * DER WICHTIGSTE FALL: die Anwendungsbereiche bleiben ungemessen,
     * auch wenn Matomo eingerichtet ist.
     */
    public function test_beraterwelt_und_portal_werden_nie_gemessen(): void
    {
        $this->einrichten();

        foreach (['/login', '/register'] as $pfad) {
            $html = $this->get($pfad)->getContent();

            $this->assertStringNotContainsString('_paq', $html, $pfad);
            $this->assertStringNotContainsString('matomo.js', $html, $pfad);
        }

        // Und strukturell: das Partial darf in keiner Vorlage der
        // Anwendungsbereiche stehen. Eine Vorlage mehr, und die Regel
        // waere eine Absprache statt einer Eigenschaft.
        foreach (['layouts/admin', 'layouts/app', 'layouts/partner'] as $layout) {
            $pfad = resource_path('views/'.$layout.'.blade.php');
            if (! is_file($pfad)) {
                continue;
            }
            $this->assertStringNotContainsString('partials.matomo', (string) file_get_contents($pfad), $layout);
        }
    }

    public function test_die_messung_setzt_keine_kennung_und_beachtet_nicht_verfolgen(): void
    {
        $this->seed(ServicePageSeeder::class);
        $this->einrichten();

        $html = $this->get('/leistungen/kfz-versicherung')->assertOk()->getContent();

        $this->assertStringContainsString("_paq.push(['disableCookies'])", $html,
            'Ohne disableCookies braeuchte die Messung eine Einwilligung - genau das war der Grund gegen GA4.');
        $this->assertStringContainsString("_paq.push(['setDoNotTrack', true])", $html);
    }

    public function test_die_kontaktwege_werden_als_ereignis_gezaehlt(): void
    {
        $this->seed(ServicePageSeeder::class);
        $this->einrichten();

        $html = $this->get('/leistungen/kfz-versicherung')->assertOk()->getContent();

        $this->assertStringContainsString("trackEvent', 'Kontakt'", $html);
        $this->assertStringContainsString('data-cta="telefon"', $html);
        $this->assertStringContainsString('data-cta="whatsapp"', $html);
    }

    public function test_der_uebergang_ins_portal_ist_gekennzeichnet(): void
    {
        $this->einrichten();

        $html = $this->get('/website')->assertOk()->getContent();

        $this->assertStringContainsString('data-cta="portal"', $html);
        // enableLinkTracking zaehlt den Klick auf die fremde Adresse -
        // ohne dass im Portal selbst etwas gemessen wird.
        $this->assertStringContainsString("_paq.push(['enableLinkTracking'])", $html);
    }

    public function test_die_richtlinie_gibt_genau_den_eigenen_host_frei(): void
    {
        $this->einrichten('https://analytics.dienstly24.de/matomo');

        $richtlinie = (new SecurityHeaders)->policy();

        // Der URSPRUNG, nicht der Pfad: eine Freigabe ist hostweit.
        $this->assertStringContainsString('script-src ', $richtlinie);
        $this->assertStringContainsString('https://analytics.dienstly24.de', $richtlinie);
        $this->assertStringNotContainsString('analytics.dienstly24.de/matomo', $richtlinie);
        $this->assertStringContainsString("connect-src 'self' https://analytics.dienstly24.de", $richtlinie);

        // Und was SEC-4 entfernt hat, bleibt entfernt.
        $this->assertStringNotContainsString("'unsafe-inline'", explode('script-src ', $richtlinie)[1]);
        $this->assertStringNotContainsString("'unsafe-eval'", $richtlinie);
        $this->assertStringNotContainsString('googletagmanager', $richtlinie);
    }

    /**
     * Ein Tippfehler in der .env darf keinen fremden Skript-Host in die
     * Richtlinie schreiben - dieselbe Strenge wie bei
     * `legal_external_base` (SEC-5). Im Zweifel: Messung aus.
     */
    public function test_unsaubere_adressen_schalten_die_messung_ab(): void
    {
        $faelle = [
            'http://analytics.dienstly24.de' => 'kein https',
            'https://user:pass@analytics.dienstly24.de' => 'Zugangsdaten',
            'https://analytics.dienstly24.de?x=1' => 'Abfrage',
            'https://analytics.dienstly24.de#x' => 'Fragment',
            'javascript:alert(1)' => 'kein Schema https',
            'https://' => 'kein Host',
            'https://analytics.dienstly24.de/pfad mit leerzeichen' => 'unzulaessiger Pfad',
            '' => 'leer',
        ];

        foreach ($faelle as $url => $grund) {
            config(['analytics.matomo.url' => $url, 'analytics.matomo.site_id' => '1']);
            $this->assertNull(Matomo::konfiguration(), "haette abgelehnt werden muessen ($grund): $url");
        }
    }

    public function test_unsaubere_seitennummern_schalten_die_messung_ab(): void
    {
        foreach (['0', 'abc', '1; alert(1)', '-1', '', '12345678'] as $id) {
            config(['analytics.matomo.url' => 'https://analytics.dienstly24.de', 'analytics.matomo.site_id' => $id]);
            $this->assertNull(Matomo::konfiguration(), "haette abgelehnt werden muessen: $id");
        }

        config(['analytics.matomo.url' => 'https://analytics.dienstly24.de', 'analytics.matomo.site_id' => '7']);
        $this->assertSame('7', Matomo::konfiguration()['site_id']);
    }

    /** Ein Pfad ist erlaubt (Matomo liegt oft unter /matomo) - ohne Schraegstrich am Ende. */
    public function test_ein_unterverzeichnis_ist_erlaubt(): void
    {
        $this->einrichten('https://dienstly24.de/matomo/');

        $konf = Matomo::konfiguration();

        $this->assertSame('https://dienstly24.de/matomo', $konf['url']);
        $this->assertSame('https://dienstly24.de', $konf['origin']);
    }

    /** Die Adresse steht NIE im Repository - nur in der Server-.env. */
    public function test_keine_fest_verdrahtete_adresse_im_quelltext(): void
    {
        $dateien = [
            config_path('analytics.php'),
            app_path('Support/Matomo.php'),
            resource_path('views/partials/matomo.blade.php'),
        ];

        foreach ($dateien as $datei) {
            $inhalt = (string) file_get_contents($datei);
            $this->assertStringNotContainsString('https://analytics.', $inhalt, basename($datei));
        }
    }
}
