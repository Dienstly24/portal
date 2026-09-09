<?php

namespace Tests\Feature\Messaging;

use App\Jobs\AnswerCustomerMessageJob;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\AiMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * DIE VIER WEGE VON AUSSEN NACH INNEN UND ZURUECK (Auftrag Prioritaet 6).
 *
 * Jeder Fall laeuft ueber die GANZE Kette - vom signierten Webhook bis in
 * die Liste, die der Mitarbeiter sieht, bzw. vom Klick bis zum Aufruf bei
 * Meta. Einzelteile sind anderswo geprueft; hier geht es darum, dass sie
 * zusammen funktionieren.
 */
class WhatsAppEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'app-secret-123';

    private const EIGENE = '+49 170 9999999';

    private const KUNDE = '491701234567';

    protected function setUp(): void
    {
        parent::setUp();
        Channel::where('key', 'whatsapp')->update(['is_active' => true]);
    }

    private function konto(): ChannelAccount
    {
        return ChannelAccount::create([
            'channel_id' => Channel::where('key', 'whatsapp')->firstOrFail()->id,
            'name' => 'Geschaeftsnummer',
            'is_active' => true,
            'credentials' => [
                'access_token' => 'TOKEN-X', 'phone_number_id' => '111222333',
                'app_secret' => self::SECRET, 'verify_token' => 'VERIFY-ME',
            ],
        ]);
    }

    private function kunde(string $name = 'Max Muster'): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
            'mobile' => '+'.self::KUNDE,
        ]);
    }

    /** @return array<string,mixed> */
    private function nutzlast(string $feld, array $eintrag): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => '111222333', 'display_phone_number' => self::EIGENE],
                'contacts' => [['wa_id' => self::KUNDE, 'profile' => ['name' => 'Max Muster']]],
                $feld => [$eintrag],
            ]]]]],
        ];
    }

    private function zustellen(array $payload): void
    {
        $roh = (string) json_encode($payload);
        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $roh, self::SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $roh)->assertOk();
    }

    private function kiAn(): void
    {
        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set('ai_assistant_auto_reply', '1');
        Channel::where('key', 'whatsapp')->update(['ai_mode' => AiMode::AUTO_REPLY]);
    }

    /**
     * WEG 1 - BESTANDSKUNDE.
     *
     * WhatsApp -> Meta -> Webhook -> Adapter -> Engine -> Kundenakte ->
     * Betreuer -> Postfach. Geprueft wird das ENDE der Kette: der
     * Mitarbeiter sieht die Nachricht in seiner Liste.
     */
    public function test_weg_1_bestandskunde_landet_beim_betreuer_im_postfach(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->konto();
        $kunde = $this->kunde('Bestands Kundin');
        $betreuer = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $kunde->betreuer()->attach($betreuer->id, ['is_primary' => true]);
        $mitarbeiter = User::factory()->create(['role' => 'employee']);
        $kunde->betreuer()->attach($mitarbeiter->id);

        $this->zustellen($this->nutzlast('messages', [
            'from' => self::KUNDE, 'id' => 'wamid.E2E1', 'timestamp' => '1780000000',
            'type' => 'text', 'text' => ['body' => 'Wann laeuft mein Vertrag aus?'],
        ]));

        // Kundenakte gefunden, Betreuer zugewiesen.
        $unterhaltung = Conversation::firstOrFail();
        $this->assertSame((string) $kunde->id, (string) $unterhaltung->customer_id);
        $this->assertSame($betreuer->id, $unterhaltung->assigned_employee_id);

        // Und im Postfach seines Betreuers sichtbar - ungelesen.
        $this->actingAs($betreuer)->get(route('admin.postfach', ['sicht' => 'ungelesen']))
            ->assertOk()
            ->assertSee('Bestands Kundin')
            ->assertSee('WhatsApp');
    }

    /**
     * WEG 2 - UNBEKANNTER ABSENDER.
     *
     * Ohne Kundenakte geht die Nachricht NICHT verloren: sie steht im
     * Postfach, laesst sich zuordnen, und danach haengt der Betreuer dran.
     */
    public function test_weg_2_unbekannter_absender_bleibt_sichtbar_und_wird_zugeordnet(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $konto = $this->konto();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->zustellen($this->nutzlast('messages', [
            'from' => '491700001111', 'id' => 'wamid.E2E2', 'timestamp' => '1780000000',
            'type' => 'text', 'text' => ['body' => 'Hallo, ich interessiere mich fuer eine Police'],
        ]));

        $unterhaltung = Conversation::firstOrFail();
        $this->assertNull($unterhaltung->customer_id);

        // Sichtbar - der Punkt, an dem die alte Oberflaeche gescheitert waere.
        $this->actingAs($admin)->get(route('admin.postfach'))
            ->assertOk()->assertSee('Unbekannter Kontakt')->assertSee('491700001111');

        // Zuordnen, Betreuer setzen.
        $kunde = $this->kunde('Spaet Erkannt');
        $betreuer = User::factory()->create(['role' => 'employee']);
        $kunde->betreuer()->attach($betreuer->id, ['is_primary' => true]);

        $this->actingAs($admin)
            ->post(route('admin.postfach.link_customer', $unterhaltung->id), ['customer_id' => $kunde->id])
            ->assertRedirect();

        $frisch = $unterhaltung->fresh();
        $this->assertSame((string) $kunde->id, (string) $frisch->customer_id);
        $this->assertSame($betreuer->id, $frisch->assigned_employee_id);
        // Der urspruengliche Wortlaut ist erhalten.
        $this->assertStringContainsString(
            'interessiere mich',
            (string) $frisch->messages()->first()->body
        );
    }

    /**
     * WEG 3 - COEXISTENCE-ECHO.
     *
     * Was ein Mitarbeiter in der Business App getippt hat, wird nie eine
     * Kundenfrage: kein Ungelesen, keine KI, keine Schleife.
     */
    public function test_weg_3_echo_wird_keine_kundenfrage(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->kiAn();
        $this->konto();
        $this->kunde();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->zustellen($this->nutzlast('message_echoes', [
            'from' => '491709999999', 'to' => self::KUNDE, 'id' => 'wamid.E2E3',
            'timestamp' => '1780000000', 'type' => 'text',
            'text' => ['body' => 'Ich melde mich morgen'],
        ]));

        $nachricht = CustomerMessage::firstOrFail();
        $this->assertSame(CustomerMessage::DIRECTION_OUTGOING, $nachricht->direction);
        $this->assertNotNull($nachricht->read_at);
        Queue::assertNotPushed(AnswerCustomerMessageJob::class);

        // Und im Postfach steht sie NICHT als offene Kundenfrage.
        $this->actingAs($admin)->get(route('admin.postfach', ['sicht' => 'ungelesen']))
            ->assertOk()->assertSee('Keine Unterhaltungen');
    }

    /**
     * WEG 4 - AUSGEHEND.
     *
     * Klick im Postfach -> Nachricht -> Job -> Adapter -> Meta. Geprueft
     * wird der ECHTE Aufruf, nicht nur der Datensatz.
     */
    public function test_weg_4_antwort_aus_dem_postfach_erreicht_meta(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
        $konto = $this->konto();
        $kunde = $this->kunde();
        $admin = User::factory()->create(['role' => 'admin']);

        $unterhaltung = Conversation::create([
            'channel_id' => Channel::where('key', 'whatsapp')->firstOrFail()->id,
            'channel_account_id' => $konto->id,
            'customer_id' => $kunde->id,
            'external_user_id' => self::KUNDE,
            'status' => Conversation::STATUS_OPEN,
        ]);
        CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'customer_id' => $kunde->id,
            'body' => 'Frage', 'from_staff' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.postfach.reply', $unterhaltung->id), [
                'body' => 'Ihr Vertrag laeuft bis 31.12.',
            ])->assertRedirect();

        Http::assertSent(fn ($r) => str_contains($r->url(), '111222333/messages')
            && $r['to'] === self::KUNDE
            && $r['text']['body'] === 'Ihr Vertrag laeuft bis 31.12.'
        );

        $gesendet = CustomerMessage::where('from_staff', true)->firstOrFail();
        $this->assertSame('wamid.OUT', $gesendet->external_message_id);
        $this->assertSame(CustomerMessage::STATUS_SENT, $gesendet->status);
    }

    /**
     * WEG 4b - dieselbe Kette, aber von der KI angestossen. Sie geht
     * ueber DENSELBEN Weg; einen zweiten Versandweg gibt es nicht.
     */
    public function test_weg_4b_ki_antwort_nimmt_denselben_weg(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.KI']]], 200)]);
        $konto = $this->konto();
        $kunde = $this->kunde();

        $unterhaltung = Conversation::create([
            'channel_id' => Channel::where('key', 'whatsapp')->firstOrFail()->id,
            'channel_account_id' => $konto->id,
            'customer_id' => $kunde->id,
            'external_user_id' => self::KUNDE,
            'status' => Conversation::STATUS_OPEN,
        ]);
        CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'customer_id' => $kunde->id,
            'body' => 'Frage', 'from_staff' => false,
        ]);

        CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'customer_id' => $kunde->id,
            'body' => 'Gerne helfe ich weiter.', 'from_staff' => true, 'ai_generated' => true,
        ]);

        Http::assertSent(fn ($r) => $r['text']['body'] === 'Gerne helfe ich weiter.');
    }
}
