<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalAuthArabicTest extends TestCase
{
    use RefreshDatabase;

    // ---------------- Login-Seite ----------------

    public function test_login_page_shows_new_design_with_register_and_legal_links(): void
    {
        $response = $this->get('/login');
        $response->assertOk()
            ->assertSee('images/logo.png')
            ->assertSee('Willkommen zurück')
            ->assertSee('Konto erstellen')
            ->assertSee('Impressum')
            ->assertSee('Datenschutzerklärung')
            ->assertSee('العربية'); // Sprachumschalter
    }

    public function test_login_page_renders_arabic_rtl_after_switch(): void
    {
        $this->get(route('locale.switch', 'ar'));

        $response = $this->get('/login');
        $response->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('تسجيل الدخول')   // Anmelden
            ->assertSee('إنشاء حساب');    // Konto erstellen
    }

    // ---------------- Registrierung ----------------

    public function test_registration_creates_full_customer_with_year_number(): void
    {
        $response = $this->post('/register', [
            'first_name' => 'Omar',
            'last_name' => 'Beispiel',
            'email' => 'omar@neu.de',
            'birth_date' => '1992-05-10',
            'password' => 'sicheres-passwort-1',
            'password_confirmation' => 'sicheres-passwort-1',
            'agb' => '1',
        ]);

        $response->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticated();

        $user = User::where('email', 'omar@neu.de')->first();
        $this->assertSame('customer', $user->role);

        $customer = Customer::where('user_id', $user->id)->first();
        $this->assertNotNull($customer, 'Registrierung muss eine Kundenakte anlegen.');
        $this->assertSame('website', $customer->source);
        $this->assertSame('1992-05-10', $customer->birth_date);
        $this->assertMatchesRegularExpression('/^\d{7}$/', $customer->customer_number); // JJ+5-stellig
    }

    public function test_registration_honeypot_blocks_bots(): void
    {
        $this->post('/register', [
            'first_name' => 'Bot', 'last_name' => 'Bot',
            'email' => 'bot@bot.de',
            'password' => 'passwort-123', 'password_confirmation' => 'passwort-123',
            'agb' => '1',
            'website' => 'http://spam.example', // Honeypot gefüllt
        ])->assertStatus(422);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'bot@bot.de']);
    }

    public function test_registration_requires_agb(): void
    {
        $this->post('/register', [
            'first_name' => 'Ohne', 'last_name' => 'Agb',
            'email' => 'ohne@agb.de',
            'password' => 'passwort-123', 'password_confirmation' => 'passwort-123',
        ])->assertSessionHasErrors('agb');
    }

    // ---------------- Sprache / RTL im Portal ----------------

    private function makeCustomer(string $lang = 'de'): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . uniqid(),
            'preferred_lang' => $lang,
        ]);
    }

    public function test_portal_renders_arabic_rtl_for_arabic_customer(): void
    {
        $customer = $this->makeCustomer('ar');

        $response = $this->actingAs($customer->user)->get(route('portal.dashboard'));
        $response->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('عقودي')        // Meine Verträge (Nav)
            ->assertSee('نظرة عامة')    // Übersicht
            ->assertSee('تسجيل الخروج'); // Abmelden
    }

    public function test_portal_stays_german_for_german_customer(): void
    {
        $customer = $this->makeCustomer('de');

        $response = $this->actingAs($customer->user)->get(route('portal.dashboard'));
        $response->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('Meine Verträge')
            ->assertSee('Übersicht');
    }

    public function test_locale_switch_persists_for_customer(): void
    {
        $customer = $this->makeCustomer('de');

        $this->actingAs($customer->user)->get(route('locale.switch', 'ar'));

        $this->assertSame('ar', $customer->fresh()->preferred_lang);
    }
}
