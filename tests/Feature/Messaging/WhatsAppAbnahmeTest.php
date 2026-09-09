<?php

namespace Tests\Feature\Messaging;

use App\Jobs\Messaging\SendOutboundMessageJob;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\CustomerMessageAttachment;
use App\Models\User;
use App\Services\Ai\Assistant\CustomerAssistantService;
use App\Services\Messaging\AssignmentService;
use App\Services\Messaging\Channels\ChannelManager;
use App\Services\Messaging\Channels\WhatsAppAdapter;
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\Dto\InboundAttachment;
use App\Services\Messaging\Dto\InboundMessage;
use App\Services\Messaging\Dto\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ABNAHME der WhatsApp-Anbindung, ein- und ausgehend.
 *
 * Der Unterschied zu den vorhandenen Testdateien ist die FLUGHOEHE: hier
 * wird nicht geprueft, ob ein Job angestossen wurde, sondern ob die
 * Nachricht die Meta-API tatsaechlich erreicht - und umgekehrt, dass
 * genau die Wege, die NICHT nach draussen gehoeren (interner Chat,
 * interne Notiz), auch keinen einzigen Aufruf ausloesen.
 *
 * Die Warteschlange laeuft in Tests synchron; ein `Http::assertSent`
 * nach dem Speichern belegt damit die ganze Kette Modell-Hook -> Job ->
 * Adapter -> API.
 */
class WhatsAppAbnahmeTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'app-secret-123';

    private function kanal(): Channel
    {
        return Channel::where('key', 'whatsapp')->firstOrFail();
    }

    private function konto(): ChannelAccount
    {
        return ChannelAccount::create([
            'channel_id' => $this->kanal()->id,
            'name' => 'Geschaeftsnummer',
            'is_active' => true,
            'credentials' => [
                'access_token' => 'TOKEN-X', 'phone_number_id' => '111222333',
                'app_secret' => self::SECRET, 'verify_token' => 'VERIFY-ME',
            ],
        ]);
    }

    private function unterhaltung(?Customer $kunde = null, ?ChannelAccount $konto = null): Conversation
    {
        return Conversation::create([
            'channel_id' => $this->kanal()->id,
            'channel_account_id' => ($konto ?? $this->konto())->id,
            'customer_id' => $kunde?->id,
            'external_user_id' => '491701234567',
            'status' => Conversation::STATUS_OPEN,
        ]);
    }

    /** Ein Kundeneingang, damit das 24-Stunden-Fenster offen steht. */
    private function eingang(Conversation $unterhaltung): CustomerMessage
    {
        $m = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id,
            'customer_id' => $unterhaltung->customer_id,
            'body' => 'Frage', 'from_staff' => false,
        ]);
        $m->forceFill(['created_at' => now()->subHour()])->saveQuietly();

        return $m;
    }

    private function apiAntwortet(string $id = 'wamid.OUT'): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => $id]]], 200)]);
    }

    private function kunde(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
            'mobile' => '+491701234567',
        ]);
    }

    /** @return array<string,mixed> */
    private function nutzlast(string $msgId = 'wamid.IN', string $text = 'Hallo'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => '111222333'],
                'contacts' => [['wa_id' => '491701234567', 'profile' => ['name' => 'Max Muster']]],
                'messages' => [[
                    'from' => '491701234567', 'id' => $msgId,
                    'timestamp' => '1780000000', 'type' => 'text',
                    'text' => ['body' => $text],
                ]],
            ]]]]],
        ];
    }

    // ---------------------------------------------------------------
    // 1. Die Antwort eines Mitarbeiters erreicht die API
    // ---------------------------------------------------------------

    public function test_1_mitarbeiter_antwort_erreicht_die_whatsapp_api(): void
    {
        $this->apiAntwortet('wamid.MITARBEITER');
        $konto = $this->konto();
        $unterhaltung = $this->unterhaltung($this->kunde(), $konto);
        $this->eingang($unterhaltung);

        $mitarbeiter = User::factory()->create(['role' => 'employee']);
        $antwort = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id,
            'customer_id' => $unterhaltung->customer_id,
            'sender_id' => $mitarbeiter->id,
            'body' => 'Gerne, ich pruefe das.',
            'from_staff' => true,
        ]);

        // Die Nutzlast muss beim richtigen Absender und Empfaenger landen -
        // ein Aufruf allein waere noch kein Beleg.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '111222333/messages')
                && $request['to'] === '491701234567'
                && $request['text']['body'] === 'Gerne, ich pruefe das.'
                && $request->hasHeader('Authorization', 'Bearer TOKEN-X');
        });
        $this->assertSame('wamid.MITARBEITER', $antwort->fresh()->external_message_id);
        $this->assertSame(CustomerMessage::STATUS_SENT, $antwort->fresh()->status);
    }

    // ---------------------------------------------------------------
    // 2. Die KI-Antwort erreicht die API ebenfalls
    // ---------------------------------------------------------------

    /**
     * DER FALL, DER DEN FEHLER GEZEIGT HAT. Die KI schrieb ihre Antwort
     * frueher nur an den KUNDEN, ohne Unterhaltung - im Portal sah man
     * sie (es liest nach Kunde), auf WhatsApp waere sie nie angekommen.
     * Geprueft wird deshalb ueber denselben Weg, den die KI benutzt:
     * eine Nachricht mit `ai_generated`.
     */
    public function test_2_ki_antwort_erreicht_die_whatsapp_api(): void
    {
        $this->apiAntwortet('wamid.KI');
        $unterhaltung = $this->unterhaltung($this->kunde());
        $this->eingang($unterhaltung);

        $antwort = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id,
            'customer_id' => $unterhaltung->customer_id,
            'sender_id' => null,
            'body' => 'Ihr Vertrag laeuft bis 31.12.',
            'from_staff' => true,
            'ai_generated' => true,
        ]);

        Http::assertSent(fn ($r) => $r['text']['body'] === 'Ihr Vertrag laeuft bis 31.12.');
        $this->assertSame('wamid.KI', $antwort->fresh()->external_message_id);
        $this->assertSame(CustomerMessage::SENDER_BOT, $antwort->fresh()->sender_type);
    }

    /**
     * Und der Weg dorthin: der Assistent haengt seine Antwort an DIESELBE
     * Unterhaltung. Ohne diese Zuordnung greift der Versand-Hook nicht.
     */
    public function test_2b_ki_antwort_haengt_an_derselben_unterhaltung(): void
    {
        $kunde = $this->kunde();
        $unterhaltung = $this->unterhaltung($kunde);
        $eingang = $this->eingang($unterhaltung);

        $spiegel = new \ReflectionMethod(
            CustomerAssistantService::class, 'reply'
        );
        $spiegel->setAccessible(true);
        $this->apiAntwortet();
        $antwort = $spiegel->invoke(
            app(CustomerAssistantService::class),
            $eingang, 'Antwort des Assistenten'
        );

        $this->assertSame($unterhaltung->id, $antwort->conversation_id);
    }

    // ---------------------------------------------------------------
    // 3./4. Was nicht nach draussen gehoert, geht auch nicht raus
    // ---------------------------------------------------------------

    public function test_3_interner_chat_geht_nie_an_whatsapp(): void
    {
        Http::fake();
        $intern = Conversation::create([
            'channel_id' => Channel::where('key', Channel::INTERNAL)->firstOrFail()->id,
            'status' => Conversation::STATUS_OPEN,
            // Kein external_user_id: der interne Chat hat keine Gegenstelle.
        ]);

        CustomerMessage::create([
            'conversation_id' => $intern->id,
            'body' => 'Bitte Rueckruf einplanen.',
            'from_staff' => true,
        ]);

        Http::assertNothingSent();
    }

    public function test_4_interne_notiz_geht_nie_raus(): void
    {
        Http::fake();
        $unterhaltung = $this->unterhaltung($this->kunde());
        $this->eingang($unterhaltung);

        // Eine Notiz traegt keine Gegenstelle: sie steht am KUNDEN, nicht
        // an der Kanal-Unterhaltung. Genau daran haengt der Versand.
        CustomerMessage::create([
            'customer_id' => $unterhaltung->customer_id,
            'body' => 'Intern: Kunde war 2024 im Verzug.',
            'from_staff' => true,
            'sender_type' => CustomerMessage::SENDER_SYSTEM,
        ]);

        Http::assertNothingSent();
    }

    /** Und der Portal-Chat ebenso: eigener Kanal, keine Gegenstelle. */
    public function test_4b_portal_nachricht_geht_nie_an_whatsapp(): void
    {
        Http::fake();
        $portal = Conversation::create([
            'channel_id' => Channel::where('key', 'portal')->firstOrFail()->id,
            'customer_id' => $this->kunde()->id,
            'status' => Conversation::STATUS_OPEN,
        ]);

        CustomerMessage::create([
            'conversation_id' => $portal->id,
            'customer_id' => $portal->customer_id,
            'body' => 'Antwort im Portal', 'from_staff' => true,
        ]);

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // 5. Wiederholte Zustellung
    // ---------------------------------------------------------------

    public function test_5_doppelte_zustellung_erzeugt_weder_nachricht_noch_versand_doppelt(): void
    {
        Http::fake();
        $konto = $this->konto();
        $roh = json_encode($this->nutzlast('wamid.DOPPEL'));
        $signatur = 'sha256='.hash_hmac('sha256', $roh, self::SECRET);

        foreach ([1, 2] as $ignoriert) {
            $this->call('POST', '/webhooks/whatsapp', [], [], [], [
                'HTTP_X_HUB_SIGNATURE_256' => $signatur,
                'CONTENT_TYPE' => 'application/json',
            ], $roh)->assertOk();
        }

        $this->assertSame(1, CustomerMessage::where('external_message_id', 'wamid.DOPPEL')->count());
        // Eine eingehende Nachricht loest nie einen Versand aus - der
        // Idempotenz-Schutz darf das nicht erst reparieren muessen.
        Http::assertNothingSent();
    }

    /**
     * Und ausgehend: eine Nachricht, die bereits eine externe Kennung
     * traegt, wird nie ein zweites Mal abgesetzt. Der Schutz liegt am
     * DATENSATZ, nicht an der Warteschlange.
     */
    public function test_5b_zweiter_versandlauf_sendet_nicht_erneut(): void
    {
        $this->apiAntwortet('wamid.EINMAL');
        $unterhaltung = $this->unterhaltung($this->kunde());
        $this->eingang($unterhaltung);

        $antwort = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id,
            'body' => 'Nur einmal', 'from_staff' => true,
        ]);
        Http::assertSentCount(1);

        (new SendOutboundMessageJob($antwort->id))
            ->handle(app(ChannelManager::class));

        Http::assertSentCount(1);
    }

    // ---------------------------------------------------------------
    // 6. Eingehende Mediendatei
    // ---------------------------------------------------------------

    public function test_6_eingehende_mediendatei_wird_geholt_und_gespeichert(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/media-77' => Http::response(['url' => 'https://lookaside.test/f', 'mime_type' => 'image/jpeg'], 200),
            'lookaside.test/*' => Http::response('BILDDATEN', 200),
        ]);

        $konto = $this->konto();
        app(ConversationEngine::class)->handleInbound(
            new InboundMessage(
                externalUserId: '491701234567', externalMessageId: 'wamid.MEDIA',
                text: '', type: 'image',
                attachments: [new InboundAttachment(
                    type: 'image', externalMediaId: 'media-77', mimeType: 'image/jpeg',
                )],
            ),
            $this->kanal(), $konto
        );

        $anhang = CustomerMessageAttachment::firstOrFail();
        $this->assertNotSame('', $anhang->file_path);
        $this->assertSame('local', $anhang->disk);
        Storage::disk('local')->assertExists($anhang->file_path);
        $this->assertStringStartsWith('customers/', $anhang->file_path);
    }

    // ---------------------------------------------------------------
    // 7./8. Das 24-Stunden-Fenster
    // ---------------------------------------------------------------

    /**
     * Die Frist ist PLATTFORMWISSEN. Sie steht im WhatsApp-Adapter; der
     * Kern (Unterhaltung, Engine, Zuweisung) kennt sie nicht - sonst
     * truege jeder kuenftige Kanal eine Regel mit, die nur fuer einen
     * gilt.
     */
    public function test_7_die_frist_gilt_nur_im_whatsapp_adapter(): void
    {
        $this->assertSame(24, WhatsAppAdapter::SERVICE_WINDOW_HOURS);

        foreach ([
            app_path('Services/Messaging/ConversationEngine.php'),
            app_path('Services/Messaging/AssignmentService.php'),
            app_path('Models/Conversation.php'),
            app_path('Services/Messaging/Channels/PortalAdapter.php'),
            app_path('Services/Messaging/Channels/InternalChatAdapter.php'),
        ] as $datei) {
            $this->assertStringNotContainsString(
                'SERVICE_WINDOW', (string) file_get_contents($datei),
                basename($datei).' darf die WhatsApp-Frist nicht kennen.'
            );
        }

        // Und der andere Kanal sendet ohne jede Fristpruefung.
        $portal = Conversation::create([
            'channel_id' => Channel::where('key', 'portal')->firstOrFail()->id,
            'customer_id' => $this->kunde()->id,
            'status' => Conversation::STATUS_OPEN,
        ]);
        $alt = CustomerMessage::create([
            'conversation_id' => $portal->id, 'body' => 'alt', 'from_staff' => false,
        ]);
        $alt->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();

        $ergebnis = app(ChannelManager::class)->driver('portal')->send(
            new OutboundMessage(
                recipientId: (string) $portal->customer_id,
                text: 'Auch nach 30 Tagen',
                lastInboundAt: $portal->fresh()->lastInboundAt(),
            ),
            null
        );
        $this->assertTrue($ergebnis->ok);
    }

    /** Ausserhalb des Fensters wird ehrlich abgelehnt statt blind gesendet. */
    public function test_7b_ausserhalb_des_fensters_wird_nicht_gesendet(): void
    {
        Http::fake();
        $unterhaltung = $this->unterhaltung($this->kunde());
        $alt = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'body' => 'Frage', 'from_staff' => false,
        ]);
        $alt->forceFill(['created_at' => now()->subHours(25)])->saveQuietly();

        $antwort = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'body' => 'Zu spaet', 'from_staff' => true,
        ]);

        Http::assertNothingSent();
        $frisch = $antwort->fresh();
        $this->assertSame(CustomerMessage::STATUS_FAILED, $frisch->status);
        $this->assertStringContainsString('24-Stunden-Fenster', (string) $frisch->failure_reason);
    }

    /**
     * "Wir wissen es nicht" ist kein Ablehnungsgrund: ohne bekannten
     * Eingang geht die erste Antwort raus. Sonst waere nach dem Nachtrag
     * jede Unterhaltung stumm.
     */
    public function test_8_ohne_bekannten_eingang_geht_die_erste_antwort_raus(): void
    {
        $this->apiAntwortet('wamid.ERST');
        $unterhaltung = $this->unterhaltung($this->kunde());
        $this->assertNull($unterhaltung->lastInboundAt());

        $antwort = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'body' => 'Erstkontakt', 'from_staff' => true,
        ]);

        Http::assertSent(fn ($r) => $r['text']['body'] === 'Erstkontakt');
        $this->assertSame('wamid.ERST', $antwort->fresh()->external_message_id);
    }

    // ---------------------------------------------------------------
    // 9. Zustell-, Lese- und Fehlermeldungen
    // ---------------------------------------------------------------

    public function test_9_zustellung_lesen_und_fehlschlag_werden_gefuehrt(): void
    {
        $this->apiAntwortet('wamid.STATUS');
        $unterhaltung = $this->unterhaltung($this->kunde());
        $this->eingang($unterhaltung);

        $nachricht = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'body' => 'Text', 'from_staff' => true,
        ]);
        $this->assertSame(CustomerMessage::STATUS_SENT, $nachricht->fresh()->status);

        $engine = app(ConversationEngine::class);
        $this->assertTrue($engine->handleStatus('wamid.STATUS', CustomerMessage::STATUS_DELIVERED));
        $this->assertNotNull($nachricht->fresh()->delivered_at);

        $this->assertTrue($engine->handleStatus('wamid.STATUS', CustomerMessage::STATUS_READ));
        $this->assertSame(CustomerMessage::STATUS_READ, $nachricht->fresh()->status);

        // RUECKWAERTS NIE: Meldungen treffen regelmaessig in falscher
        // Reihenfolge ein - eine gelesene Nachricht darf nicht wieder
        // auf "zugestellt" zurueckfallen.
        $this->assertFalse($engine->handleStatus('wamid.STATUS', CustomerMessage::STATUS_DELIVERED));
        $this->assertSame(CustomerMessage::STATUS_READ, $nachricht->fresh()->status);

        // Ein Fehlschlag ist dagegen immer die juengere Wahrheit.
        $this->assertTrue($engine->handleStatus('wamid.STATUS', CustomerMessage::STATUS_FAILED, 'Nummer ungueltig'));
        $frisch = $nachricht->fresh();
        $this->assertSame(CustomerMessage::STATUS_FAILED, $frisch->status);
        $this->assertSame('Nummer ungueltig', $frisch->failure_reason);
    }

    /** Ein Fehler der API landet am Datensatz - ohne fremden Wortlaut. */
    public function test_9b_api_fehler_wird_vermerkt(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Interner Meta-Text', 'code' => 131047],
        ], 400)]);
        $unterhaltung = $this->unterhaltung($this->kunde());
        $this->eingang($unterhaltung);

        $nachricht = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'body' => 'Text', 'from_staff' => true,
        ]);

        $frisch = $nachricht->fresh();
        $this->assertSame(CustomerMessage::STATUS_FAILED, $frisch->status);
        $this->assertNull($frisch->external_message_id);
        $this->assertStringNotContainsString('Interner Meta-Text', (string) $frisch->failure_reason);
    }

    // ---------------------------------------------------------------
    // 10./11. Betreuer und Zustaendigkeit
    // ---------------------------------------------------------------

    /**
     * Der Betreuer gehoert zum KUNDEN, die Zustaendigkeit zur
     * UNTERHALTUNG. Uebernimmt der Support einen Vorgang - oder antwortet
     * die KI -, aendert das den Betreuer nie.
     */
    public function test_10_betreuer_bleibt_bei_support_und_ki_antwort(): void
    {
        $this->apiAntwortet();
        $betreuer = User::factory()->create(['role' => 'employee']);
        $support = User::factory()->create(['role' => 'support']);
        $kunde = $this->kunde();
        $kunde->betreuer()->attach($betreuer->id, ['is_primary' => true]);

        $unterhaltung = $this->unterhaltung($kunde);
        $this->eingang($unterhaltung);
        $this->assertSame($betreuer->id, $kunde->fresh()->betreuerPrimary()?->id);

        // Support uebernimmt und antwortet.
        app(AssignmentService::class)->takeOver($unterhaltung, $support);
        CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'customer_id' => $kunde->id,
            'sender_id' => $support->id, 'body' => 'Ich uebernehme.', 'from_staff' => true,
        ]);

        // Und danach die KI.
        CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'customer_id' => $kunde->id,
            'body' => 'Automatische Auskunft.', 'from_staff' => true, 'ai_generated' => true,
        ]);

        $this->assertSame($betreuer->id, $kunde->fresh()->betreuerPrimary()?->id);
        $this->assertSame($support->id, $unterhaltung->fresh()->assigned_employee_id);
    }

    /**
     * Zwei getrennte Felder, zwei getrennte Wahrheiten - und eine spaetere
     * Kundennachricht nimmt die Uebernahme nicht zurueck (sonst saehe der
     * Support seine Faelle bei der naechsten Antwort verschwinden).
     */
    public function test_11_zustaendigkeit_bleibt_vom_betreuer_getrennt(): void
    {
        $betreuer = User::factory()->create(['role' => 'employee']);
        $support = User::factory()->create(['role' => 'support']);
        $kunde = $this->kunde();
        $kunde->betreuer()->attach($betreuer->id, ['is_primary' => true]);

        $konto = $this->konto();
        $unterhaltung = $this->unterhaltung($kunde, $konto);
        app(AssignmentService::class)->takeOver($unterhaltung, $support);

        app(ConversationEngine::class)->handleInbound(
            new InboundMessage(
                externalUserId: '491701234567', externalMessageId: 'wamid.SPAETER',
                text: 'Noch eine Frage',
            ),
            $this->kanal(), $konto
        );

        $frisch = $unterhaltung->fresh();
        $this->assertSame($support->id, $frisch->assigned_employee_id);
        $this->assertSame($betreuer->id, $kunde->fresh()->betreuerPrimary()?->id);
        $this->assertNotSame($frisch->assigned_employee_id, $kunde->fresh()->betreuerPrimary()?->id);
    }
}
