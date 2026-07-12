<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_five_legal_pages_are_publicly_reachable(): void
    {
        foreach (['impressum', 'agb', 'datenschutz', 'cookie-richtlinie', 'kontakt'] as $slug) {
            $this->get('/' . $slug)->assertOk();
        }
    }

    public function test_unknown_legal_slug_is_404_and_does_not_shadow_other_routes(): void
    {
        $this->get('/gibt-es-nicht')->assertNotFound();
        // Login/Portal-Routen bleiben unberührt
        $this->get('/login')->assertOk();
    }

    public function test_impressum_and_kontakt_use_company_settings(): void
    {
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

    public function test_admin_custom_texts_appear_on_pages(): void
    {
        SystemSetting::set('legal_agb', 'Individueller AGB-Text Absatz 1.');
        SystemSetting::set('legal_impressum', 'USt-IdNr.: DE999999999');

        $this->get('/agb')->assertOk()->assertSee('Individueller AGB-Text Absatz 1.');
        $this->get('/impressum')->assertOk()->assertSee('USt-IdNr.: DE999999999');
    }

    public function test_datenschutz_explains_correspondence_but_no_private_mailbox_access(): void
    {
        $this->get('/datenschutz')->assertOk()
            ->assertSee('Vertragsbezogene Korrespondenz')
            ->assertSee('nicht')
            ->assertSee('Ihre Rechte');
    }

    public function test_login_and_register_link_to_portal_legal_pages(): void
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

    public function test_welcome_mail_links_to_portal_legal_pages(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'customer', 'email' => 'legal@k.de']);
        $customer = \App\Models\Customer::create(['user_id' => $user->id, 'customer_number' => 'K-LEGAL', 'birth_date' => '1990-01-01']);

        $html = (new \App\Mail\CustomerWelcomeMail($customer, 'birthdate'))->render();

        $this->assertStringContainsString(url('/impressum'), $html);
        $this->assertStringContainsString(url('/datenschutz'), $html);
    }
}
