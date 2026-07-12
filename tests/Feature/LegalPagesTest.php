<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    // ---------------- Standard: eine Inhaltsquelle = offizielle Website ----------------

    public function test_legal_routes_redirect_to_official_website_by_default(): void
    {
        foreach (['impressum', 'agb', 'datenschutz', 'cookie-richtlinie', 'kontakt'] as $slug) {
            $this->get('/' . $slug)
                ->assertRedirect('https://dienstly24.de/' . $slug);
        }
    }

    public function test_custom_external_base_is_used_for_redirects(): void
    {
        SystemSetting::set('legal_external_base', 'https://dienstly24.com/');

        $this->get('/impressum')->assertRedirect('https://dienstly24.com/impressum');
    }

    public function test_unknown_legal_slug_is_404_and_does_not_shadow_other_routes(): void
    {
        $this->get('/gibt-es-nicht')->assertNotFound();
        // Login/Portal-Routen bleiben unberührt
        $this->get('/login')->assertOk();
    }

    // ---------------- Fallback: Portal-eigene Seiten (Quelle geleert) ----------------

    public function test_portal_pages_render_when_external_base_is_empty(): void
    {
        SystemSetting::set('legal_external_base', '');

        foreach (['impressum', 'agb', 'datenschutz', 'cookie-richtlinie', 'kontakt'] as $slug) {
            $this->get('/' . $slug)->assertOk();
        }
    }

    public function test_portal_impressum_and_kontakt_use_company_settings(): void
    {
        SystemSetting::set('legal_external_base', '');
        SystemSetting::set('company_name', 'Dienstly24 GmbH');
        SystemSetting::set('company_address', "Musterstraße 1\n12345 Musterstadt");
        SystemSetting::set('company_phone', '+49 40 123456');

        $this->get('/impressum')->assertOk()
            ->assertSee('Dienstly24 GmbH')
            ->assertSee('Musterstraße 1');

        $this->get('/kontakt')->assertOk()
            ->assertSee('+49 40 123456')
            ->assertSee('info@dienstly24.de');
    }

    public function test_portal_datenschutz_explains_no_private_mailbox_access(): void
    {
        SystemSetting::set('legal_external_base', '');

        $this->get('/datenschutz')->assertOk()
            ->assertSee('Vertragsbezogene Korrespondenz')
            ->assertSee('nicht')
            ->assertSee('Ihre Rechte');
    }

    // ---------------- Verlinkung: alles zeigt auf die Portal-Routen ----------------

    public function test_login_and_register_link_to_legal_routes(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee(route('legal', 'impressum'))
            ->assertSee(route('legal', 'agb'))
            ->assertSee(route('legal', 'datenschutz'))
            ->assertSee(route('legal', 'cookie-richtlinie'))
            ->assertSee(route('legal', 'kontakt'));

        $this->get('/register')->assertOk()
            ->assertSee(route('legal', 'agb'))
            ->assertSee(route('legal', 'datenschutz'));
    }

    public function test_welcome_mail_links_to_legal_routes(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'customer', 'email' => 'legal@k.de']);
        $customer = \App\Models\Customer::create(['user_id' => $user->id, 'customer_number' => 'K-LEGAL', 'birth_date' => '1990-01-01']);

        $html = (new \App\Mail\CustomerWelcomeMail($customer, 'birthdate'))->render();

        $this->assertStringContainsString(url('/impressum'), $html);
        $this->assertStringContainsString(url('/datenschutz'), $html);
    }
}
