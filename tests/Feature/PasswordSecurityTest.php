<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\PasswordSetupController;
use App\Mail\EmployeeWelcomeMail;
use App\Models\Customer;
use App\Models\User;
use App\Services\Portal\PortalAccessService;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Abnahmefaelle der Passwort-Haertung (Betreiber-Vorgabe 18.08.2026:
 * "Sicherheit sehr hoch" + "Kunden wissen nicht, wie sie ihr Passwort
 * zuruecksetzen").
 *
 * Geprueft wird jeweils das VERHALTEN, nicht die Implementierung:
 *  1. Kein Klartext-Passwort verlaesst mehr das System.
 *  2. Ein system-vergebenes Passwort (Geburtsdatum) haelt nicht dauerhaft.
 *  3. Die Passwort-Regel gilt in ALLEN Pfaden gleich.
 *  4. "Passwort vergessen" funktioniert auch ohne bekannte E-Mail-Adresse
 *     und verraet dabei nicht, wer Kunde ist.
 */
class PasswordSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@dienstly24.de']);
    }

    /** Kunde mit Geburtsdatum -> Startpasswort, aber Wechsel faellig. */
    private function customer(array $customerAttrs = []): Customer
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'email' => 'erika@kunde.de',
            'name' => 'Erika Musterfrau',
        ]);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => '2600123',
            'birth_date' => '1985-03-15',
        ], $customerAttrs));
    }

    // ---------- 1) Kein Klartext-Passwort mehr ----------

    public function test_new_employee_gets_an_invitation_link_and_never_a_password(): void
    {
        $this->actingAs($this->admin)->post(route('admin.employees.store'), [
            'name' => 'Neuer Mitarbeiter',
            'email' => 'neu@dienstly24.de',
            'access_level' => 'full',
        ])->assertRedirect(route('admin.employees'));

        $employee = User::where('email', 'neu@dienstly24.de')->firstOrFail();

        Mail::assertSent(EmployeeWelcomeMail::class, function (EmployeeWelcomeMail $mail) use ($employee) {
            $html = $mail->render();

            // Der Link muss drin sein ...
            $this->assertStringContainsString('/zugang/passwort-festlegen/' . $employee->id, $html);
            // ... und ausdruecklich KEIN Passwort.
            $this->assertStringNotContainsString('Passwort:</strong> ', $html);

            return $mail->hasTo('neu@dienstly24.de');
        });
    }

    public function test_employee_form_no_longer_offers_a_password_field(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.employees.create'))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('name="password"', $html);
    }

    public function test_employee_can_set_own_password_via_invitation_link(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'email' => 'mitarbeiter@dienstly24.de']);
        $url = PasswordSetupController::invitationUrl($employee);

        $this->get($url)->assertOk();

        $this->post($url, [
            'password' => 'mein-langes-mitarbeiter-passwort',
            'password_confirmation' => 'mein-langes-mitarbeiter-passwort',
        ])->assertRedirect(route('login'));

        $employee->refresh();
        $this->assertTrue(Hash::check('mein-langes-mitarbeiter-passwort', $employee->password));
        $this->assertNotNull($employee->password_changed_at);
        $this->assertFalse((bool) $employee->must_change_password);
    }

    /** Ein deaktiviertes Konto darf ueber einen alten Link nicht wiederbelebt werden. */
    public function test_invitation_link_is_dead_for_a_deactivated_account(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $url = PasswordSetupController::invitationUrl($employee);

        $employee->forceFill(['is_active' => false])->save();

        $this->get($url)->assertForbidden();
    }

    // ---------- 2) Startpasswort haelt nicht dauerhaft ----------

    public function test_birthdate_start_password_forces_a_change_on_first_use(): void
    {
        $customer = $this->customer();
        app(PortalAccessService::class)->sendInvitation($customer, $this->admin->id);

        $user = $customer->user->fresh();
        $this->assertTrue($user->must_change_password, 'Startpasswort = Geburtsdatum muss den Wechsel erzwingen.');

        // Login klappt weiterhin mit dem Geburtsdatum ...
        $this->post('/login', ['email' => 'erika@kunde.de', 'password' => '15.03.1985']);
        $this->assertAuthenticated();

        // ... aber jede Portalseite fuehrt zum Passwort-Bildschirm.
        $this->get(route('portal.dashboard'))->assertRedirect(route('password.forced'));
        $this->get(route('password.forced'))->assertOk();
    }

    public function test_forced_change_unlocks_the_portal(): void
    {
        $customer = $this->customer();
        app(PortalAccessService::class)->sendInvitation($customer, $this->admin->id);
        $user = $customer->user->fresh();

        $this->actingAs($user)->post(route('password.forced.store'), [
            'password' => 'mein-ganz-eigenes-passwort',
            'password_confirmation' => 'mein-ganz-eigenes-passwort',
        ])->assertRedirect(route('portal.dashboard'));

        $user->refresh();
        $this->assertFalse((bool) $user->must_change_password);
        $this->assertTrue(Hash::check('mein-ganz-eigenes-passwort', $user->password));

        // Danach ist das Portal normal erreichbar.
        $this->actingAs($user->fresh())->get(route('portal.dashboard'))->assertOk();
    }

    /** Das alte Passwort erneut zu setzen waere ein Klick ins Leere. */
    public function test_forced_change_rejects_the_previous_password(): void
    {
        $customer = $this->customer();
        app(PortalAccessService::class)->sendInvitation($customer, $this->admin->id);

        $this->actingAs($customer->user->fresh())->post(route('password.forced.store'), [
            'password' => '15.03.1985',
            'password_confirmation' => '15.03.1985',
        ])->assertSessionHasErrors('password');
    }

    /** Abmelden muss auch im Zwangswechsel moeglich bleiben (keine Sackgasse). */
    public function test_logout_stays_reachable_during_forced_change(): void
    {
        $customer = $this->customer();
        app(PortalAccessService::class)->sendInvitation($customer, $this->admin->id);

        $this->actingAs($customer->user->fresh())->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    /** Admin-Reset stellt den Zwangswechsel wieder scharf. */
    public function test_admin_portal_reset_reactivates_the_forced_change(): void
    {
        $customer = $this->customer();
        $portal = app(PortalAccessService::class);
        $portal->sendInvitation($customer, $this->admin->id);

        // Kunde hat gewechselt ...
        $customer->user->fresh()->setPassword('erstes-eigenes-passwort');
        $this->assertFalse((bool) $customer->user->fresh()->must_change_password);

        // ... Admin setzt zurueck -> wieder Geburtsdatum + Pflichtwechsel.
        $portal->resetPortal($customer, $this->admin->id);

        $user = $customer->user->fresh();
        $this->assertTrue(Hash::check('15.03.1985', $user->password));
        $this->assertTrue((bool) $user->must_change_password);
    }

    // ---------- 3) Eine Regel in allen Pfaden ----------

    public function test_policy_requires_more_characters_for_staff_than_for_customers(): void
    {
        $this->assertSame(PasswordPolicy::MIN_CUSTOMER, PasswordPolicy::minimumFor('customer'));
        $this->assertSame(PasswordPolicy::MIN_STAFF, PasswordPolicy::minimumFor('admin'));
        $this->assertSame(PasswordPolicy::MIN_STAFF, PasswordPolicy::minimumFor('partner'));
        $this->assertGreaterThan(PasswordPolicy::MIN_CUSTOMER, PasswordPolicy::MIN_STAFF);
    }

    public function test_portal_password_form_rejects_a_too_short_password(): void
    {
        $customer = $this->customer();
        app(PortalAccessService::class)->sendInvitation($customer, $this->admin->id);

        $this->actingAs($customer->user->fresh())->post(route('portal.profile.password'), [
            'current_password' => '15.03.1985',
            'password' => 'kurz123', 'password_confirmation' => 'kurz123',
        ])->assertSessionHasErrors('password');
    }

    public function test_invitation_setup_rejects_a_too_short_password(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $url = PasswordSetupController::invitationUrl($employee);

        $this->post($url, ['password' => 'kurz', 'password_confirmation' => 'kurz'])
            ->assertSessionHasErrors('password');

        $this->assertNull($employee->fresh()->password_changed_at);
    }

    // ---------- 4) Passwort vergessen: einfach UND verschwiegen ----------

    public function test_reset_can_be_requested_with_the_customer_number(): void
    {
        $customer = $this->customer();

        $this->post('/forgot-password', ['identifier' => '2600123'])
            ->assertRedirect(route('password.request.sent'));

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'erika@kunde.de']);
    }

    public function test_reset_can_be_requested_with_the_secondary_email(): void
    {
        $customer = $this->customer(['email2' => 'privat@kunde.de']);

        $this->post('/forgot-password', ['identifier' => 'privat@kunde.de'])
            ->assertRedirect(route('password.request.sent'));

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'erika@kunde.de']);
    }

    /**
     * Kernpunkt: Ein unbekannter Wert fuehrt zur GLEICHEN Antwort wie ein
     * bekannter. Sonst kann jeder Fremde durchprobieren, welche Adressen
     * bei uns Kunde sind (DSGVO Art. 32).
     */
    public function test_unknown_identifier_looks_exactly_like_a_successful_request(): void
    {
        $this->customer();

        $bekannt = $this->post('/forgot-password', ['identifier' => 'erika@kunde.de']);
        $unbekannt = $this->post('/forgot-password', ['identifier' => 'gibtesnicht@example.com']);

        $bekannt->assertRedirect(route('password.request.sent'));
        $unbekannt->assertRedirect(route('password.request.sent'));
        $unbekannt->assertSessionHasNoErrors();

        // Und es entsteht wirklich kein Token fuer das unbekannte Konto.
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'gibtesnicht@example.com']);
    }

    /** Die Ergebnisseite muss die naechsten Schritte wirklich erklaeren. */
    public function test_sent_page_explains_what_happens_next(): void
    {
        $html = $this->get(route('password.request.sent'))->assertOk()->getContent();

        $this->assertStringContainsString('Spam', $html);
        $this->assertStringContainsString(route('support.form'), $html);
        $this->assertStringContainsString(route('password.request'), $html);
    }

    /** Das Formular muss die Kundennummer als Weg ausdruecklich anbieten. */
    public function test_forgot_password_form_offers_the_customer_number(): void
    {
        $html = $this->get('/forgot-password')->assertOk()->getContent();

        $this->assertStringContainsString('name="identifier"', $html);
        $this->assertStringContainsString('Kundennummer', $html);
    }

    /** Aeltere Formulare/Lesezeichen schicken 'email' - das muss weiter gehen. */
    public function test_legacy_email_field_is_still_accepted(): void
    {
        $this->customer();

        $this->post('/forgot-password', ['email' => 'erika@kunde.de'])
            ->assertRedirect(route('password.request.sent'));

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'erika@kunde.de']);
    }

    /** Interne Import-Adressen koennen keine Mail empfangen - das sagen wir klar. */
    public function test_internal_placeholder_address_gets_a_clear_message(): void
    {
        $this->post('/forgot-password', ['identifier' => 'import-1@dienstly24.internal'])
            ->assertSessionHasErrors('identifier');
    }
}
