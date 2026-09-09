<?php

namespace Tests\Feature\Messaging;

use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\CustomerMessage;
use App\Models\User;
use App\Services\Messaging\Channels\Onboarding\EmbeddedSignupService;
use App\Support\ChannelConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Die Anbindung einer WhatsApp-Nummer ueber den OFFIZIELLEN Weg
 * (Auftrag Teil B, Abschnitte 17-23 und 35).
 *
 * Der Massstab dieser Datei ist nicht "es klappt", sondern: das Secret
 * bleibt auf dem Server, ein Token erreicht den Browser nie, und
 * "Cloud API verbunden" wird NIE zu "Coexistence verbunden".
 */
class WhatsAppOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function konfiguriert(): void
    {
        config([
            'services.meta.app_id' => '123456',
            'services.meta.app_secret' => 'SECRET-NIE-NACH-AUSSEN',
            'services.meta.es_config_id' => 'CFG-1',
            'services.meta.graph_version' => 'v23.0',
        ]);
    }

    private function metaAntwortet(): void
    {
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'TOKEN-NEU'], 200),
            '*/subscribed_apps' => Http::response(['success' => true], 200),
            // Die Nummer selbst - der Beweis, dass das Token traegt.
            '*' => Http::response(['display_phone_number' => '+49 170 9999999'], 200),
        ]);
    }

    private function anbinden(bool $coexistence = false): TestResponse
    {
        return $this->actingAs($this->admin())->post(route('admin.channels.whatsapp.complete'), [
            'code' => 'CODE-AUS-DEM-FENSTER',
            'waba_id' => 'WABA-1',
            'phone_number_id' => '111222333',
            'coexistence' => $coexistence ? '1' : '0',
        ]);
    }

    /** Fall 1: Der Code wird auf dem SERVER getauscht - mit dem Secret. */
    public function test_der_code_wird_serverseitig_gegen_ein_token_getauscht(): void
    {
        $this->konfiguriert();
        $this->metaAntwortet();

        $this->anbinden()->assertRedirect();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/oauth/access_token')
            && str_contains($r->url(), 'client_secret=SECRET-NIE-NACH-AUSSEN')
            && str_contains($r->url(), 'code=CODE-AUS-DEM-FENSTER')
        );

        $konto = ChannelAccount::firstOrFail();
        $this->assertSame('TOKEN-NEU', $konto->credential('access_token'));
    }

    /**
     * Fall 2: DAS SECRET ERREICHT DEN BROWSER NIE. Im Fenster stehen nur
     * App-Kennung und Konfigurations-Kennung - die sind oeffentlich.
     */
    public function test_das_app_secret_steht_nie_im_html(): void
    {
        $this->konfiguriert();

        $seite = $this->actingAs($this->admin())->get(route('admin.channels.index'));

        $seite->assertOk()
            ->assertDontSee('SECRET-NIE-NACH-AUSSEN')
            ->assertSee('123456', false)      // App-Kennung: oeffentlich
            ->assertSee('CFG-1', false);      // Konfiguration: oeffentlich
    }

    /** Fall 3: Auch das erhaltene Token steht nirgends im HTML. */
    public function test_das_token_steht_nie_im_html(): void
    {
        $this->konfiguriert();
        $this->metaAntwortet();
        $this->anbinden();

        $this->actingAs($this->admin())->get(route('admin.channels.index'))
            ->assertOk()->assertDontSee('TOKEN-NEU');
    }

    /**
     * Fall 4: DER KERN DES AUFTRAGS (35). Der gewoehnliche Weg ergibt
     * "Cloud API" - NIE "Coexistence".
     */
    public function test_cloud_api_ist_nicht_coexistence(): void
    {
        $this->konfiguriert();
        $this->metaAntwortet();

        $this->anbinden(coexistence: false);

        $konto = ChannelAccount::firstOrFail();
        $this->assertSame(ChannelConnection::TYPE_CLOUD_API, $konto->connection_type);
        $this->assertSame(ChannelConnection::CONNECTED, $konto->connection_status);
        $this->assertFalse($konto->isCoexistence());
        $this->assertStringContainsString('Cloud API', $konto->connectionLabel());
        $this->assertStringNotContainsString('Coexistence', $konto->connectionLabel());
    }

    /** Fall 5: Nur der ausdrueckliche Business-App-Weg ergibt Coexistence. */
    public function test_nur_der_business_app_weg_ergibt_coexistence(): void
    {
        $this->konfiguriert();
        $this->metaAntwortet();

        $this->anbinden(coexistence: true);

        $konto = ChannelAccount::firstOrFail();
        $this->assertSame(ChannelConnection::TYPE_COEXISTENCE, $konto->connection_type);
        $this->assertTrue($konto->isCoexistence());
    }

    /**
     * Fall 6: Ein gruener Verbindungstest macht aus einem Cloud-API-Konto
     * NIE ein Coexistence-Konto. Dass die API antwortet, sagt ueber die
     * zweite Freigabe von Meta gar nichts.
     */
    public function test_ein_gruener_test_macht_kein_coexistence_daraus(): void
    {
        Http::fake(['*' => Http::response(['display_phone_number' => '+49 170 1'], 200)]);
        $konto = ChannelAccount::create([
            'channel_id' => Channel::where('key', 'whatsapp')->firstOrFail()->id,
            'name' => 'Nummer', 'is_active' => true,
            'connection_type' => ChannelConnection::TYPE_CLOUD_API,
            'credentials' => ['access_token' => 'T', 'phone_number_id' => '111'],
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.channels.accounts.test', $konto->id))->assertRedirect();

        $frisch = $konto->fresh();
        $this->assertSame(ChannelConnection::TYPE_CLOUD_API, $frisch->connection_type);
        $this->assertFalse($frisch->isCoexistence());
    }

    /** Fall 7: Ohne Webhook-Abonnement ist die Anbindung NICHT verbunden. */
    public function test_ohne_webhook_abonnement_gilt_die_anbindung_nicht_als_verbunden(): void
    {
        $this->konfiguriert();
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'TOKEN-NEU'], 200),
            '*/subscribed_apps' => Http::response(['error' => ['message' => 'nope']], 400),
            '*' => Http::response(['display_phone_number' => '+49 170 9999999'], 200),
        ]);

        $this->anbinden();

        $konto = ChannelAccount::firstOrFail();
        $this->assertSame(ChannelConnection::WEBHOOK_ERROR, $konto->connection_status);
        // Und der Grund ist auf DEUTSCH und ohne fremden Wortlaut.
        $this->assertStringNotContainsString('nope', (string) $konto->connection_error);
    }

    /** Fall 8: Meta lehnt den Code ab -> kein halbfertiges Konto. */
    public function test_ein_abgelehnter_code_legt_kein_konto_an(): void
    {
        $this->konfiguriert();
        Http::fake(['*/oauth/access_token*' => Http::response(['error' => ['message' => 'bad code']], 400)]);

        $this->anbinden()->assertSessionHas('error');

        $this->assertSame(0, ChannelAccount::count());
    }

    /** Fall 9: Ohne Server-Konfiguration wird der Weg gar nicht angeboten. */
    public function test_ohne_konfiguration_wird_der_weg_nicht_angeboten(): void
    {
        config([
            'services.meta.app_id' => null,
            'services.meta.app_secret' => null,
            'services.meta.es_config_id' => null,
        ]);

        $this->assertFalse(app(EmbeddedSignupService::class)->isConfigured());

        $this->actingAs($this->admin())->get(route('admin.channels.index'))
            ->assertOk()
            ->assertSee('noch nicht eingerichtet');
    }

    /** Fall 10: Nur ein Admin darf anbinden. */
    public function test_nur_admin_darf_anbinden(): void
    {
        $this->konfiguriert();
        Http::fake();

        $this->actingAs(User::factory()->create(['role' => 'employee']))
            ->post(route('admin.channels.whatsapp.complete'), [
                'code' => 'C', 'waba_id' => 'W', 'phone_number_id' => 'P',
            ]);

        $this->assertSame(0, ChannelAccount::count());
        Http::assertNothingSent();
    }

    /**
     * Fall 11: Eine erneute Anbindung ERGAENZT das Konto, sie legt kein
     * zweites an und wirft die bereits gepflegten Werte nicht weg.
     */
    public function test_erneute_anbindung_ergaenzt_das_konto(): void
    {
        $this->konfiguriert();
        $this->metaAntwortet();

        $konto = ChannelAccount::create([
            'channel_id' => Channel::where('key', 'whatsapp')->firstOrFail()->id,
            'name' => 'Alt', 'external_account_id' => '111222333', 'is_active' => true,
            'credentials' => ['access_token' => 'ALT', 'app_secret' => 'GEPFLEGT'],
        ]);

        $this->anbinden();

        $this->assertSame(1, ChannelAccount::count());
        $frisch = $konto->fresh();
        $this->assertSame('TOKEN-NEU', $frisch->credential('access_token'));
        $this->assertSame('WABA-1', $frisch->waba_id);
    }

    /**
     * Fall 12: Das Trennen setzt den Zustand - und laesst Unterhaltungen
     * und Nachrichten unangetastet.
     */
    public function test_trennen_setzt_den_zustand_und_loescht_nichts(): void
    {
        $konto = ChannelAccount::create([
            'channel_id' => Channel::where('key', 'whatsapp')->firstOrFail()->id,
            'name' => 'Nummer', 'is_active' => true,
            'connection_status' => ChannelConnection::CONNECTED,
            'credentials' => ['access_token' => 'T'],
        ]);
        $unterhaltung = Conversation::create([
            'channel_id' => $konto->channel_id, 'channel_account_id' => $konto->id,
            'external_user_id' => '4917012345', 'status' => 'open',
        ]);
        CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'body' => 'Bleibt', 'from_staff' => false,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.channels.accounts.disconnect', $konto->id))->assertRedirect();

        $this->assertSame(ChannelConnection::DISCONNECTED, $konto->fresh()->connection_status);
        $this->assertSame(1, CustomerMessage::count());
    }

    /**
     * Fall 13: Die Anbindungsart laesst sich von Hand richtigstellen -
     * fuer Nummern, die den Weg nie durchlaufen haben.
     */
    public function test_anbindungsart_ist_von_hand_setzbar(): void
    {
        $konto = ChannelAccount::create([
            'channel_id' => Channel::where('key', 'whatsapp')->firstOrFail()->id,
            'name' => 'Bestandsnummer', 'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.channels.accounts.connection_type', $konto->id), [
                'connection_type' => ChannelConnection::TYPE_COEXISTENCE,
            ])->assertRedirect();

        $this->assertSame(ChannelConnection::TYPE_COEXISTENCE, $konto->fresh()->connection_type);
    }

    /**
     * Fall 14: Der Zustand allein macht noch keine Coexistence. Erst
     * BEIDES zusammen - Art und stehende Verbindung - zaehlt.
     */
    public function test_coexistence_verlangt_art_und_verbindung(): void
    {
        $konto = ChannelAccount::create([
            'channel_id' => Channel::where('key', 'whatsapp')->firstOrFail()->id,
            'name' => 'Nummer',
            'connection_type' => ChannelConnection::TYPE_COEXISTENCE,
            'connection_status' => ChannelConnection::PENDING,
        ]);

        $this->assertFalse($konto->isCoexistence());

        $konto->markConnection(ChannelConnection::CONNECTED);
        $this->assertTrue($konto->fresh()->isCoexistence());
    }
}
