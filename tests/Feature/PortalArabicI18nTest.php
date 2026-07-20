<?php
namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sichert die durchgaengige Arabisch/RTL-Uebersetzung der Kundenwelt:
 * waehlt ein Kunde Arabisch, muessen Portal, Login und Mail-Anrede
 * arabisch erscheinen (kein deutscher Resttext an geprueften Stellen).
 */
class PortalArabicI18nTest extends TestCase
{
    use RefreshDatabase;

    private function arCustomer(): User
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Ali Reda']);
        Customer::create(['user_id' => $user->id, 'customer_number' => 'C-AR1', 'preferred_lang' => 'ar']);
        return $user;
    }

    public function test_login_page_switches_to_arabic_via_session(): void
    {
        $res = $this->withSession(['locale' => 'ar'])->get(route('login'))->assertOk();
        $res->assertSee('dir="rtl"', false);
        $res->assertSee('تسجيل الدخول', false); // __('Anmelden')
    }

    public function test_portal_layout_and_pages_render_arabic_for_arabic_customer(): void
    {
        $user = $this->arCustomer();

        // Dashboard: RTL-Layout + arabische Navigation (aus dem Portal-Layout).
        $dash = $this->actingAs($user)->get(route('portal.dashboard'))->assertOk();
        $dash->assertSee('dir="rtl"', false);
        $dash->assertSee('المستندات', false); // __('Dokumente') in der Sidebar

        // Adressen-Seite: frisch uebersetzte Ueberschrift.
        $this->actingAs($user)->get(route('portal.addresses'))->assertOk()
            ->assertSee('عناويني', false);           // __('Meine Adressen')

        // Bankseite: frisch uebersetzter Titel, kein deutscher Resttitel.
        $bank = $this->actingAs($user)->get(route('portal.bank'))->assertOk();
        $bank->assertSee('الحساب البنكي الحالي', false); // __('Aktuelle Bankverbindung')
        $bank->assertDontSee('Aktuelle Bankverbindung', false);
    }

    public function test_english_auth_strings_are_gone(): void
    {
        // Zuvor lieferten die Breeze-Views englische Keys; jetzt Deutsch/AR.
        // password.confirm liegt hinter auth -> als eingeloggter Kunde aufrufen.
        $res = $this->actingAs($this->arCustomer())->get(route('password.confirm'))->assertOk();
        $res->assertDontSee('Please confirm your password', false);
        $res->assertDontSee('This is a secure area', false);
    }
}
