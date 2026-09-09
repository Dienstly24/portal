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
 * DER VERLAUF AUS DER WHATSAPP BUSINESS APP (Betreiber-Vorgabe 09.09.2026).
 *
 * Beim Coexistence-Onboarding liefert Meta den bisherigen Schriftwechsel
 * nach. Er gehoert in die Unterhaltung: ein Mitarbeiter braucht den
 * Zusammenhang, und ohne ihn beginnt jede Kundenbeziehung im Portal bei
 * null.
 *
 * Aber er ist KEIN Ereignis. Wuerde jede nachgelieferte Nachricht wie
 * ein frischer Eingang behandelt, bekaeme der Kunde beim Anschalten der
 * Anbindung eine Lawine: Glocken, Zuweisungen und KI-Antworten auf
 * Fragen von vor Monaten. Genau diese Trennung pruefen diese Faelle.
 */
class WhatsAppHistoryImportTest extends TestCase
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
                'access_token' => 'T', 'phone_number_id' => '111222333',
                'app_secret' => self::SECRET, 'verify_token' => 'V',
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

    private function kiAn(): void
    {
        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set('ai_assistant_auto_reply', '1');
        Channel::where('key', 'whatsapp')->update(['ai_mode' => AiMode::AUTO_REPLY]);
    }

    /**
     * Die Nutzlast, wie Meta sie liefert: nach Faeden gruppiert, mit
     * Abschnitts-Metadaten (`phase`, `chunk_order`, `progress`).
     *
     * @return array<string,mixed>
     */
    private function verlauf(array $nachrichten, int $progress = 100): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [[
                'field' => 'history',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => [
                        'phone_number_id' => '111222333',
                        'display_phone_number' => self::EIGENE,
                    ],
                    'history' => [[
                        'metadata' => ['phase' => '1', 'chunk_order' => 1, 'progress' => $progress],
                        'threads' => [['id' => self::KUNDE, 'messages' => $nachrichten]],
                    ]],
                ],
            ]]]],
        ];
    }

    /** @return array<string,mixed> */
    private function nachricht(string $von, string $id, string $text, string $ts, ?string $an = null): array
    {
        $m = [
            'from' => $von, 'id' => $id, 'timestamp' => $ts,
            'type' => 'text', 'text' => ['body' => $text],
        ];
        if ($an !== null) {
            $m['to'] = $an;
        }

        return $m;
    }

    private function zustellen(array $payload): void
    {
        $roh = (string) json_encode($payload);
        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $roh, self::SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $roh)->assertOk();
    }

    /** Ein vollstaendiger Verlauf: Kunde und Betrieb im Wechsel. */
    private function beispielVerlauf(): array
    {
        return $this->verlauf([
            $this->nachricht(self::KUNDE, 'wamid.H1', 'Hallo, ich brauche eine Kfz-Police', '1740000000'),
            $this->nachricht('491709999999', 'wamid.H2', 'Gerne, ich melde mich', '1740003600', self::KUNDE),
            $this->nachricht(self::KUNDE, 'wamid.H3', 'Danke!', '1740007200'),
        ]);
    }

    // ---------------------------------------------------------------

    /** 1. Der Verlauf wird importiert - und als HISTORIE gekennzeichnet. */
    public function test_1_verlauf_wird_importiert(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->konto();
        $this->kunde();

        $this->zustellen($this->beispielVerlauf());

        $this->assertSame(3, CustomerMessage::count());
        $this->assertSame(3, CustomerMessage::historical()->count());
        $this->assertSame(0, CustomerMessage::live()->count());

        // Richtung aus dem Absender: die mittlere kam von UNS.
        $unsere = CustomerMessage::where('external_message_id', 'wamid.H2')->firstOrFail();
        $this->assertSame(CustomerMessage::DIRECTION_OUTGOING, $unsere->direction);
        $kundenfrage = CustomerMessage::where('external_message_id', 'wamid.H1')->firstOrFail();
        $this->assertSame(CustomerMessage::DIRECTION_INCOMING, $kundenfrage->direction);
    }

    /** 2. Derselbe Verlauf zweimal erzeugt nichts doppelt. */
    public function test_2_doppelter_verlauf_erzeugt_keine_duplikate(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->konto();
        $this->kunde();

        $this->zustellen($this->beispielVerlauf());
        $this->zustellen($this->beispielVerlauf());

        $this->assertSame(3, CustomerMessage::count());
        $this->assertSame(1, Conversation::count());
        $this->assertSame(1, Customer::count());
    }

    /** 3. DER KERNFALL: der Verlauf stoesst die KI NIE an. */
    public function test_3_verlauf_stoesst_die_ki_nicht_an(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->kiAn();
        $this->konto();
        $this->kunde();

        $this->zustellen($this->beispielVerlauf());

        Queue::assertNotPushed(AnswerCustomerMessageJob::class);
    }

    /** 4. Er erzeugt keinen Ungelesen-Stand. */
    public function test_4_verlauf_erhoeht_ungelesen_nicht(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->konto();
        $this->kunde();

        $this->zustellen($this->beispielVerlauf());

        $this->assertSame(0, CustomerMessage::fromCustomer()->unread()->count());
        $this->assertSame(0, CustomerMessage::whereNull('read_at')->count());
    }

    /** 5. Er loest keinen Versand aus - nichts geht ein zweites Mal raus. */
    public function test_5_verlauf_sendet_nichts(): void
    {
        Http::fake();
        $this->konto();
        $this->kunde();

        $this->zustellen($this->beispielVerlauf());

        Http::assertNothingSent();
    }

    /** 6. Er ist ueber die EINE Suche des Postfachs findbar. */
    public function test_6_verlauf_ist_durchsuchbar(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->konto();
        $this->kunde('Gesuchte Person');
        $this->zustellen($this->beispielVerlauf());

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.postfach', ['q' => 'Kfz-Police']))
            ->assertOk()
            ->assertSee('Gesuchte Person');
    }

    /**
     * 7. Er steht der KI als ZUSAMMENHANG zur Verfuegung - er haengt an
     * derselben Unterhaltung und demselben Kunden wie alles andere.
     */
    public function test_7_verlauf_steht_als_zusammenhang_bereit(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->konto();
        $kunde = $this->kunde();
        $this->zustellen($this->beispielVerlauf());

        $unterhaltung = Conversation::firstOrFail();
        $this->assertSame((string) $kunde->id, (string) $unterhaltung->customer_id);
        $this->assertSame(3, $unterhaltung->messages()->count());
        // Und am Kunden - der Weg, ueber den der Assistent liest.
        $this->assertSame(3, CustomerMessage::where('customer_id', $kunde->id)->count());
    }

    /** 8. Nach dem Verlauf ist eine LIVE-Nachricht wieder ein Ereignis. */
    public function test_8_live_nachrichten_bleiben_normale_eingaenge(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->kiAn();
        $this->konto();
        $kunde = $this->kunde();
        $betreuer = User::factory()->create(['role' => 'employee']);
        $kunde->betreuer()->attach($betreuer->id, ['is_primary' => true]);

        $this->zustellen($this->beispielVerlauf());
        Queue::assertNotPushed(AnswerCustomerMessageJob::class);

        // Jetzt eine echte, neue Nachricht.
        $this->zustellen([
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['field' => 'messages', 'value' => [
                'metadata' => ['phone_number_id' => '111222333', 'display_phone_number' => self::EIGENE],
                'contacts' => [['wa_id' => self::KUNDE, 'profile' => ['name' => 'Max']]],
                'messages' => [$this->nachricht(self::KUNDE, 'wamid.LIVE', 'Neue Frage', '1780000000')],
            ]]]]],
        ]);

        $live = CustomerMessage::where('external_message_id', 'wamid.LIVE')->firstOrFail();
        $this->assertSame(CustomerMessage::SOURCE_LIVE, $live->source);
        $this->assertNull($live->read_at);
        $this->assertSame($betreuer->id, Conversation::firstOrFail()->assigned_employee_id);
        Queue::assertPushed(AnswerCustomerMessageJob::class);
    }

    /**
     * 9. Historie und Live stehen in EINER Unterhaltung, chronologisch -
     * und die Historie holt sie nicht nach oben.
     */
    public function test_9_historie_und_live_stehen_chronologisch_zusammen(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->konto();
        $this->kunde();

        $this->zustellen($this->beispielVerlauf());
        $this->zustellen([
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['field' => 'messages', 'value' => [
                'metadata' => ['phone_number_id' => '111222333', 'display_phone_number' => self::EIGENE],
                'contacts' => [['wa_id' => self::KUNDE]],
                'messages' => [$this->nachricht(self::KUNDE, 'wamid.LIVE', 'Heute', '1780000000')],
            ]]]]],
        ]);

        $this->assertSame(1, Conversation::count());
        $reihenfolge = CustomerMessage::orderBy('created_at')->pluck('external_message_id')->all();
        $this->assertSame(['wamid.H1', 'wamid.H2', 'wamid.H3', 'wamid.LIVE'], $reihenfolge);

        // Die letzte Aktivitaet ist die LIVE-Nachricht, nicht der Import.
        $this->assertTrue(
            Conversation::firstOrFail()->last_message_at->greaterThan(
                CustomerMessage::where('external_message_id', 'wamid.H3')->firstOrFail()->created_at
            )
        );
    }

    /**
     * 10. Ein UNBEKANNTER Absender: der Verlauf geht nicht verloren, die
     * Unterhaltung entsteht - und bleibt trotzdem stumm.
     */
    public function test_10_verlauf_eines_unbekannten_bleibt_erhalten_und_stumm(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->kiAn();
        $this->konto();

        $this->zustellen($this->beispielVerlauf());

        $unterhaltung = Conversation::firstOrFail();
        $this->assertNull($unterhaltung->customer_id);
        $this->assertSame(3, $unterhaltung->messages()->count());
        $this->assertNull($unterhaltung->assigned_employee_id);
        $this->assertSame(0, CustomerMessage::whereNull('read_at')->count());
        Queue::assertNotPushed(AnswerCustomerMessageJob::class);

        // Und er ist im Postfach sichtbar - sonst waere er wertlos.
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.postfach'))
            ->assertOk()->assertSee('Unbekannter Kontakt');
    }

    /** 11. Die Kundenzuordnung bleibt dieselbe wie im Live-Betrieb. */
    public function test_11_kundenzuordnung_bleibt_gleich(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->konto();
        $kunde = $this->kunde();

        $this->zustellen($this->beispielVerlauf());
        $this->zustellen([
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['field' => 'messages', 'value' => [
                'metadata' => ['phone_number_id' => '111222333', 'display_phone_number' => self::EIGENE],
                'contacts' => [['wa_id' => self::KUNDE]],
                'messages' => [$this->nachricht(self::KUNDE, 'wamid.LIVE', 'Heute', '1780000000')],
            ]]]]],
        ]);

        foreach (CustomerMessage::all() as $nachricht) {
            $this->assertSame((string) $kunde->id, (string) $nachricht->customer_id);
        }
        $this->assertSame(1, Customer::count());
    }

    /**
     * 12. Der ECHTE Zeitpunkt zaehlt, nicht der des Imports. Sonst
     * stuende der halbe Verlauf unter dem Datum der Anbindung.
     */
    public function test_12_der_echte_zeitpunkt_bleibt_erhalten(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->konto();
        $this->kunde();

        $this->zustellen($this->beispielVerlauf());

        $erste = CustomerMessage::where('external_message_id', 'wamid.H1')->firstOrFail();
        $this->assertSame(
            '2025-02-19',
            $erste->created_at->format('Y-m-d'),
            'Der Zeitstempel muss der der urspruenglichen Nachricht sein.'
        );
    }

    /**
     * 13. Der Fortschritt wird vermerkt - damit die Oberflaeche NICHT
     * behaupten muss, der Verlauf sei vollstaendig.
     */
    public function test_13_der_fortschritt_wird_vermerkt(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $konto = $this->konto();
        $this->kunde();

        $this->zustellen($this->verlauf([
            $this->nachricht(self::KUNDE, 'wamid.T1', 'Teil eins', '1740000000'),
        ], progress: 50));

        $stand = $konto->fresh()->settings['history'] ?? [];
        $this->assertSame(1, $stand['chunks'] ?? null);
        $this->assertSame(50, $stand['progress'] ?? null);
        $this->assertNotNull($stand['last_chunk_at'] ?? null);
    }

    /** 14. Eine historische Nachricht wird NIE ausgehend zugestellt. */
    public function test_14_historische_eigene_nachricht_geht_nicht_erneut_raus(): void
    {
        Http::fake();
        $konto = $this->konto();
        $kunde = $this->kunde();
        $unterhaltung = Conversation::create([
            'channel_id' => $konto->channel_id, 'channel_account_id' => $konto->id,
            'customer_id' => $kunde->id, 'external_user_id' => self::KUNDE,
            'status' => Conversation::STATUS_OPEN,
        ]);

        // Direkt geschrieben: auch dieser Weg darf nichts absetzen.
        CustomerMessage::create([
            'conversation_id' => $unterhaltung->id,
            'customer_id' => $kunde->id,
            'body' => 'Alte eigene Nachricht',
            'from_staff' => true,
            'source' => CustomerMessage::SOURCE_HISTORICAL,
        ]);

        Http::assertNothingSent();
    }
}
