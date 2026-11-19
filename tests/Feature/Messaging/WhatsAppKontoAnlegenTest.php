<?php

namespace Tests\Feature\Messaging;

use App\Models\ActivityLog;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\CustomerMessage;
use App\Models\User;
use App\Support\ChannelConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `whatsapp:add-account`: Nummer ohne Embedded Signup anbinden
 * (Betreiber-Auftrag 19.11.2026, Testnummer fuer das App Review).
 */
class WhatsAppKontoAnlegenTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_ID = '1468612986331762';

    private const WABA_ID = '1756427188751872';

    private const TOKEN = 'EAAG-geheimes-test-token';

    private const SECRET = 'app-secret-aus-env';

    private const ABSENDER = '491701234567';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.meta.app_secret' => self::SECRET,
            'services.meta.webhook_verify_token' => 'VERIFY-ENV',
        ]);
        Channel::where('key', 'whatsapp')->update(['is_active' => false]);
    }

    private function metaAntwortet(bool $aboOk = true, int $nummerStatus = 200): void
    {
        Http::fake([
            'graph.facebook.com/*/'.self::WABA_ID.'/subscribed_apps' => $aboOk
                ? Http::response(['success' => true], 200)
                : Http::response(['error' => ['message' => '(#200) Permissions error', 'code' => 200]], 403),
            'graph.facebook.com/*/'.self::PHONE_ID.'/messages' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
            'graph.facebook.com/*/'.self::PHONE_ID.'*' => $nummerStatus === 200
                ? Http::response(['display_phone_number' => '+1 555-192-2932', 'verified_name' => 'Test Number', 'id' => self::PHONE_ID], 200)
                : Http::response(['error' => ['message' => 'Invalid OAuth access token']], $nummerStatus),
        ]);
    }

    private function ausfuehren()
    {
        return $this->artisan('whatsapp:add-account', [
            '--phone-number-id' => self::PHONE_ID,
            '--waba-id' => self::WABA_ID,
        ])->expectsQuestion('Access Token einfuegen (Eingabe bleibt unsichtbar)', self::TOKEN);
    }

    public function test_legt_aktives_konto_an_und_abonniert_den_waba(): void
    {
        $this->metaAntwortet();

        $this->ausfuehren()
            ->expectsOutputToContain('+1 555-192-2932')
            ->expectsOutputToContain('subscribed_apps -> HTTP 200')
            ->assertExitCode(0);

        $konto = ChannelAccount::where('external_account_id', self::PHONE_ID)->firstOrFail();
        $this->assertTrue($konto->is_active);
        $this->assertSame(self::TOKEN, $konto->credential('access_token'));
        $this->assertSame(self::PHONE_ID, $konto->credential('phone_number_id'));
        $this->assertSame(self::SECRET, $konto->credential('app_secret'));
        $this->assertSame('VERIFY-ENV', $konto->credential('verify_token'));
        $this->assertSame(self::WABA_ID, $konto->waba_id);
        $this->assertSame(ChannelConnection::CONNECTED, $konto->connection_status);
        $this->assertSame('+1 555-192-2932', $konto->name);

        // Ohne aktiven Kanal ginge keine Antwort aus dem Postfach raus.
        $this->assertTrue((bool) Channel::where('key', 'whatsapp')->value('is_active'));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), self::WABA_ID.'/subscribed_apps')
            && $r->method() === 'POST'
            && $r->hasHeader('Authorization', 'Bearer '.self::TOKEN));
    }

    public function test_token_steht_nirgends_im_klartext(): void
    {
        $this->metaAntwortet();
        $this->ausfuehren()->doesntExpectOutputToContain(self::TOKEN)->assertExitCode(0);

        $roh = (string) DB::table('channel_accounts')->value('credentials');
        $this->assertStringNotContainsString(self::TOKEN, $roh);

        $log = ActivityLog::where('action', 'whatsapp_account_manual')->firstOrFail();
        $this->assertStringNotContainsString(self::TOKEN, json_encode($log->getAttributes()));
        $this->assertStringNotContainsString(self::SECRET, json_encode($log->getAttributes()));
    }

    public function test_untaugliches_token_legt_nichts_an(): void
    {
        $this->metaAntwortet(nummerStatus: 401);

        $this->ausfuehren()->assertExitCode(1);

        $this->assertSame(0, ChannelAccount::count());
        $this->assertFalse((bool) Channel::where('key', 'whatsapp')->value('is_active'));
    }

    public function test_fehlgeschlagenes_abo_wird_gemeldet(): void
    {
        $this->metaAntwortet(aboOk: false);

        $this->ausfuehren()
            ->expectsOutputToContain('HTTP 403')
            ->expectsOutputToContain('Permissions error')
            ->assertExitCode(1);

        $konto = ChannelAccount::firstOrFail();
        $this->assertSame(ChannelConnection::WEBHOOK_ERROR, $konto->connection_status);
    }

    public function test_ohne_app_secret_wird_abgebrochen(): void
    {
        config(['services.meta.app_secret' => '']);

        $this->artisan('whatsapp:add-account', ['--phone-number-id' => self::PHONE_ID, '--waba-id' => self::WABA_ID])
            ->assertExitCode(1);

        $this->assertSame(0, ChannelAccount::count());
    }

    public function test_zweiter_lauf_aktualisiert_statt_zu_verdoppeln(): void
    {
        $this->metaAntwortet();
        $this->ausfuehren()->assertExitCode(0);
        $this->ausfuehren()->assertExitCode(0);

        $this->assertSame(1, ChannelAccount::count());
    }

    /**
     * Die ganze Kette auf dem angelegten Konto: Eingang -> Unterhaltung
     * im Postfach -> Antwort ueber die Graph API an den Absender.
     */
    public function test_eingang_und_antwort_laufen_ueber_das_neue_konto(): void
    {
        $this->metaAntwortet();
        $this->ausfuehren()->assertExitCode(0);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => self::WABA_ID, 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['phone_number_id' => self::PHONE_ID, 'display_phone_number' => '15551922932'],
                'contacts' => [['wa_id' => self::ABSENDER, 'profile' => ['name' => 'Reviewer']]],
                'messages' => [[
                    'from' => self::ABSENDER, 'id' => 'wamid.IN1', 'timestamp' => (string) time(),
                    'type' => 'text', 'text' => ['body' => 'Hallo Dienstly24'],
                ]],
            ]]]]],
        ];
        $roh = (string) json_encode($payload);

        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $roh, self::SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $roh)->assertOk();

        $eingang = CustomerMessage::where('body', 'Hallo Dienstly24')->firstOrFail();
        $unterhaltung = Conversation::findOrFail($eingang->conversation_id);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.postfach'))->assertSee('Hallo Dienstly24');

        $this->actingAs($admin)
            ->post(route('admin.postfach.reply', $unterhaltung->id), ['body' => 'Guten Tag, wie koennen wir helfen?'])
            ->assertRedirect();

        Http::assertSent(fn ($r) => str_contains($r->url(), self::PHONE_ID.'/messages')
            && $r['to'] === self::ABSENDER
            && $r['text']['body'] === 'Guten Tag, wie koennen wir helfen?'
            && $r->hasHeader('Authorization', 'Bearer '.self::TOKEN));
    }
}
