<?php

namespace Tests\Feature;

use App\Http\Controllers\SupportFormController;
use App\Mail\ContractSwitchMail;
use App\Mail\CustomerMessageMail;
use App\Mail\CustomerWelcomeMail;
use App\Mail\DocumentRequestMail;
use App\Mail\PasswordResetMail;
use App\Mail\TicketReplyMail;
use App\Models\Channel;
use App\Models\Contract;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\DocumentRequest;
use App\Models\SystemSetting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ContractSwitchReminderService;
use App\Services\Portal\PortalAccessService;
use App\Support\ChatReferenz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Kundenportal-Sperre (CUSTOMER_PORTAL_ENABLED=false, Betreiber-Vorgabe
 * 20.11.2026, keine Erlaubnis nach Paragraph 34d GewO).
 *
 * Geprueft wird aus der Rolle dessen, der hinein will: Gast, Kunde mit
 * offener Sitzung, Kunde mit altem Link, Kunde auf dem Admin-Host - und
 * dass Personal, Chat und Erinnerungsmails weiter funktionieren. Der
 * letzte Block prueft, dass der Schalter AN das bisherige Verhalten
 * wiederherstellt. Die uebrige Testsuite laeuft mit Schalter AN
 * (phpunit.xml) und belegt damit "unveraendert".
 */
class KundenportalSperreTest extends TestCase
{
    use RefreshDatabase;

    private string $logDatei;

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.enabled' => false]);
        SystemSetting::set('two_factor_required', '0');
        SystemSetting::set('portal_url', 'https://portal.dienstly24.test');

        $this->logDatei = storage_path('framework/testing/portal-sperre-'.uniqid().'.log');
        config(['logging.channels.portal_sperre' => [
            'driver' => 'single', 'path' => $this->logDatei, 'level' => 'info',
        ]]);
    }

    protected function tearDown(): void
    {
        @unlink($this->logDatei);
        parent::tearDown();
    }

    private function kunde(array $user = [], array $kunde = []): Customer
    {
        $u = User::factory()->create(array_merge([
            'role' => 'customer', 'name' => 'Erika Musterfrau', 'email' => 'erika@kunde.de',
            'password' => Hash::make('ein-sehr-langes-passwort-1'),
        ], $user));

        return Customer::create(array_merge([
            'user_id' => $u->id, 'customer_number' => '2600123', 'birth_date' => '1985-03-15',
            'phone' => '+49 171 1234567', 'preferred_lang' => 'de',
        ], $kunde));
    }

    private function log(): string
    {
        return is_file($this->logDatei) ? (string) file_get_contents($this->logDatei) : '';
    }

    // ---------------- Seiten und API ----------------

    public function test_portalseite_zeigt_wartungsseite_mit_503(): void
    {
        $antwort = $this->get('/portal/contracts')->assertStatus(503);

        $antwort->assertSee('Unser Kundenportal ist vorübergehend nicht verfügbar.');
        $antwort->assertSee('Sie erreichen uns weiterhin direkt:');
        $antwort->assertSee('Im Chat schreiben');
        $antwort->assertSee('Per WhatsApp schreiben');
        $antwort->assertSee('https://wa.me/491799673909', false);
        $antwort->assertSee('Impressum');
        $antwort->assertSee('Datenschutz');
        $this->assertStringContainsString('no-store', (string) $antwort->headers->get('Cache-Control'));
    }

    public function test_api_anfrage_bekommt_503_json(): void
    {
        $this->getJson('/portal/nachrichten/feed')
            ->assertStatus(503)
            ->assertJson(['ok' => false, 'fehler' => 'kundenportal_nicht_verfuegbar']);
    }

    public function test_alle_portalrouten_sind_gesperrt(): void
    {
        $kunde = $this->kunde();
        $routen = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'portal'));
        $this->assertGreaterThan(30, $routen->count());

        foreach ($routen as $route) {
            $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $methode) {
                $this->actingAs($kunde->user);
                $antwort = $this->call($methode, $uri);
                $this->assertSame(503, $antwort->getStatusCode(), "$methode $uri ist nicht gesperrt");
                $this->assertGuest();
            }
        }
    }

    public function test_bestehende_kundensitzung_wird_beendet_auch_ausserhalb_des_portals(): void
    {
        $kunde = $this->kunde();
        $this->actingAs($kunde->user)->get('/chat')->assertOk();
        $this->assertGuest();
        $this->assertStringContainsString('kundensitzung_beendet', $this->log());
    }

    public function test_registrierung_und_magic_link_sind_gesperrt(): void
    {
        $kunde = $this->kunde();
        $this->get('/register')->assertStatus(503);
        $this->post('/register', ['email' => 'neu@kunde.de'])->assertStatus(503);

        $link = URL::temporarySignedRoute('magic.login', now()->addDay(), ['user' => $kunde->user_id]);
        $this->get($link)->assertStatus(503);
        $this->assertGuest();
    }

    // ---------------- Anmeldung ----------------

    public function test_anmeldung_auf_dem_portal_host_ist_gesperrt(): void
    {
        $this->get('https://portal.dienstly24.test/login')->assertStatus(503);
        $this->get('https://portal.dienstly24.test/')->assertStatus(503);
        $this->get('https://portal.dienstly24.test/forgot-password')->assertStatus(503);
    }

    public function test_kunde_kann_sich_auch_auf_dem_admin_host_nicht_anmelden(): void
    {
        $kunde = $this->kunde();

        $this->post('/login', ['email' => 'erika@kunde.de', 'password' => 'ein-sehr-langes-passwort-1'])
            ->assertStatus(503);
        $this->assertGuest();
        $this->assertStringContainsString('kundenanmeldung_verhindert', $this->log());
        $this->assertNotNull($kunde->fresh()->user, 'Konto bleibt bestehen');
    }

    public function test_personal_kann_sich_weiter_anmelden_und_arbeiten(): void
    {
        User::factory()->create(['role' => 'admin', 'email' => 'chef@dienstly24.de',
            'password' => Hash::make('ein-sehr-langes-passwort-1')]);

        $this->get('/login')->assertOk();
        $this->post('/login', ['email' => 'chef@dienstly24.de', 'password' => 'ein-sehr-langes-passwort-1'])
            ->assertRedirect(route('admin.dashboard', absolute: false));
        $this->assertAuthenticated();
        $this->get('/admin/customers')->assertOk();
    }

    public function test_gast_auf_dem_admin_host_landet_bei_der_anmeldung(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    // ---------------- Passwort-Wege ----------------

    public function test_passwort_vergessen_schickt_kunden_keinen_link_personal_schon(): void
    {
        Mail::fake();
        $this->kunde();
        User::factory()->create(['role' => 'employee', 'email' => 'team@dienstly24.de']);

        $this->post('/forgot-password', ['identifier' => 'erika@kunde.de'])
            ->assertRedirect(route('password.request.sent'));
        Mail::assertNotSent(PasswordResetMail::class);

        $this->post('/forgot-password', ['identifier' => 'team@dienstly24.de']);
        Mail::assertSent(PasswordResetMail::class, 1);
    }

    public function test_reset_link_und_einladungslink_eines_kunden_sind_gesperrt(): void
    {
        $kunde = $this->kunde();
        $token = Password::broker()->createToken($kunde->user);

        $this->get('/reset-password/'.$token.'?email=erika@kunde.de')->assertStatus(503);
        $this->post('/reset-password', [
            'token' => $token, 'email' => 'erika@kunde.de',
            'password' => 'neues-langes-passwort-9', 'password_confirmation' => 'neues-langes-passwort-9',
        ])->assertStatus(503);
        $this->assertTrue(Hash::check('ein-sehr-langes-passwort-1', $kunde->user->fresh()->password));

        $einladung = URL::temporarySignedRoute('password.setup', now()->addDay(), ['user' => $kunde->user_id], absolute: false);
        $this->get($einladung)->assertStatus(503);
    }

    public function test_reset_link_fuer_personal_funktioniert_weiter(): void
    {
        $mitarbeiter = User::factory()->create(['role' => 'employee', 'email' => 'team@dienstly24.de']);
        $token = Password::broker()->createToken($mitarbeiter);

        $this->get('/reset-password/'.$token.'?email=team@dienstly24.de')->assertOk();
    }

    public function test_keine_einladungen_und_keine_portal_erinnerung(): void
    {
        Mail::fake();
        $kunde = $this->kunde();
        $dienst = app(PortalAccessService::class);

        $this->assertFalse($dienst->autoInvite($kunde));
        try {
            $dienst->sendInvitation($kunde);
            $this->fail('Einladung haette abgelehnt werden muessen');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('gesperrt', $e->getMessage());
        }
        SystemSetting::set('portal_invite_auto_enabled', '1');
        $this->artisan('portal:send-invitations')->assertSuccessful();

        Mail::assertNotSent(CustomerWelcomeMail::class);
        Mail::assertNotSent(PasswordResetMail::class);
    }

    // ---------------- Protokoll ----------------

    public function test_protokoll_ist_minimal(): void
    {
        $this->get('/portal/contracts?geheim=abc')->assertStatus(503);

        $log = $this->log();
        $this->assertStringContainsString('"grund":"portal"', $log);
        $this->assertStringContainsString('/portal/contracts', $log);
        $this->assertStringNotContainsString('geheim', $log);
        $this->assertStringNotContainsString('127.0.0.1', $log);
    }

    // ---------------- Gast-Chat ----------------

    public function test_gast_chat_funktioniert_ohne_anmeldung(): void
    {
        $this->get('/chat')->assertOk()->assertSee('Wir sind kein Versicherungsvermittler', false);

        $this->postJson('/chat', ['nachricht' => 'Hallo'])->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'kontakt', 'einwilligung']);

        $this->postJson('/chat', [
            'name' => 'Max Gast', 'kontakt' => 'max@gast.de', 'nachricht' => 'Ich habe eine Frage.', 'einwilligung' => '1',
        ])->assertOk()->assertJson(['ok' => true]);

        $unterhaltung = Conversation::sole();
        $this->assertNull($unterhaltung->customer_id);
        $this->assertSame(Channel::idFor(Channel::WEBCHAT), $unterhaltung->channel_id);
        $notiz = ConversationNote::sole();
        $this->assertStringContainsString('Max Gast', $notiz->body);
        $this->assertStringContainsString('UNGEPRUEFT', $notiz->body);

        // Folgenachricht ohne erneute Angaben, Antwort des Teams erscheint.
        $this->postJson('/chat', ['nachricht' => 'Noch etwas.'])->assertOk();
        CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'channel_id' => $unterhaltung->channel_id,
            'direction' => CustomerMessage::DIRECTION_OUTGOING, 'body' => 'Antwort vom Team', 'from_staff' => true,
        ]);

        $verlauf = $this->getJson('/chat/verlauf')->assertOk()->json('nachrichten');
        $this->assertSame(['sie', 'sie', 'team'], array_column($verlauf, 'von'));
        $this->assertSame('Antwort vom Team', $verlauf[2]['text']);
        $this->assertSame(1, Conversation::count());
    }

    public function test_eine_getippte_kundennummer_ordnet_nicht_zu(): void
    {
        $this->kunde();
        $this->postJson('/chat', [
            'name' => 'Fremder', 'kontakt' => '+49 171 1234567', 'nachricht' => 'Was steht in meinem Vertrag?', 'einwilligung' => '1',
        ])->assertOk();

        $this->assertNull(Conversation::sole()->customer_id);
    }

    public function test_ref_link_erzeugt_nur_eine_interne_notiz(): void
    {
        $kunde = $this->kunde();
        $vertrag = Contract::create(['customer_id' => $kunde->id, 'type' => 'kfz', 'insurer' => 'WGV',
            'contract_number' => 'KFZ-4711', 'status' => 'active']);
        $ref = ChatReferenz::erzeugen($kunde, $vertrag);

        $seite = $this->get('/chat?ref='.urlencode($ref))->assertOk();
        $seite->assertDontSee('Erika');
        $seite->assertDontSee('2600123');
        $seite->assertDontSee('KFZ-4711');
        $seite->assertDontSee('WGV');
        $this->assertGuest();

        $this->postJson('/chat', ['name' => 'Erika', 'kontakt' => 'erika@kunde.de', 'nachricht' => 'Frage', 'einwilligung' => '1'])->assertOk();

        $this->assertNull(Conversation::sole()->customer_id, 'nie automatisch zuordnen');
        $notiz = ConversationNote::sole()->body;
        $this->assertStringContainsString('2600123', $notiz);
        $this->assertStringContainsString('KFZ-4711', $notiz);
        $this->assertStringContainsString('NICHT automatisch zugeordnet', $notiz);

        $verlauf = json_encode($this->getJson('/chat/verlauf')->json());
        $this->assertStringNotContainsString('2600123', $verlauf);
        $this->assertStringNotContainsString('KFZ-4711', $verlauf);
    }

    public function test_abgelaufener_oder_fremder_ref_wird_ignoriert(): void
    {
        $kunde = $this->kunde();
        $abgelaufen = ChatReferenz::erzeugen($kunde, null, 1);
        $this->travel(2)->days();
        $this->assertNull(ChatReferenz::pruefen($abgelaufen));
        $this->assertNull(ChatReferenz::pruefen(SupportFormController::tokenFor($kunde)));
        $this->assertNull(ChatReferenz::pruefen('kaputt'));

        $this->get('/chat?ref='.urlencode($abgelaufen))->assertOk();
        $this->postJson('/chat', ['name' => 'X', 'kontakt' => 'x@y.de', 'nachricht' => 'Frage', 'einwilligung' => '1'])->assertOk();
        $this->assertStringNotContainsString('signierten Link', ConversationNote::sole()->body);
    }

    public function test_chat_ist_je_sitzung_gedrosselt(): void
    {
        $this->postJson('/chat', ['name' => 'X', 'kontakt' => 'x@y.de', 'nachricht' => 'eins', 'einwilligung' => '1'])->assertOk();
        // Die Routen-Drossel je IP (8/min) wuerde zuerst greifen - fuer den
        // Sitzungs-Limiter wird sie hier umgangen.
        $this->withoutMiddleware(ThrottleRequests::class);
        for ($i = 2; $i <= 20; $i++) {
            $this->postJson('/chat', ['nachricht' => 'n'.$i])->assertOk();
        }
        $this->postJson('/chat', ['nachricht' => 'zu viel'])->assertStatus(429);
    }

    public function test_chat_hat_eine_drossel_je_ip(): void
    {
        $route = app('router')->getRoutes()->getByName('chat.send');
        $this->assertContains('throttle:8,1', $route->gatherMiddleware());
    }

    // ---------------- E-Mails ohne Portal-Link ----------------

    private function ohnePortalLink(string $html): void
    {
        // Kein Pfad in den Portalbereich (der Chat darf auf dem Portal-HOST
        // liegen - /chat ist dort kein Portalbereich).
        $this->assertDoesNotMatchRegularExpression('~https?://[^/"\s]+/(portal|login|register|magic-login|reset-password|zugang)~', $html);
        $this->assertStringNotContainsString('Kundenportal', $html);
    }

    public function test_antwort_mail_zum_vorgang_enthaelt_text_und_chat_statt_portal(): void
    {
        $kunde = $this->kunde();
        $ticket = Ticket::create(['customer_id' => $kunde->id, 'type' => 'other', 'status' => 'open',
            'subject' => 'Frage', 'description' => 'x', 'priority' => 'mittel']);

        $html = (new TicketReplyMail($ticket, 'Hier ist unsere Antwort.'))->render();

        $this->ohnePortalLink($html);
        $this->assertStringContainsString('Hier ist unsere Antwort.', $html);
        $this->assertStringContainsString('https://portal.dienstly24.test/chat?ref=', $html);
        $this->assertStringContainsString('https://wa.me/491799673909', $html);
    }

    public function test_dokumentanforderung_und_nachricht_ohne_portal_link(): void
    {
        $kunde = $this->kunde();
        $anforderung = DocumentRequest::create(['customer_id' => $kunde->id, 'title' => 'Ausweis', 'status' => 'open']);
        $html = (new DocumentRequestMail($anforderung))->render();
        $this->ohnePortalLink($html);
        $this->assertStringContainsString('/chat?ref=', $html);

        $nachricht = CustomerMessage::create(['customer_id' => $kunde->id, 'body' => 'Bitte melden Sie sich.', 'from_staff' => true]);
        $mail = new CustomerMessageMail($nachricht, 'hint');
        $html = $mail->render();
        $this->ohnePortalLink($html);
        $this->assertSame('full', $mail->mode, 'der Text steht in der Mail');
        $this->assertStringContainsString('Bitte melden Sie sich.', $html);
    }

    public function test_wechsel_erinnerung_geht_weiter_raus_ohne_portal_link(): void
    {
        Mail::fake();
        $kunde = $this->kunde([], ['marketing_consent' => true]);
        Contract::create(['customer_id' => $kunde->id, 'type' => 'kfz', 'insurer' => 'WGV',
            'status' => 'active', 'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->addDays(50)->toDateString()]);

        $this->assertSame(1, app(ContractSwitchReminderService::class)->run());

        Mail::assertQueued(ContractSwitchMail::class, function (ContractSwitchMail $m) {
            $html = $m->render();
            $this->assertDoesNotMatchRegularExpression('~https?://[^/"\s]+/(portal|login)~', $html);

            return true;
        });
    }

    public function test_website_kopfzeile_zeigt_chat_statt_login(): void
    {
        $html = $this->get('/website')->assertOk()->getContent();
        $this->assertStringNotContainsString('portal.dienstly24.de/login', $html);
        $this->assertStringContainsString('href="/chat"', $html);
    }

    // ---------------- Schalter AN: alles wie vorher ----------------

    public function test_schalter_an_stellt_das_bisherige_verhalten_her(): void
    {
        config(['portal.enabled' => true]);
        $kunde = $this->kunde();

        $this->get('/portal/contracts')->assertRedirect(route('login'));
        $this->post('/login', ['email' => 'erika@kunde.de', 'password' => 'ein-sehr-langes-passwort-1'])
            ->assertRedirect('/portal/contracts'); // "intended" wie bisher
        $this->assertAuthenticatedAs($kunde->user);
        $this->get('/portal/contracts')->assertOk();

        $ticket = Ticket::create(['customer_id' => $kunde->id, 'type' => 'other', 'status' => 'open',
            'subject' => 'Frage', 'description' => 'x', 'priority' => 'mittel']);
        $this->assertStringContainsString('/portal/tickets/', (new TicketReplyMail($ticket, 'x'))->render());
        $this->assertSame('', $this->log());
    }
}
