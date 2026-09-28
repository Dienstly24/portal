<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Auth\MagicLoginController;
use App\Http\Controllers\Auth\PasswordSetupController;
use App\Mail\CustomerWelcomeMail;
use App\Models\Customer;
use App\Models\User;
use App\Services\Portal\PortalAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Zugangslinks wirken nur bis zum ersten eigenen Passwort
 * (System-Audit 28.09.2026, KI-026).
 *
 * Magic-Login (90 Tage) und Einladungslink (14 Tage) prueften nur
 * Signatur und Ablauf. Eine alte Mail blieb damit ein zweiter Schluessel
 * zum Konto, auch nachdem der Berechtigte laengst ein eigenes Passwort
 * gewaehlt hatte. Die Faelle unten scheitern ohne die Aenderung.
 */
class EinmalLinkTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => uniqid().'@kunde.de']);

        return Customer::create(['user_id' => $user->id, 'customer_number' => 'K-'.uniqid()]);
    }

    private function magicUrl(User $user): string
    {
        return URL::temporarySignedRoute('magic.login', now()->addDays(MagicLoginController::GUELTIG_TAGE), ['user' => $user->id]);
    }

    public function test_magic_link_meldet_nach_eigenem_passwort_niemanden_mehr_an(): void
    {
        $kunde = $this->kunde();
        $url = $this->magicUrl($kunde->user);

        $this->get($url)->assertRedirect(route('portal.profile'));
        $this->travel(5)->minutes();
        $kunde->user->fresh()->setPassword('ein-eigenes-langes-passwort');
        auth()->logout();

        $this->travel(3)->days();
        $this->get($url)->assertForbidden();
        $this->assertGuest();
    }

    public function test_unbenutzter_magic_link_funktioniert_weiter(): void
    {
        $kunde = $this->kunde();

        $this->get($this->magicUrl($kunde->user))->assertRedirect(route('portal.profile'));
        $this->assertAuthenticatedAs($kunde->user->fresh());
    }

    public function test_neuer_link_nach_dem_passwortwechsel_gilt(): void
    {
        $kunde = $this->kunde();
        $kunde->user->setPassword('ein-eigenes-langes-passwort');

        $this->travel(1)->minutes();
        $this->get($this->magicUrl($kunde->user->fresh()))->assertRedirect(route('portal.profile'));
    }

    public function test_einladungslink_setzt_das_passwort_nur_einmal(): void
    {
        $mitarbeiter = User::factory()->create(['role' => 'employee', 'email' => uniqid().'@dienstly24.de']);
        $url = PasswordSetupController::invitationUrl($mitarbeiter);

        $this->get($url)->assertOk();
        $this->post($url, [
            'password' => 'erstes-sehr-langes-passwort-2026',
            'password_confirmation' => 'erstes-sehr-langes-passwort-2026',
        ])->assertRedirect(route('login'));

        // Tage spaeter: dieselbe (weitergeleitete) Mail.
        $this->travel(3)->days();
        $this->get($url)->assertForbidden();
        $this->post($url, [
            'password' => 'fremdes-sehr-langes-passwort-99',
            'password_confirmation' => 'fremdes-sehr-langes-passwort-99',
        ])->assertForbidden();

        $this->assertTrue(\Hash::check('erstes-sehr-langes-passwort-2026', $mitarbeiter->fresh()->password));
    }

    /**
     * KI-038: das Zuruecksetzen-Formular verriet mit einem BELIEBIGEN Token,
     * ob es zu einer Adresse ein Konto gibt ("kein Konto gefunden" gegen
     * "Link abgelaufen"). Beide Faelle antworten jetzt gleich.
     */
    public function test_reset_formular_verraet_nicht_ob_ein_konto_existiert(): void
    {
        User::factory()->create(['role' => 'customer', 'email' => 'vorhanden@kunde.de']);
        $daten = fn (string $mail) => ['token' => 'geraten', 'email' => $mail,
            'password' => 'ein-sehr-langes-passwort-1', 'password_confirmation' => 'ein-sehr-langes-passwort-1'];

        $meldung = 'Dieser Link ist abgelaufen oder ungültig. Bitte fordern Sie einen neuen Link an.';
        $this->from('/reset-password/geraten')->post(route('password.store'), $daten('vorhanden@kunde.de'))
            ->assertSessionHasErrors(['email' => $meldung]);
        $this->from('/reset-password/geraten')->post(route('password.store'), $daten('niemand@kunde.de'))
            ->assertSessionHasErrors(['email' => $meldung]);
    }

    /**
     * Nachpruefung vor dem Merge (KI-041): "Portal zuruecksetzen" ist die
     * Antwort des Betriebs auf "jemand anderes hat meine Mail". Vorher
     * setzte der Reset nur ein neues Startpasswort - ein ALTER Magic-Link
     * blieb bis zu 90 Tage gueltig, weil `password_changed_at` sich nicht
     * aenderte. Der Reset entwertet jetzt alle vorher ausgestellten Links;
     * der Link der NEUEN Willkommensmail gilt.
     */
    public function test_portal_reset_entwertet_alte_links(): void
    {
        Mail::fake();
        $user = User::factory()->create(['role' => 'customer', 'email' => uniqid().'@kunde.de']);
        $kunde = Customer::create(['user_id' => $user->id, 'customer_number' => 'K-'.uniqid(), 'birth_date' => '1990-01-01']);
        app(PortalAccessService::class)->sendInvitation($kunde);
        $alt = $this->linkAusLetzterWillkommensmail();
        $this->get($alt)->assertRedirect(route('portal.profile'));
        auth()->logout();

        // Bewusst OHNE Zeitreise: Reset und alter Link koennen in dieselbe
        // Sekunde fallen. Der fruehere Sekunden-Vergleich (KI-041) haette
        // den alten Link dann gelten lassen; der Widerrufsstand (KI-043)
        // ist davon unabhaengig.
        app(PortalAccessService::class)->resetPortal($kunde->fresh());
        $neu = $this->linkAusLetzterWillkommensmail();

        $this->get($alt)->assertForbidden();
        $this->assertGuest();
        $this->get($neu)->assertRedirect(route('portal.profile'));
    }

    /** Der Magic-Link, wie ihn die zuletzt verschickte Willkommensmail wirklich enthaelt. */
    private function linkAusLetzterWillkommensmail(): string
    {
        $mails = Mail::sent(CustomerWelcomeMail::class);
        $this->assertNotEmpty($mails, 'Es wurde keine Willkommensmail verschickt.');

        return (string) $mails->last()->magicLoginUrl;
    }
}
