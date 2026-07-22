<?php

namespace Tests\Feature;

use App\Mail\CustomerWelcomeMail;
use App\Mail\PasswordResetMail;
use App\Models\Customer;
use App\Models\User;
use App\Services\Portal\PortalAccessService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Regressionstests fuer die gemeldeten Kundenprobleme:
 * - Willkommens-Mail kam nicht an (Queue-Abhaengigkeit)
 * - Passwort liess sich nicht aendern (Sackgasse nach Magic-Login,
 *   guest-Middleware auf den Reset-Seiten)
 * - Passwort-Reset funktionierte nicht (Set-Link nach 60 Min tot,
 *   Wiedereinladung ueberschrieb gesetzte Passwoerter)
 */
class PasswordFlowHardeningTest extends TestCase
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

    // ---------- Kein Queue-Umweg fuer Zugangs-Mails ----------

    public function test_welcome_and_reset_mail_are_not_queued(): void
    {
        // ShouldQueue haengt den Versand an den Queue-Worker: steht der
        // Worker, kommt die Mail nie an und der Fehler bleibt unsichtbar.
        $this->assertFalse(
            is_subclass_of(CustomerWelcomeMail::class, ShouldQueue::class),
            'CustomerWelcomeMail darf nicht queued sein (Zugangsweg des Kunden).'
        );
        $this->assertFalse(
            is_subclass_of(PasswordResetMail::class, ShouldQueue::class),
            'PasswordResetMail darf nicht queued sein (Kunde wartet aktiv).'
        );
    }

    // ---------- Wiedereinladung erhaelt gesetzte Passwoerter ----------

    public function test_reinvitation_does_not_overwrite_a_usable_birthdate_password(): void
    {
        $customer = $this->customer();
        $portal = app(PortalAccessService::class);

        $portal->sendInvitation($customer, $this->admin->id);
        $user = $customer->user->fresh();
        // Kunde aendert sein Passwort selbst (z.B. ueber das Portal)
        $user->forceFill(['password' => Hash::make('mein-eigenes-pw1'), 'portal_password_set_at' => now()])->save();

        // Erneute Einladung (Button oder 7-Tage-Batch)
        $portal->sendInvitation($customer, $this->admin->id);

        $this->assertTrue(
            Hash::check('mein-eigenes-pw1', $user->fresh()->password),
            'Wiedereinladung darf ein selbst gesetztes Passwort nicht ueberschreiben.'
        );
    }

    public function test_reinvitation_does_not_overwrite_password_set_via_setlink(): void
    {
        $customer = $this->customer([], ['birth_date' => null]);
        $portal = app(PortalAccessService::class);
        $portal->sendInvitation($customer, $this->admin->id);

        // Kunde setzt sein Passwort ueber den Set-Link (Reset-Broker)
        $user = $customer->user->fresh();
        $token = Password::broker()->createToken($user);
        $this->post(route('password.store'), [
            'token' => $token, 'email' => 'erika@kunde.de',
            'password' => 'selbst-gesetzt-1', 'password_confirmation' => 'selbst-gesetzt-1',
        ])->assertSessionHasNoErrors();

        // 7-Tage-Erinnerung: Kunde hat sich noch nie eingeloggt. Frisch
        // aus der DB laden - genau wie der Batch (SendPortalInvitations)
        // den Kunden laedt, nicht die veraltete In-Memory-Relation.
        $portal->sendInvitation(Customer::find($customer->id), $this->admin->id);

        $this->assertTrue(
            Hash::check('selbst-gesetzt-1', $user->fresh()->password),
            'Der Erinnerungs-Batch darf ein per Set-Link gesetztes Passwort nicht zuruecksetzen.'
        );
    }

    public function test_reset_portal_still_forces_new_birthdate_start_password(): void
    {
        $customer = $this->customer();
        $portal = app(PortalAccessService::class);
        $portal->sendInvitation($customer, $this->admin->id);
        $customer->user->fresh()->forceFill(['password' => Hash::make('vergessen-pw1')])->save();

        // Bewusstes Zuruecksetzen durch den Admin erzwingt das Startpasswort
        $portal->resetPortal($customer, $this->admin->id);

        $this->assertTrue(Hash::check('15.03.1985', $customer->user->fresh()->password));
    }

    // ---------- Passwort festlegen ohne aktuelles Passwort ----------

    public function test_customer_without_usable_password_can_set_password_without_current(): void
    {
        // Magic-Login-Fall: Set-Link-Einladung, Passwort = Zufallswert,
        // portal_password_set_at leer -> Kunde kennt KEIN Passwort.
        $customer = $this->customer([], ['birth_date' => null]);
        app(PortalAccessService::class)->sendInvitation($customer, $this->admin->id);
        $user = $customer->user->fresh();
        $this->assertNull($user->portal_password_set_at);

        $this->actingAs($user)->post(route('portal.profile.password'), [
            'password' => 'endlich-eigenes-1', 'password_confirmation' => 'endlich-eigenes-1',
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertTrue(Hash::check('endlich-eigenes-1', $user->password));
        $this->assertNotNull($user->portal_password_set_at);
    }

    public function test_customer_with_usable_password_still_needs_current_password(): void
    {
        $customer = $this->customer();
        app(PortalAccessService::class)->sendInvitation($customer, $this->admin->id);

        // Ohne aktuelles Passwort -> abgelehnt
        $this->actingAs($customer->user->fresh())->post(route('portal.profile.password'), [
            'password' => 'neues-passwort-1', 'password_confirmation' => 'neues-passwort-1',
        ])->assertSessionHasErrors('current_password');
    }

    // ---------- Reset-Seiten trotz bestehender Anmeldung ----------

    public function test_logged_in_customer_can_open_and_use_the_set_password_link(): void
    {
        // Kunde ist per Magic-Login angemeldet und klickt danach den
        // Passwort-Setzen-Link aus der Willkommens-Mail. Vorher: stille
        // Umleitung zum Dashboard (guest-Middleware) - Sackgasse.
        $customer = $this->customer([], ['birth_date' => null]);
        $user = $customer->user;
        $token = Password::broker()->createToken($user);

        $this->actingAs($user)->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk();

        $this->actingAs($user)->post(route('password.store'), [
            'token' => $token, 'email' => 'erika@kunde.de',
            'password' => 'ueber-den-link-1', 'password_confirmation' => 'ueber-den-link-1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('ueber-den-link-1', $user->fresh()->password));
    }

    // ---------- Set-Link: Portal-Domain + laengere Gueltigkeit ----------

    public function test_set_password_link_uses_portal_domain_even_from_admin_host(): void
    {
        $customer = $this->customer([], ['birth_date' => null]);

        // Simuliert den Bau in der Beraterwelt: URL traegt den Admin-Host
        $mail = new CustomerWelcomeMail(
            $customer, 'setlink', null,
            'https://admin.dienstly24.de/reset-password/tok123?email=erika%40kunde.de'
        );

        $this->assertStringStartsWith('https://portal.dienstly24.de/reset-password/tok123', $mail->setPasswordUrl);
        $this->assertStringContainsString('email=erika%40kunde.de', $mail->setPasswordUrl);
    }

    public function test_reset_token_is_still_valid_after_several_hours(): void
    {
        // Willkommens-Mails werden oft erst am Abend gelesen - der
        // fruehere 60-Minuten-Default machte den Set-Link nutzlos.
        $customer = $this->customer([], ['birth_date' => null]);
        $user = $customer->user;
        $token = Password::broker()->createToken($user);

        $this->travel(8)->hours();

        $this->post(route('password.store'), [
            'token' => $token, 'email' => 'erika@kunde.de',
            'password' => 'stunden-spaeter-1', 'password_confirmation' => 'stunden-spaeter-1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('stunden-spaeter-1', $user->fresh()->password));
    }
}
