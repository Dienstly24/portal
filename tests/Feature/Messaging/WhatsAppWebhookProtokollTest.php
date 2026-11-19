<?php

namespace Tests\Feature\Messaging;

use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use App\Models\Channel;
use App\Models\ChannelAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * KI-109 (19.11.2026): Zustellungen von Meta an /webhooks/whatsapp
 * hinterliessen keine Spur, und ein am Konto veraltetes App-Secret liess
 * JEDE Zustellung lautlos mit 403 enden.
 */
class WhatsAppWebhookProtokollTest extends TestCase
{
    use RefreshDatabase;

    private const ALT = 'altes-secret';

    private const NEU = 'neues-secret';

    private function konto(string $secret = self::ALT): ChannelAccount
    {
        return ChannelAccount::create([
            'channel_id' => Channel::where('key', 'whatsapp')->firstOrFail()->id,
            'name' => 'Geschaeftsnummer',
            'is_active' => true,
            'credentials' => [
                'access_token' => 'TOKEN-X',
                'phone_number_id' => '111222333',
                'app_secret' => $secret,
                'verify_token' => 'VERIFY-ME',
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function fehlgeschlagen(): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => '111222333'],
                        'statuses' => [[
                            'id' => 'wamid.X',
                            'status' => 'failed',
                            'timestamp' => '1790000000',
                            'recipient_id' => '491701234567',
                            'errors' => [[
                                'code' => 131047,
                                'title' => 'Re-engagement message',
                                'message' => 'Re-engagement message',
                                'error_data' => ['details' => 'Message failed to send because more than 24 hours have passed.'],
                            ]],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    /** Eigener Kanal in eine Wegwerf-Datei, damit der Test den Inhalt lesen kann. */
    private function protokollDatei(): string
    {
        $datei = storage_path('logs/test-whatsapp-webhook-'.uniqid().'.log');
        config(['logging.channels.whatsapp_webhook' => ['driver' => 'single', 'path' => $datei]]);
        app('log')->forgetChannel('whatsapp_webhook');
        $this->beforeApplicationDestroyed(fn () => @unlink($datei));

        return $datei;
    }

    private function senden(array $payload, ?string $secret)
    {
        $roh = json_encode($payload);
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($secret !== null) {
            $server['HTTP_X-Hub-Signature-256'] = 'sha256='.hash_hmac('sha256', $roh, $secret);
        }

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], $server, $roh);
    }

    /** Der eigentliche Fehler: Konto mit altem Secret, Meta signiert mit dem neuen. */
    public function test_signatur_mit_aktuellem_server_secret_wird_angenommen(): void
    {
        Queue::fake();
        config(['services.meta.app_secret' => self::NEU]);
        $this->konto(self::ALT);

        $this->senden($this->fehlgeschlagen(), self::NEU)->assertOk();

        Queue::assertPushed(\App\Jobs\Messaging\ProcessWhatsAppWebhookJob::class);
    }

    /** Gegenprobe Angreifer: ein drittes Secret bleibt abgewiesen. */
    public function test_fremdes_secret_bleibt_abgewiesen(): void
    {
        Queue::fake();
        config(['services.meta.app_secret' => self::NEU]);
        $this->konto(self::ALT);

        $this->senden($this->fehlgeschlagen(), 'angreifer')->assertStatus(403);
        $this->senden($this->fehlgeschlagen(), null)->assertStatus(403);

        Queue::assertNothingPushed();
    }

    public function test_jede_zustellung_und_der_fehlercode_werden_protokolliert(): void
    {
        Queue::fake();
        $this->konto();

        $datei = $this->protokollDatei();

        $this->senden($this->fehlgeschlagen(), self::ALT)->assertOk();

        $inhalt = (string) file_get_contents($datei);
        $this->assertStringContainsString('Zustellung eingegangen', $inhalt);
        $this->assertStringContainsString('Zustellmeldung failed', $inhalt);
        $this->assertStringContainsString('"recipient_id":"491701234567"', $inhalt);
        $this->assertStringContainsString('"code":131047', $inhalt);
        $this->assertStringContainsString('more than 24 hours', $inhalt);
    }

    public function test_abweisung_nennt_ihren_grund(): void
    {
        Queue::fake();
        $this->konto();

        $datei = $this->protokollDatei();

        $this->senden($this->fehlgeschlagen(), 'falsch')->assertStatus(403);

        $this->assertStringContainsString('Signatur ungueltig', (string) file_get_contents($datei));
    }

    /** Signierte Zustellung ohne Rufnummern-Kennung: quittieren, nicht 403. */
    public function test_zustellung_ohne_rufnummer_wird_quittiert_wenn_signiert(): void
    {
        Queue::fake();
        config(['services.meta.app_secret' => self::NEU]);
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'WABA1',
            'changes' => [['field' => 'account_update', 'value' => ['event' => 'VERIFIED_ACCOUNT']]],
        ]]];

        $this->senden($payload, self::NEU)->assertOk();
        $this->senden($payload, 'angreifer')->assertStatus(403);

        Queue::assertNothingPushed();
    }

    public function test_fehlgeschlagene_status_werden_herausgezogen(): void
    {
        $liste = WhatsAppWebhookController::fehlgeschlageneStatus($this->fehlgeschlagen());

        $this->assertCount(1, $liste);
        $this->assertSame('Re-engagement message', $liste[0]['errors'][0]['title']);
    }
}
