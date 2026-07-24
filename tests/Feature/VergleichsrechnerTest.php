<?php

namespace Tests\Feature;

use App\Models\ServicePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Eingebetteter Partner-Vergleichsrechner auf den Leistungsseiten
 * (config/vergleichsrechner.php): Zwei-Klick-Einwilligung ist Pflicht -
 * das Drittanbieter-Script darf NIE direkt im Server-HTML stehen.
 */
class VergleichsrechnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function makePage(string $slug): ServicePage
    {
        return ServicePage::create([
            'slug' => $slug,
            'title_de' => 'Testseite',
            'is_active' => true,
        ]);
    }

    public function test_kfz_seite_zeigt_einwilligung_statt_direktem_script(): void
    {
        $this->makePage('kfz-versicherung');

        $res = $this->get('/leistungen/kfz-versicherung');
        $res->assertOk();
        // Einwilligungs-Block mit Datenattributen ist da ...
        $res->assertSee('Vergleichsrechner laden');
        $res->assertSee('data-script', false);
        $res->assertSee('form.partner-versicherung.de');
        // ... aber KEIN direkt eingebundenes Drittanbieter-Script (DSGVO).
        $res->assertDontSee('<script src="https://form.partner-versicherung.de', false);
    }

    public function test_seite_ohne_konfigurierten_rechner_zeigt_keinen_block(): void
    {
        $this->makePage('krankenversicherung');

        $res = $this->get('/leistungen/krankenversicherung');
        $res->assertOk();
        $res->assertDontSee('Vergleichsrechner laden');
        $res->assertDontSee('partner-versicherung.de');
    }

    public function test_csp_erlaubt_partner_hosts_auf_html_antworten(): void
    {
        $this->makePage('kfz-versicherung');

        $res = $this->get('/leistungen/kfz-versicherung');
        $csp = (string) $res->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('script-src', $csp);
        $this->assertStringContainsString('https://form.partner-versicherung.de', $csp);
        $this->assertStringContainsString('frame-src', $csp);
    }
}
