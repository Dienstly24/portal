<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTwoFactor;
use App\Models\Customer;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Abnahmefaelle der Zwei-Faktor-Anmeldung (Betreiber-Vorgabe 18.08.2026).
 *
 * Kernaussagen, die hier abgesichert werden:
 *  1. Ohne zweiten Faktor kommt niemand in die Beraterwelt.
 *  2. Niemand kann sich dabei aussperren (Einrichtung selbst moeglich,
 *     Ersatzcodes, Abmelden bleibt erreichbar, Admin-/CLI-Reset).
 *  3. Kunden sind bewusst NICHT betroffen.
 *  4. Raten wird begrenzt und protokolliert.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Die Pflicht ist in der Testumgebung standardmaessig AUS (sonst
        // muesste jeder Beraterwelt-Test einen zweiten Faktor einrichten).
        // Hier wird sie ausdruecklich eingeschaltet - das IST der Gegenstand
        // dieser Datei.
        SystemSetting::updateOrCreate(['key' => 'two_factor_required'], ['value' => '1']);
    }

    private function staff(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'employee',
            'email' => 'mitarbeiter@dienstly24.de',
        ], $attrs));
    }

    /** Konto mit fertig eingerichtetem zweiten Faktor. */
    private function withTwoFactor(User $user): string
    {
        $secret = Totp::generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [Hash::make('AAAAA-BBBBB')],
        ])->save();

        return $secret;
    }

    // ---------- 1) Ohne zweiten Faktor kein /admin ----------

    public function test_staff_without_two_factor_is_sent_to_setup(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->get(route('admin.dashboard'))
            ->assertRedirect(route('two_factor.setup'));
    }

    public function test_setup_page_shows_qr_code_and_a_typeable_key(): void
    {
        $user = $this->staff();

        $html = $this->actingAs($user)->get(route('two_factor.setup'))->assertOk()->getContent();

        // QR als INLINE-SVG - keine externe Quelle, keine Datei.
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringNotContainsString('api.qrserver.com', $html);
        $this->assertStringNotContainsString('chart.googleapis.com', $html);

        // Der Schluessel muss auch abtippbar dastehen (Telefon ohne Kamera).
        $secret = $user->fresh()->two_factor_secret;
        $this->assertNotNull($secret);
        $this->assertStringContainsString(Totp::formatSecret($secret), $html);
    }

    public function test_setup_is_only_complete_after_a_valid_code(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->get(route('two_factor.setup'))->assertOk();
        $secret = $user->fresh()->two_factor_secret;

        // Falscher Code -> nicht bestaetigt
        $this->actingAs($user)->post(route('two_factor.setup.store'), ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->two_factor_confirmed_at);

        // Richtiger Code -> bestaetigt, Ersatzcodes einmalig sichtbar
        $this->actingAs($user)->post(route('two_factor.setup.store'), ['code' => Totp::code($secret)])
            ->assertRedirect(route('two_factor.recovery_codes'))
            ->assertSessionHas('recovery_codes');

        $user->refresh();
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertCount(TwoFactorService::RECOVERY_CODE_COUNT, $user->two_factor_recovery_codes);
    }

    /** Ein bereits eingerichtetes Geheimnis darf nie still ersetzt werden. */
    public function test_opening_setup_again_never_replaces_a_working_secret(): void
    {
        $user = $this->staff();
        $secret = $this->withTwoFactor($user);

        $this->actingAs($user)->get(route('two_factor.setup'))
            ->assertRedirect(route('two_factor.recovery_codes'));

        $this->assertSame($secret, $user->fresh()->two_factor_secret);
    }

    public function test_confirmed_staff_must_pass_the_challenge_each_session(): void
    {
        $user = $this->staff();
        $secret = $this->withTwoFactor($user);

        $this->actingAs($user)->get(route('admin.dashboard'))
            ->assertRedirect(route('two_factor.challenge'));

        $this->actingAs($user)->post(route('two_factor.challenge.store'), ['code' => Totp::code($secret)])
            ->assertRedirect(route('admin.dashboard'));

        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_wrong_code_is_rejected_and_logged(): void
    {
        $user = $this->staff();
        $this->withTwoFactor($user);

        $this->actingAs($user)->post(route('two_factor.challenge.store'), ['code' => '123456'])
            ->assertSessionHasErrors('code');

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'two_factor_failed',
            'entity_id' => (string) $user->id,
        ]);
    }

    /** Raten muss teuer werden - der Code hat nur eine Million Varianten. */
    public function test_repeated_wrong_codes_are_rate_limited(): void
    {
        $user = $this->staff();
        $this->withTwoFactor($user);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->post(route('two_factor.challenge.store'), ['code' => '111111']);
        }

        $response = $this->actingAs($user)->post(route('two_factor.challenge.store'), ['code' => '111111']);
        $response->assertSessionHasErrors('code');
        $this->assertStringContainsString('Zu viele Fehlversuche', session('errors')->first('code'));
    }

    // ---------- 2) Niemand sperrt sich aus ----------

    public function test_a_recovery_code_works_and_is_used_up(): void
    {
        $user = $this->staff();
        $this->withTwoFactor($user);

        $this->actingAs($user)->post(route('two_factor.challenge.store'), ['code' => 'AAAAA-BBBBB'])
            ->assertRedirect(route('admin.dashboard'));

        // Genau einmal - danach ist er verbraucht.
        $this->assertCount(0, $user->fresh()->two_factor_recovery_codes);
        $this->assertDatabaseHas('activity_logs', ['action' => 'two_factor_recovery_code_used']);
    }

    public function test_logout_stays_reachable_without_the_second_factor(): void
    {
        $user = $this->staff();
        $this->withTwoFactor($user);

        $this->actingAs($user)->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_admin_can_reset_a_lost_second_factor(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@dienstly24.de']);
        $this->withTwoFactor($admin);

        $employee = $this->staff();
        $this->withTwoFactor($employee);

        // Admin muss selbst durch die Abfrage - deshalb Sitzung vorbereiten.
        $this->actingAs($admin)
            ->withSession([TwoFactorService::sessionKey($admin) => true])
            ->post(route('admin.employees.reset_two_factor', $employee->id))
            ->assertSessionHas('success');

        $employee->refresh();
        $this->assertFalse($employee->hasTwoFactor());
        $this->assertNull($employee->two_factor_secret);
        $this->assertDatabaseHas('activity_logs', ['action' => 'two_factor_reset_by_admin']);
    }

    /** Nur admin - nicht jeder Manager darf fremde Konten entsperren. */
    public function test_manager_cannot_reset_someone_elses_second_factor(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'email' => 'manager@dienstly24.de']);
        $this->withTwoFactor($manager);
        $employee = $this->staff();
        $this->withTwoFactor($employee);

        $this->actingAs($manager)
            ->withSession([TwoFactorService::sessionKey($manager) => true])
            ->post(route('admin.employees.reset_two_factor', $employee->id))
            ->assertRedirect();

        $this->assertTrue($employee->fresh()->hasTwoFactor());
    }

    public function test_cli_can_rescue_a_locked_out_account(): void
    {
        $user = $this->staff(['email' => 'chef@dienstly24.de']);
        $this->withTwoFactor($user);

        $this->artisan('2fa:zuruecksetzen', ['email' => 'chef@dienstly24.de'])->assertExitCode(0);

        $this->assertFalse($user->fresh()->hasTwoFactor());
    }

    // ---------- 3) Kunden sind nicht betroffen ----------

    public function test_customers_are_not_asked_for_a_second_factor(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'email' => 'kunde@example.de']);
        $customer->forceFill(['portal_password_set_at' => now(), 'password_changed_at' => now()])->save();
        Customer::create([
            'user_id' => $customer->id,
            'customer_number' => '2600999',
            'birth_date' => '1990-01-01',
        ]);

        // Kein Umweg ueber eine 2FA-Seite - das Portal ist direkt da.
        $this->actingAs($customer)->get(route('portal.dashboard'))->assertOk();
    }

    // ---------- 4) Notbremse ----------

    public function test_operator_can_switch_the_requirement_off(): void
    {
        SystemSetting::updateOrCreate(['key' => 'two_factor_required'], ['value' => '0']);

        $user = $this->staff();

        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
    }

    /**
     * Die Voreinstellung ist bewusst umgebungsabhaengig: im BETRIEB AN
     * (eine Schutzschicht, die man erst einschalten muss, ist in der
     * Praxis meistens aus), in TESTS AUS - sonst muesste jeder der
     * hunderten fachfremden Beraterwelt-Tests zusaetzlich einen zweiten
     * Faktor einrichten und wuerde dabei echte Fehler verdecken.
     */
    public function test_testing_environment_defaults_to_off(): void
    {
        SystemSetting::where('key', 'two_factor_required')->delete();

        $this->assertSame('0', EnsureTwoFactor::defaultSetting());
        $this->assertFalse(EnsureTwoFactor::enabled());
    }
}
