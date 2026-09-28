<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Auth\MagicLoginController;
use App\Mail\CustomerWelcomeMail;
use App\Models\Customer;
use App\Models\User;
use App\Services\Portal\PortalAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * KI-043 (Nachpruefung vor dem Merge von PR #358): Zugangslinks ueberlebten
 * Aenderungen an den Anmeldedaten durch die Verwaltung.
 *
 * Der gemessene Fall: Kunde mit Tippfehler in der Adresse angelegt, die
 * Willkommensmail geht an einen Fremden, der Mitarbeiter korrigiert die
 * Adresse und setzt ein neues Passwort - der Magic-Link beim Fremden
 * meldete trotzdem 90 Tage lang an. Die Faelle unten scheitern ohne die
 * Aenderung; die Links stammen jeweils aus der WIRKLICH verschickten Mail.
 */
class ZugangslinkWiderrufTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function kunde(string $email = 'tippfehler@kunde.de'): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => $email]);

        return Customer::create([
            'user_id' => $user->id, 'customer_number' => 'K-'.uniqid(),
            'first_name' => 'Erika', 'last_name' => 'Muster', 'birth_date' => '1990-01-01',
        ]);
    }

    /** Magic-Link der zuletzt an diese Adresse verschickten Willkommensmail. */
    private function letzterLink(string $an): string
    {
        $mail = Mail::sent(CustomerWelcomeMail::class, fn ($m) => $m->hasTo($an))->last();
        $this->assertNotNull($mail, 'Keine Willkommensmail an '.$an);

        return (string) $mail->magicLoginUrl;
    }

    /** Der Link meldet an - Beweis, dass er vor der Aenderung wirklich galt. */
    private function meldetAn(string $link): void
    {
        $this->get($link)->assertRedirect(route('portal.profile'));
        // Frische Sitzung fuer den naechsten Akteur: AuthenticateSession
        // haelt sonst den Passwort-Hash dieses Kunden fest und meldet den
        // naechsten Nutzer derselben Test-Sitzung ab.
        auth()->logout();
        $this->flushSession();
    }

    private function meldetNichtAn(string $link): void
    {
        $this->get($link)->assertForbidden();
        $this->assertGuest();
    }

    private function verwaltungSpeichert(Customer $kunde, array $felder): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->put(route('admin.customer.update', $kunde->id), array_merge([
            'first_name' => 'Erika', 'last_name' => 'Muster',
            'preferred_lang' => 'de', 'customer_type' => 'privat',
            'portal_email' => $kunde->user->email,
        ], $felder))->assertRedirect(route('admin.customer', $kunde->id))->assertSessionHasNoErrors();
        auth()->logout();
        $this->flushSession();
    }

    public function test_neue_login_adresse_entwertet_den_link_an_die_alte(): void
    {
        $kunde = $this->kunde();
        app(PortalAccessService::class)->sendInvitation($kunde);
        $beimFremden = $this->letzterLink('tippfehler@kunde.de');
        $this->meldetAn($beimFremden);

        $this->verwaltungSpeichert($kunde, ['portal_email' => 'richtig@kunde.de']);

        $this->meldetNichtAn($beimFremden);
    }

    public function test_passwort_von_der_verwaltung_entwertet_alte_links(): void
    {
        $kunde = $this->kunde();
        app(PortalAccessService::class)->sendInvitation($kunde);
        $alt = $this->letzterLink('tippfehler@kunde.de');
        $this->meldetAn($alt);

        $this->verwaltungSpeichert($kunde, ['new_password' => 'ein-ganz-neues-langes-passwort-2026']);

        $this->meldetNichtAn($alt);
        $user = $kunde->user->fresh();
        // Der Weg ueber das Modell behaelt die bisherige Bedeutung bei:
        // system-vergeben, Pflichtwechsel beim Login - und
        // `password_changed_at` bleibt dem selbst gewaehlten Passwort
        // vorbehalten.
        $this->assertTrue($user->must_change_password);
        $this->assertNotNull($user->portal_password_set_at);
        $this->assertNull($user->password_changed_at);
        $this->assertTrue(\Hash::check('ein-ganz-neues-langes-passwort-2026', $user->password));
    }

    /**
     * Reihenfolge: erst widerrufen, dann neu ausstellen. Der Link der
     * Einladung an die KORRIGIERTE Adresse gilt; der alte nicht; ein
     * anderes Konto bleibt unberuehrt.
     */
    public function test_neue_einladung_gilt_die_alte_nicht_und_fremde_konten_bleiben_unberuehrt(): void
    {
        $kunde = $this->kunde();
        $anderer = $this->kunde('anderer@kunde.de');
        app(PortalAccessService::class)->sendInvitation($kunde);
        app(PortalAccessService::class)->sendInvitation($anderer);
        $alt = $this->letzterLink('tippfehler@kunde.de');
        $linkDesAnderen = $this->letzterLink('anderer@kunde.de');

        $this->verwaltungSpeichert($kunde, ['portal_email' => 'richtig@kunde.de']);
        app(PortalAccessService::class)->sendInvitation($kunde->fresh());
        $neu = $this->letzterLink('richtig@kunde.de');

        $this->meldetNichtAn($alt);
        $this->meldetAn($neu);
        $this->meldetAn($linkDesAnderen);
    }

    /** "Einladung erneut senden": nur die NEUESTE Mail gilt. */
    public function test_erneute_einladung_entwertet_die_vorige(): void
    {
        $kunde = $this->kunde();
        app(PortalAccessService::class)->sendInvitation($kunde);
        $erste = $this->letzterLink('tippfehler@kunde.de');
        $this->meldetAn($erste);

        app(PortalAccessService::class)->sendInvitation($kunde->fresh());
        $zweite = $this->letzterLink('tippfehler@kunde.de');

        $this->meldetNichtAn($erste);
        $this->meldetAn($zweite);
    }

    /** Jeder Schreibweg der Login-Adresse widerruft - nicht nur der Controller. */
    public function test_jede_adressaenderung_am_modell_widerruft_eine_namensaenderung_nicht(): void
    {
        $user = $this->kunde()->user;
        $vorher = $user->aktuelleZugangslinkVersion();

        $user->update(['name' => 'Erika Neu']);
        $this->assertSame($vorher, $user->aktuelleZugangslinkVersion());

        $user->update(['email' => 'neu@kunde.de']);
        $this->assertSame($vorher + 1, $user->aktuelleZugangslinkVersion());
    }

    /**
     * Der Stand im Link ist signiert: wer `v` auf den aktuellen Stand
     * "hochdreht", zerstoert die Signatur.
     */
    public function test_manipulierter_stand_im_link_ist_ungueltig(): void
    {
        $kunde = $this->kunde();
        app(PortalAccessService::class)->sendInvitation($kunde);
        $alt = $this->letzterLink('tippfehler@kunde.de');
        app(PortalAccessService::class)->sendInvitation($kunde->fresh());
        $aktuell = $kunde->user->aktuelleZugangslinkVersion();

        $gefaelscht = preg_replace('/([?&]v=)\d+/', '${1}'.$aktuell, $alt);
        $this->assertNotSame($alt, $gefaelscht);

        $this->meldetNichtAn($gefaelscht);
    }

    /**
     * Bereits verschickte Links (vor dem Deployment, ohne `v`) gelten
     * weiter - bis zum ersten Widerruf an ihrem Konto.
     */
    public function test_alter_link_ohne_stand_gilt_bis_zum_ersten_widerruf(): void
    {
        $user = $this->kunde()->user;
        $altbestand = URL::temporarySignedRoute('magic.login', now()->addDays(MagicLoginController::GUELTIG_TAGE), ['user' => $user->id]);
        $this->meldetAn($altbestand);

        $user->zugangslinksWiderrufen();

        $this->meldetNichtAn($altbestand);
    }

    /**
     * Ein veraltetes Modell darf den Stand nie zurueckschreiben - sonst
     * kaeme ein bereits widerrufener Link wieder zum Leben.
     */
    public function test_veraltetes_modell_setzt_den_stand_nicht_zurueck(): void
    {
        $user = $this->kunde()->user;
        $veraltet = User::find($user->id);

        $user->zugangslinksWiderrufen();
        $user->zugangslinksWiderrufen();
        $veraltet->zugangslinksWiderrufen();

        $this->assertSame(3, $user->aktuelleZugangslinkVersion());
        $this->assertSame(3, $veraltet->zugangslink_version);
    }
}
