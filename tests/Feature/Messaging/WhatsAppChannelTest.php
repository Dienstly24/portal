<?php

namespace Tests\Feature\Messaging;

use App\Jobs\Messaging\ProcessWhatsAppWebhookJob;
use App\Jobs\Messaging\SendOutboundMessageJob;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\User;
use App\Services\Messaging\Channels\ChannelManager;
use App\Services\Messaging\Channels\WhatsAppAdapter;
use App\Services\Messaging\ConversationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * WhatsApp Cloud API (Auftrag Abschnitt 30).
 *
 * Der wichtigste Teil ist die SIGNATUR: sie ist der einzige Beleg dafuer,
 * dass eine Zustellung wirklich von Meta kommt. Faellt sie, kann jeder
 * Nachrichten in fremde Kundenakten schreiben.
 */
class WhatsAppChannelTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'app-secret-123';

    private function konto(): ChannelAccount
    {
        return ChannelAccount::create([
            'channel_id' => Channel::where('key', 'whatsapp')->firstOrFail()->id,
            'name' => 'Geschaeftsnummer',
            'is_active' => true,
            'credentials' => [
                'access_token' => 'TOKEN-X',
                'phone_number_id' => '111222333',
                'app_secret' => self::SECRET,
                'verify_token' => 'VERIFY-ME',
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function nutzlast(string $text = 'Hallo', string $msgId = 'wamid.A'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => '111222333'],
                        'contacts' => [['wa_id' => '491701234567', 'profile' => ['name' => 'Max Muster']]],
                        'messages' => [[
                            'from' => '491701234567',
                            'id' => $msgId,
                            'timestamp' => '1780000000',
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function signiert(array $payload, ?string $secret = null): array
    {
        $roh = json_encode($payload);

        return [$roh, 'sha256='.hash_hmac('sha256', $roh, $secret ?? self::SECRET)];
    }

    /** Fall 1: Der Kanal existiert und ist INAKTIV - er schaltet sich nicht selbst live. */
    public function test_der_kanal_existiert_und_ist_zunaechst_aus(): void
    {
        $kanal = Channel::where('key', 'whatsapp')->first();

        $this->assertNotNull($kanal);
        $this->assertFalse($kanal->is_active);
        $this->assertTrue($kanal->supports('supportsMedia'));
    }

    /** Fall 2: Eine GUELTIG signierte Zustellung wird angenommen. */
    public function test_gueltige_signatur_wird_angenommen(): void
    {
        Queue::fake();
        $this->konto();
        [$roh, $signatur] = $this->signiert($this->nutzlast());

        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $signatur,
            'CONTENT_TYPE' => 'application/json',
        ], $roh)->assertOk();

        Queue::assertPushed(ProcessWhatsAppWebhookJob::class);
    }

    /**
     * Fall 3: DER SICHERHEITSKERN. Eine falsch signierte Zustellung wird
     * abgewiesen - und es entsteht NICHTS.
     */
    public function test_falsche_signatur_wird_abgewiesen(): void
    {
        Queue::fake();
        $this->konto();
        [$roh] = $this->signiert($this->nutzlast(), 'falsches-secret');

        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $roh, 'falsches-secret'),
            'CONTENT_TYPE' => 'application/json',
        ], $roh)->assertStatus(403);

        Queue::assertNothingPushed();
        $this->assertSame(0, Conversation::count());
    }

    /** Fall 4: Ganz ohne Signatur ebenfalls nicht. */
    public function test_ohne_signatur_wird_abgewiesen(): void
    {
        Queue::fake();
        $this->konto();
        [$roh] = $this->signiert($this->nutzlast());

        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'], $roh)
            ->assertStatus(403);

        Queue::assertNothingPushed();
    }

    /**
     * Fall 5: OHNE hinterlegtes App-Secret wird abgelehnt, nie
     * durchgewunken - ein Schutz, der bei fehlender Einrichtung
     * durchlaesst, ist genau dann aus, wenn er gebraucht wird.
     */
    public function test_ohne_app_secret_wird_nichts_angenommen(): void
    {
        $adapter = app(WhatsAppAdapter::class);
        $konto = $this->konto();
        $konto->forceFill(['credentials' => ['access_token' => 'T']])->save();

        $this->assertFalse($adapter->verifyWebhook('{}', ['x-hub-signature-256' => 'sha256=abc'], $konto->fresh()));
    }

    /** Fall 6: Ersteinrichtung - richtiges Token gibt die Challenge zurueck. */
    public function test_ersteinrichtung_beantwortet_die_challenge(): void
    {
        $this->konto();

        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=VERIFY-ME&hub_challenge=12345')
            ->assertOk()->assertSee('12345');

        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=FALSCH&hub_challenge=12345')
            ->assertStatus(403);
    }

    /** Fall 7: Die Nutzlast wird in eine kanalfreie Nachricht uebersetzt. */
    public function test_nutzlast_wird_normalisiert(): void
    {
        $adapter = app(WhatsAppAdapter::class);
        $konto = $this->konto();

        $nachrichten = $adapter->parseInbound($this->nutzlast('Guten Tag'), $konto);

        $this->assertCount(1, $nachrichten);
        $this->assertSame('491701234567', $nachrichten[0]->externalUserId);
        $this->assertSame('Guten Tag', $nachrichten[0]->text);
        $this->assertSame('Max Muster', $nachrichten[0]->senderName);
        // Die wa_id IST die Telefonnummer - genau das braucht die Zuordnung.
        $this->assertSame('491701234567', $nachrichten[0]->senderPhone);
    }

    /** Fall 8: Ein Bild kommt als Anhang mit Medien-Kennung an. */
    public function test_bildnachricht_wird_als_anhang_gelesen(): void
    {
        $adapter = app(WhatsAppAdapter::class);
        $nutzlast = $this->nutzlast();
        $nutzlast['entry'][0]['changes'][0]['value']['messages'][0] = [
            'from' => '491701234567', 'id' => 'wamid.IMG', 'type' => 'image',
            'image' => ['id' => 'media-77', 'mime_type' => 'image/jpeg', 'caption' => 'Mein Zaehler'],
        ];

        $nachrichten = $adapter->parseInbound($nutzlast, $this->konto());

        $this->assertSame('image', $nachrichten[0]->type);
        $this->assertSame('Mein Zaehler', $nachrichten[0]->text);
        $this->assertSame('media-77', $nachrichten[0]->attachments[0]->externalMediaId);
    }

    /** Fall 9: Ein unbekannter Typ wird nie stillschweigend verworfen. */
    public function test_unbekannter_typ_geht_nicht_verloren(): void
    {
        $adapter = app(WhatsAppAdapter::class);
        $nutzlast = $this->nutzlast();
        $nutzlast['entry'][0]['changes'][0]['value']['messages'][0] = [
            'from' => '491701234567', 'id' => 'wamid.X', 'type' => 'reaction',
        ];

        $nachrichten = $adapter->parseInbound($nutzlast, $this->konto());

        $this->assertSame('unsupported', $nachrichten[0]->type);
        $this->assertStringContainsString('reaction', (string) $nachrichten[0]->text);
    }

    /** Fall 10: Zustellmeldungen werden uebersetzt. */
    public function test_zustellmeldungen_werden_gelesen(): void
    {
        $adapter = app(WhatsAppAdapter::class);
        $nutzlast = [
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => '111222333'],
                'statuses' => [
                    ['id' => 'wamid.OUT', 'status' => 'delivered'],
                    ['id' => 'wamid.BAD', 'status' => 'failed', 'errors' => [['title' => 'Nummer ungueltig']]],
                ],
            ]]]]],
        ];

        $meldungen = $adapter->parseStatusUpdates($nutzlast, $this->konto());

        $this->assertSame('delivered', $meldungen[0]['status']);
        $this->assertSame('failed', $meldungen[1]['status']);
        $this->assertSame('Nummer ungueltig', $meldungen[1]['reason']);
    }

    /**
     * Fall 11: DIE IDEMPOTENZ END-TO-END. Dieselbe Zustellung zweimal
     * ergibt EINE Nachricht - Meta wiederholt regelmaessig.
     */
    public function test_dieselbe_zustellung_erzeugt_nur_eine_nachricht(): void
    {
        $konto = $this->konto();
        $user = User::factory()->create(['role' => 'customer', 'email' => 'w@example.de']);
        Customer::create([
            'user_id' => $user->id, 'customer_number' => '2600777',
            'preferred_lang' => 'de', 'phone' => '0170 1234567',
        ]);

        $job = new ProcessWhatsAppWebhookJob($konto->id, $this->nutzlast('Frage', 'wamid.SAME'));
        $job->handle(app(WhatsAppAdapter::class), app(ConversationEngine::class));
        $job->handle(app(WhatsAppAdapter::class), app(ConversationEngine::class));

        $this->assertSame(1, CustomerMessage::count());
        $this->assertSame(1, Conversation::count());
    }

    /** Fall 12: Senden liefert die externe Kennung - ohne sie gibt es keine Statuszuordnung. */
    public function test_senden_speichert_die_externe_kennung(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'messages' => [['id' => 'wamid.NEU']],
            'contacts' => [['wa_id' => '491701234567']],
        ], 200)]);

        $konto = $this->konto();
        $unterhaltung = Conversation::create([
            'channel_id' => Channel::where('key', 'whatsapp')->firstOrFail()->id,
            'channel_account_id' => $konto->id,
            'external_user_id' => '491701234567',
            'status' => Conversation::STATUS_OPEN,
        ]);
        $nachricht = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id,
            'body' => 'Antwort des Teams',
            'from_staff' => true,
        ]);

        (new SendOutboundMessageJob($nachricht->id))->handle(app(ChannelManager::class));

        $frisch = $nachricht->fresh();
        $this->assertSame('wamid.NEU', $frisch->external_message_id);
        $this->assertSame(CustomerMessage::STATUS_SENT, $frisch->status);
    }

    /** Fall 13: Ein Fehlschlag steht am Datensatz - und wird nie doppelt gesendet. */
    public function test_fehlschlag_wird_vermerkt_und_nicht_wiederholt(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'nope']], 401)]);

        $konto = $this->konto();
        $unterhaltung = Conversation::create([
            'channel_id' => Channel::where('key', 'whatsapp')->firstOrFail()->id,
            'channel_account_id' => $konto->id,
            'external_user_id' => '491701234567',
            'status' => Conversation::STATUS_OPEN,
        ]);
        $nachricht = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'body' => 'x', 'from_staff' => true,
        ]);

        (new SendOutboundMessageJob($nachricht->id))->handle(app(ChannelManager::class));

        $frisch = $nachricht->fresh();
        $this->assertSame(CustomerMessage::STATUS_FAILED, $frisch->status);
        $this->assertNull($frisch->external_message_id);
        // Die Fremdmeldung wird NICHT durchgereicht.
        $this->assertStringNotContainsString('nope', (string) $frisch->failure_reason);
    }

    /**
     * Fall 14: Eine bereits abgesetzte Nachricht wird nie erneut
     * gesendet - der Schutz liegt am Datensatz, nicht an der Queue.
     */
    public function test_bereits_abgesetzte_nachricht_geht_nicht_erneut_raus(): void
    {
        Http::fake();
        $konto = $this->konto();
        $unterhaltung = Conversation::create([
            'channel_id' => Channel::where('key', 'whatsapp')->firstOrFail()->id,
            'channel_account_id' => $konto->id,
            'external_user_id' => '491701234567',
            'status' => Conversation::STATUS_OPEN,
        ]);
        $nachricht = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'body' => 'x', 'from_staff' => true,
            'external_message_id' => 'wamid.SCHON',
        ]);

        (new SendOutboundMessageJob($nachricht->id))->handle(app(ChannelManager::class));

        Http::assertNothingSent();
    }

    /** Fall 15: Eine unbrauchbare Nutzlast bekommt 200 - sonst wiederholt Meta endlos. */
    public function test_unbrauchbare_nutzlast_wird_quittiert(): void
    {
        $this->konto();

        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'kein json')
            ->assertOk();
    }
}
