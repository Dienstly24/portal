<?php

namespace Tests\Feature;

use App\Mail\CustomerWelcomeMail;
use App\Mail\PasswordResetMail;
use App\Models\Customer;
use App\Models\User;
use App\Services\Portal\PortalAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Kundenportal-Login-Flow: Startpasswort (Geburtsdatum TT.MM.JJJJ),
 * Einladungs-Mail, Passwort-Reset ohne 500, Login-Tracking,
 * Admin-Portal-Controls.
 */
class PortalAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function customer(array $userAttrs = [], array $customerAttrs = []): Customer
    {
        $user = User::factory()->create(array_merge([
            'role' => 'customer', 'name' => 'Erika Musterfrau', 'email' => 'erika@kunde.de',
        ], $userAttrs));

        return Customer::create(array_merge([
            'user_id' => $user->id, 'customer_number' => 'K-' . uniqid(), 'birth_date' => '1985-03-15',
        ], $customerAttrs));
    }

    // ---------- Startpasswort / Einladung ----------

    public function test_invitation_sets_birthdate_password_and_customer_can_login_with_it(): void
    {
        $customer = $this->customer();

        app(PortalAccessService::class)->sendInvitation($customer, $this->admin->id);

        $user = $customer->user->fresh();
        // Startpasswort = Geburtsdatum TT.MM.JJJJ
        $this->assertTrue(Hash::check('15.03.1985', $user->password));
        $this->assertNotNull($user->invitation_sent_at);
        $this->assertNotNull($user->portal_password_set_at);
        Mail::assertSent(CustomerWelcomeMail::class, fn ($m) => $m->hasTo('erika@kunde.de') && $m->mode === 'birthdate');

        // Echter Login über HTTP mit dem Geburtsdatum-Passwort
        $this->post('/login', ['email' => 'erika@kunde.de', 'password' => '15.03.1985'])
            ->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticatedAs($user->fresh());
    }

    /**
     * Kunden tippen ihr Geburtsdatum-Passwort oft in einer abweichenden,
     * aber gleichwertigen Schreibweise. Der Login akzeptiert diese, ohne dass
     * das gespeicherte Passwort (kanonisch TT.MM.JJJJ) angepasst werden muss.
     */
    public function test_customer_can_login_with_equivalent_birthdate_formats(): void
    {
        $customer = $this->customer();
        app(PortalAccessService::class)->sendInvitation($customer, $this->admin->id);
        $user = $customer->user->fresh();

        // Geburtsdatum 15.03.1985 in gleichwertigen Schreibweisen
        foreach (['15.3.1985', '15031985', '1985-03-15', '15-03-1985', '15/03/1985', '15.03.1985 '] as $variant) {
            $this->post('/login', ['email' => 'erika@kunde.de', 'password' => $variant])
                ->assertRedirect(route('portal.dashboard'));
            $this->assertAuthenticatedAs($user);
            $this->post('/logout');
        }
    }

    /** Der Geburtsdatum-Fallback darf keinen Fremdzugriff ermoeglichen. */
    public function test_birthdate_fallback_does_not_bypass_a_self_chosen_password(): void
    {
        // Kunde hat ein eigenes Passwort gesetzt (nicht mehr das Geburtsdatum)
        $customer = $this->customer(['password' => Hash::make('mein-eigenes-pw1')]);

        // Das Geburtsdatum in irgendeiner Schreibweise darf NICHT einloggen
        $this->post('/login', ['email' => 'erika@kunde.de', 'password' => '15.3.1985'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /** Fuehrende/nachfolgende Leerzeichen in der E-Mail brechen den Login nicht. */
    public function test_login_trims_whitespace_around_email(): void
    {
        $customer = $this->customer();
        app(PortalAccessService::class)->sendInvitation($customer, $this->admin->id);

        $this->post('/login', ['email' => '  erika@kunde.de  ', 'password' => '15.03.1985'])
            ->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_welcome_mail_contains_birthdate_rule_but_not_the_date_itself(): void
    {
        $customer = $this->customer();
        app(PortalAccessService::class)->sendInvitation($customer, $this->admin->id);

        Mail::assertSent(CustomerWelcomeMail::class, function (CustomerWelcomeMail $m) {
            $html = $m->render();
            return str_contains($html, 'Ihr erstes Passwort ist Ihr Geburtsdatum im Format TT.MM.JJJJ')
                && str_contains($html, 'portal.dienstly24.de')
                && str_contains($html, 'erika@kunde.de')
                && str_contains($html, 'Passwort vergessen')
                && !str_contains($html, '15.03.1985'); // das echte Datum steht NIE in der Mail
        });
    }

    public function test_invitation_without_birthdate_sends_set_password_link(): void
    {
        $customer = $this->customer([], ['birth_date' => null]);

        app(PortalAccessService::class)->sendInvitation($customer, $this->admin->id);

        $user = $customer->user->fresh();
        $this->assertNull($user->portal_password_set_at); // noch kein nutzbares Passwort
        $this->assertNotNull($user->invitation_sent_at);
        Mail::assertSent(CustomerWelcomeMail::class, function (CustomerWelcomeMail $m) {
            return $m->mode === 'setlink'
                && $m->setPasswordUrl !== null
                && str_contains($m->render(), 'Passwort jetzt festlegen');
        });
    }

    public function test_no_login_mail_for_customers_without_real_email(): void
    {
        $customer = $this->customer(['email' => 'import-abc@dienstly24.internal']);

        try {
            app(PortalAccessService::class)->sendInvitation($customer, $this->admin->id);
            $this->fail('Exception erwartet');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('keine echte E-Mail', $e->getMessage());
        }

        Mail::assertNothingSent();
        $this->assertNull($customer->user->fresh()->invitation_sent_at);
    }

    public function test_store_customer_without_password_uses_birthdate_flow(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.customers.store'), [
            'first_name' => 'Max', 'last_name' => 'Neukunde',
            'email' => 'max@neu.de', 'birth_date' => '1990-01-01',
        ]);

        $response->assertRedirect();
        $user = User::where('email', 'max@neu.de')->first();
        $this->assertTrue(Hash::check('01.01.1990', $user->password));
        $this->assertNotNull($user->invitation_sent_at);
        Mail::assertSent(CustomerWelcomeMail::class, fn ($m) => $m->mode === 'birthdate');
    }

    public function test_store_customer_with_manual_password_still_works(): void
    {
        $this->actingAs($this->admin)->post(route('admin.customers.store'), [
            'first_name' => 'Manu', 'last_name' => 'Ell',
            'email' => 'manu@ell.de', 'password' => 'super-sicher-123',
        ])->assertRedirect();

        $user = User::where('email', 'manu@ell.de')->first();
        $this->assertTrue(Hash::check('super-sicher-123', $user->password));
        $this->assertNotNull($user->portal_password_set_at);
        Mail::assertSent(CustomerWelcomeMail::class, fn ($m) => $m->mode === 'manual');
    }

    // ---------- Passwort-Reset (kein 500 mehr) ----------

    public function test_password_reset_request_sends_german_mail(): void
    {
        $this->customer();

        $this->post(route('password.email'), ['email' => 'erika@kunde.de'])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $m) {
            $html = $m->render();
            return $m->hasTo('erika@kunde.de')
                && str_contains($html, 'Passwort zurücksetzen')
                && str_contains($html, 'Sie erhalten diese E-Mail, weil Sie uns darum gebeten haben')
                && str_contains($html, 'Bitte ignorieren Sie diese E-Mail, falls diese Anfrage nicht von Ihnen stammt');
        });
    }

    public function test_password_reset_for_unknown_email_shows_clear_message_not_500(): void
    {
        $this->post(route('password.email'), ['email' => 'gibtsnicht@example.com'])
            ->assertSessionHasErrors('email')
            ->assertStatus(302); // Redirect zurück, kein 500
    }

    public function test_password_reset_for_internal_placeholder_email_shows_clear_message(): void
    {
        $this->customer(['email' => 'import-xyz@dienstly24.internal']);

        $response = $this->post(route('password.email'), ['email' => 'import-xyz@dienstly24.internal']);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('kein E-Mail-Versand möglich', session('errors')->first('email'));
        Mail::assertNothingSent();
    }

    public function test_mailer_failure_shows_message_instead_of_500(): void
    {
        $this->customer();
        // Mailer wirft (z. B. SMTP down) - vorher: HTTP 500 beim Kunden.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP connection refused'));

        $response = $this->post(route('password.email'), ['email' => 'erika@kunde.de']);

        $response->assertStatus(302)->assertSessionHasErrors('email');
        $this->assertStringContainsString('konnte gerade nicht versendet werden', session('errors')->first('email'));
    }

    public function test_full_reset_cycle_sets_password_and_marks_it_set(): void
    {
        $customer = $this->customer();
        $user = $customer->user;
        $token = Password::broker()->createToken($user);

        $this->post(route('password.store'), [
            'token' => $token, 'email' => 'erika@kunde.de',
            'password' => 'neues-passwort-99', 'password_confirmation' => 'neues-passwort-99',
        ])->assertRedirect(route('login'))->assertSessionHas('status');

        $user->refresh();
        $this->assertTrue(Hash::check('neues-passwort-99', $user->password));
        $this->assertNotNull($user->portal_password_set_at);

        // Login mit neuem Passwort funktioniert
        $this->post('/login', ['email' => 'erika@kunde.de', 'password' => 'neues-passwort-99'])
            ->assertRedirect(route('portal.dashboard'));
    }

    public function test_expired_token_shows_german_error(): void
    {
        $this->customer();

        $this->post(route('password.store'), [
            'token' => 'kaputt', 'email' => 'erika@kunde.de',
            'password' => 'neues-passwort-99', 'password_confirmation' => 'neues-passwort-99',
        ])->assertSessionHasErrors('email');

        $this->assertStringContainsString('abgelaufen oder ungültig', session('errors')->first('email'));
    }

    // ---------- Login-Tracking ----------

    public function test_first_and_last_login_are_tracked(): void
    {
        $customer = $this->customer();
        app(PortalAccessService::class)->sendInvitation($customer, $this->admin->id);

        $this->post('/login', ['email' => 'erika@kunde.de', 'password' => '15.03.1985']);
        $user = $customer->user->fresh();
        $firstLogin = $user->first_login_at;
        $this->assertNotNull($firstLogin);
        $this->assertNotNull($user->last_login_at);

        // Zweiter Login: first_login bleibt, last_login wandert
        auth()->logout();
        $this->travel(1)->hours();
        $this->post('/login', ['email' => 'erika@kunde.de', 'password' => '15.03.1985']);
        $user->refresh();
        $this->assertTrue($user->first_login_at->equalTo($firstLogin));
        $this->assertTrue($user->last_login_at->greaterThan($firstLogin));
    }

    // ---------- Portal-Selbstverwaltung ----------

    public function test_customer_can_change_own_password_after_first_login(): void
    {
        $customer = $this->customer();
        app(PortalAccessService::class)->sendInvitation($customer, $this->admin->id);
        $user = $customer->user->fresh();

        $this->actingAs($user)->post(route('portal.profile.password'), [
            'current_password' => '15.03.1985',
            'password' => 'mein-eigenes-pw1', 'password_confirmation' => 'mein-eigenes-pw1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('mein-eigenes-pw1', $user->fresh()->password));
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $customer = $this->customer();
        app(PortalAccessService::class)->sendInvitation($customer, $this->admin->id);

        $this->actingAs($customer->user->fresh())->post(route('portal.profile.password'), [
            'current_password' => 'falsch',
            'password' => 'mein-eigenes-pw1', 'password_confirmation' => 'mein-eigenes-pw1',
        ])->assertSessionHasErrors('current_password');
    }

    // ---------- Admin-Controls ----------

    public function test_admin_can_resend_invitation_send_reset_link_reset_and_toggle(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin)->post(route('admin.customer.portal.invite', $customer->id))
            ->assertSessionHas('success');
        Mail::assertSent(CustomerWelcomeMail::class);

        $this->actingAs($this->admin)->post(route('admin.customer.portal.reset_link', $customer->id))
            ->assertSessionHas('success');
        Mail::assertSent(PasswordResetMail::class);

        $this->actingAs($this->admin)->post(route('admin.customer.portal.reset', $customer->id))
            ->assertSessionHas('success');

        $this->actingAs($this->admin)->post(route('admin.customer.portal.toggle', $customer->id))
            ->assertSessionHas('success');
        $this->assertFalse((bool) $customer->user->fresh()->is_active);
    }

    public function test_employee_cannot_use_portal_controls(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $customer = $this->customer();

        $this->actingAs($employee)->post(route('admin.customer.portal.invite', $customer->id))
            ->assertRedirect(route('admin.dashboard'));
        Mail::assertNothingSent();
    }

    public function test_portal_status_derivation(): void
    {
        // Kein echter Account
        $c1 = $this->customer(['email' => 'import-1@dienstly24.internal']);
        $this->assertSame('kein_account', $c1->portalStatus()['key']);

        // Nichts passiert -> Passwort nicht gesetzt
        $c2 = $this->customer(['email' => 'c2@kunde.de']);
        $this->assertSame('passwort_nicht_gesetzt', $c2->portalStatus()['key']);

        // Einladung ohne Geburtsdatum -> Einladung gesendet
        $c3 = $this->customer(['email' => 'c3@kunde.de'], ['birth_date' => null]);
        app(PortalAccessService::class)->sendInvitation($c3, $this->admin->id);
        $this->assertSame('einladung_gesendet', $c3->fresh()->portalStatus()['key']);

        // Einladung mit Geburtsdatum -> Aktiviert
        $c4 = $this->customer(['email' => 'c4@kunde.de']);
        app(PortalAccessService::class)->sendInvitation($c4, $this->admin->id);
        $this->assertSame('aktiviert', $c4->fresh()->portalStatus()['key']);

        // Nach Login -> Erster Login erfolgt
        $c4->user->fresh()->forceFill(['first_login_at' => now()])->save();
        $this->assertSame('erster_login', Customer::find($c4->id)->portalStatus()['key']);

        // Deaktiviert
        $c4->user->fresh()->forceFill(['is_active' => false])->save();
        $this->assertSame('deaktiviert', Customer::find($c4->id)->portalStatus()['key']);
    }

    public function test_reminder_mail_skips_customers_without_usable_password(): void
    {
        // Kunde ohne nutzbares Passwort, 4 Tage alt, nie eingeloggt
        $customer = $this->customer(['email' => 'ohne-pw@kunde.de']);
        $customer->user->forceFill(['created_at' => now()->subDays(4), 'portal_password_set_at' => null])->save();

        // Kunde MIT nutzbarem Passwort, gleiche Lage
        $customer2 = $this->customer(['email' => 'mit-pw@kunde.de']);
        $customer2->user->forceFill(['created_at' => now()->subDays(4), 'portal_password_set_at' => now()])->save();

        // Scheduler zur Reminder-Zeit (09:00) laufen lassen
        $this->travelTo(now()->setTime(9, 0));
        \Illuminate\Support\Facades\Artisan::call('schedule:run');

        // Ohne nutzbares Passwort: KEINE "Bitte einloggen"-Mail (Sackgasse)
        Mail::assertNotQueued(\App\Mail\CustomerPortalReminderMail::class, fn ($m) => $m->hasTo('ohne-pw@kunde.de'));
        // Mit nutzbarem Passwort: Reminder geht raus
        Mail::assertQueued(\App\Mail\CustomerPortalReminderMail::class, fn ($m) => $m->hasTo('mit-pw@kunde.de'));
    }
}
