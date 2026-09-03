<?php

namespace Tests\Feature\Security;

use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sicherheits-Regressionstests der Systemeinstellungen (Audit SEC-5).
 *
 * Schwerpunkt ist `legal_external_base`: der Wert landet in
 * LegalPageController::show() in redirect()->away(), also in einem
 * Location-Header, der JEDEN Besucher der oeffentlichen Rechtsseiten
 * weiterschickt. Ohne Pruefung genuegte dort ein javascript:-, data:-
 * oder fremder http-Wert.
 */
class SettingsValidationTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Autorisierung
    // ------------------------------------------------------------------

    public function test_guest_cannot_change_settings(): void
    {
        $this->put(route('admin.settings.update'), ['company_name' => 'Fremd'])
            ->assertRedirect(route('login'));

        $this->assertNotSame('Fremd', SystemSetting::get('company_name'));
    }

    #[DataProvider('nichtAdminRollen')]
    public function test_non_admin_cannot_change_settings(string $rolle): void
    {
        $user = User::factory()->create(['role' => $rolle]);

        $response = $this->actingAs($user)
            ->put(route('admin.settings.update'), ['company_name' => 'Fremd']);

        $this->assertContains($response->getStatusCode(), [403, 302]);
        $this->assertNotSame('Fremd', SystemSetting::get('company_name'));
    }

    public static function nichtAdminRollen(): array
    {
        return [
            'Mitarbeiter' => ['employee'],
            'Support' => ['support'],
            'Manager' => ['manager'],
            'Kunde' => ['customer'],
            'Partner' => ['partner'],
        ];
    }

    // ------------------------------------------------------------------
    // legal_external_base
    // ------------------------------------------------------------------

    #[DataProvider('unzulaessigeQuellen')]
    public function test_invalid_legal_base_is_rejected(string $wert, string $warum): void
    {
        $this->alsAdmin()
            ->put(route('admin.settings.update'), ['legal_external_base' => $wert])
            ->assertSessionHasErrors('legal_external_base');

        $this->assertNotSame($wert, SystemSetting::get('legal_external_base'), $warum);
    }

    public static function unzulaessigeQuellen(): array
    {
        return [
            'javascript:' => ['javascript:alert(document.cookie)', 'Skriptausfuehrung ueber unseren Link'],
            'data:' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==', 'Beliebiger Inhalt unter unserem Link'],
            'http statt https' => ['http://dienstly24.de', 'Weiterleitung ins Unverschluesselte'],
            'fremder Host' => ['https://angreifer.example', 'Open Redirect auf eine fremde Seite'],
            'Zugangsdaten taeuschen den Host vor' => ['https://angreifer.example@dienstly24.de', 'Klassische Host-Taeuschung'],
            'Host im Pfad' => ['https://dienstly24.de.angreifer.example', 'Aehnlich aussehender Fremdhost'],
            'kaputte Adresse' => ['nicht-mal-eine-url', 'Unbrauchbarer Wert'],
            'nur Schraegstriche' => ['//angreifer.example', 'Protokollrelative Weiterleitung'],
            'ftp' => ['ftp://dienstly24.de', 'Falsches Protokoll'],
            'mit Parametern' => ['https://dienstly24.de?weiter=https://angreifer.example', 'Parameter am Ziel'],
        ];
    }

    /** Steuerzeichen wuerden den Location-Header aufspalten. */
    public function test_control_characters_are_rejected(): void
    {
        $this->alsAdmin()->put(route('admin.settings.update'), [
            'legal_external_base' => "https://dienstly24.de\r\nSet-Cookie: a=b",
        ])->assertSessionHasErrors('legal_external_base');
    }

    public function test_excessively_long_value_is_rejected(): void
    {
        $this->alsAdmin()->put(route('admin.settings.update'), [
            'legal_external_base' => 'https://dienstly24.de/' . str_repeat('a', 5000),
        ])->assertSessionHasErrors('legal_external_base');
    }

    public function test_valid_https_source_is_accepted(): void
    {
        $this->alsAdmin()->put(route('admin.settings.update'), [
            'legal_external_base' => 'https://dienstly24.de',
        ])->assertSessionHasNoErrors();

        $this->assertSame('https://dienstly24.de', SystemSetting::get('legal_external_base'));
    }

    /** Leer ist ein gueltiger Zustand: das Portal rendert dann selbst. */
    public function test_empty_source_is_allowed(): void
    {
        $this->alsAdmin()->put(route('admin.settings.update'), [
            'legal_external_base' => '',
        ])->assertSessionHasNoErrors();

        $this->get('/impressum')->assertOk();
    }

    // ------------------------------------------------------------------
    // Die Weiterleitung selbst
    // ------------------------------------------------------------------

    /**
     * Ein Altbestand aus der Zeit VOR der Validierung (oder ein per CLI
     * gesetzter Wert) darf nicht doch noch in einen Redirect geraten.
     *
     */
    #[DataProvider('unzulaessigeQuellen')]
    public function test_stored_invalid_value_never_reaches_a_redirect(string $wert, string $warum): void
    {
        // Am Formular vorbei geschrieben - genau der Fall, den die
        // Pruefung beim LESEN abdeckt.
        SystemSetting::set('legal_external_base', $wert);

        $response = $this->get('/impressum');

        $this->assertSame(200, $response->getStatusCode(),
            'Statt einer Weiterleitung muss die eigene Portalseite kommen.');
        $this->assertFalse($response->headers->has('Location'));
    }

    /** Auch ueber das Suffix darf sich kein fremdes Ziel anhaengen lassen. */
    public function test_suffix_cannot_smuggle_a_target(): void
    {
        SystemSetting::set('legal_external_base', 'https://dienstly24.de');
        SystemSetting::set('legal_external_suffix', '/../../angreifer.example');

        $response = $this->get('/impressum');
        $ziel = (string) $response->headers->get('Location');

        $this->assertStringNotContainsString('angreifer.example', $ziel);
    }

    // ------------------------------------------------------------------
    // Uebrige Einstellungen
    // ------------------------------------------------------------------

    public function test_portal_url_must_be_https(): void
    {
        $this->alsAdmin()->put(route('admin.settings.update'), [
            'portal_url' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('portal_url');
    }

    public function test_company_email_must_be_an_address(): void
    {
        $this->alsAdmin()->put(route('admin.settings.update'), [
            'company_email' => 'keine-adresse',
        ])->assertSessionHasErrors('company_email');
    }

    public function test_reminder_days_only_accepts_numbers(): void
    {
        $this->alsAdmin()->put(route('admin.settings.update'), [
            'contract_reminder_days' => '30,14,<script>',
        ])->assertSessionHasErrors('contract_reminder_days');

        $this->alsAdmin()->put(route('admin.settings.update'), [
            'contract_reminder_days' => '30,14,7',
        ])->assertSessionHasNoErrors();
    }

    public function test_auto_approve_mode_only_accepts_known_values(): void
    {
        $this->alsAdmin()->put(route('admin.settings.update'), [
            'change_request_auto_approve' => 'alles-sofort',
        ])->assertSessionHasErrors('change_request_auto_approve');
    }

    /** Ein Zeilenumbruch im Schluessel war frueher ein Env-Injection-Weg. */
    public function test_api_key_rejects_line_breaks(): void
    {
        $this->alsAdmin()->put(route('admin.settings.update'), [
            'lexoffice_api_key' => "abc\nAPP_DEBUG=true",
        ])->assertSessionHasErrors('lexoffice_api_key');
    }

    public function test_company_name_length_is_capped(): void
    {
        $this->alsAdmin()->put(route('admin.settings.update'), [
            'company_name' => str_repeat('x', 500),
        ])->assertSessionHasErrors('company_name');
    }

    /** Die Host-Liste kommt aus der bestehenden Website-Konfiguration. */
    public function test_allowed_hosts_come_from_the_website_configuration(): void
    {
        $hosts = UpdateSettingsRequest::allowedLegalHosts();

        $this->assertContains(config('website.canonical_host'), $hosts);
        foreach ((array) config('website.redirect_hosts') as $h) {
            $this->assertContains(strtolower($h), $hosts);
        }
    }

    private function alsAdmin(): self
    {
        return $this->actingAs(User::factory()->create(['role' => 'admin']));
    }
}
