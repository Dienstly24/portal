<?php

namespace Tests\Feature\Messaging;

use App\Jobs\Messaging\FetchInboundMediaJob;
use App\Jobs\Messaging\SendOutboundMessageJob;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\CustomerMessageAttachment;
use App\Models\User;
use App\Services\Messaging\Channels\ChannelManager;
use App\Services\Messaging\Channels\WhatsAppAdapter;
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\Dto\InboundAttachment;
use App\Services\Messaging\Dto\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Der Weg NACH DRAUSSEN und die Mediendateien - die Luecken, die eine
 * empfangende Anbindung von einer benutzbaren trennen.
 *
 * Der wichtigste Fall ist der erste: ohne ihn schreibt ein Mitarbeiter
 * eine Antwort, sieht sie im Verlauf, und der Kunde bekommt sie nie.
 */
class WhatsAppDeliveryTest extends TestCase
{
    use RefreshDatabase;

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
                'app_secret' => 'secret', 'verify_token' => 'verify',
            ],
        ]);
    }

    private function unterhaltung(?ChannelAccount $konto = null): Conversation
    {
        return Conversation::create([
            'channel_id' => $this->kanal()->id,
            'channel_account_id' => ($konto ?? $this->konto())->id,
            'external_user_id' => '491701234567',
            'status' => Conversation::STATUS_OPEN,
        ]);
    }

    /**
     * Fall 1: DIE LUECKE, um die es geht. Eine Mitarbeiter-Antwort in
     * einer Kanal-Unterhaltung stoesst den Versand an - ohne diesen
     * Anstoss saehe der Mitarbeiter seine Antwort im Verlauf, und der
     * Kunde bekaeme sie nie.
     */
    public function test_mitarbeiter_antwort_stoesst_den_versand_an(): void
    {
        Queue::fake();
        $unterhaltung = $this->unterhaltung();

        CustomerMessage::create([
            'conversation_id' => $unterhaltung->id,
            'body' => 'Gerne, ich pruefe das.',
            'from_staff' => true,
        ]);

        Queue::assertPushed(SendOutboundMessageJob::class);
    }

    /** Fall 2: Eine KUNDEN-Nachricht loest nie einen Versand aus. */
    public function test_kundennachricht_stoesst_keinen_versand_an(): void
    {
        Queue::fake();
        $unterhaltung = $this->unterhaltung();

        CustomerMessage::create([
            'conversation_id' => $unterhaltung->id,
            'body' => 'Meine Frage',
            'from_staff' => false,
        ]);

        Queue::assertNotPushed(SendOutboundMessageJob::class);
    }

    /**
     * Fall 3: Portal und interner Chat haben keine Gegenstelle draussen -
     * dort entsteht kein Versand-Job. Sonst liefe fuer jede
     * Portal-Nachricht ein Job ins Leere.
     */
    public function test_ohne_gegenstelle_entsteht_kein_versand(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => 'customer', 'email' => 'p@example.de']);
        $kunde = Customer::create([
            'user_id' => $user->id, 'customer_number' => '2600881', 'preferred_lang' => 'de',
        ]);
        $portal = Conversation::create([
            'customer_id' => $kunde->id,
            'channel_id' => Channel::idFor(Channel::PORTAL),
            'status' => Conversation::STATUS_OPEN,
        ]);

        CustomerMessage::create([
            'conversation_id' => $portal->id, 'customer_id' => $kunde->id,
            'body' => 'Antwort im Portal', 'from_staff' => true,
        ]);

        Queue::assertNotPushed(SendOutboundMessageJob::class);
    }

    /** Fall 4: Eine bereits abgesetzte Nachricht loest nichts erneut aus. */
    public function test_bereits_abgesetzte_nachricht_stoesst_nichts_an(): void
    {
        Queue::fake();
        $unterhaltung = $this->unterhaltung();

        CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'body' => 'x', 'from_staff' => true,
            'external_message_id' => 'wamid.SCHON',
        ]);

        Queue::assertNotPushed(SendOutboundMessageJob::class);
    }

    /**
     * Fall 5: DAS 24-STUNDEN-FENSTER. Ausserhalb wird gar nicht erst
     * gesendet - Meta lehnt eine freie Nachricht mit einem Fehlercode ab,
     * den niemand ohne Nachschlagen versteht.
     */
    public function test_ausserhalb_des_24_stunden_fensters_wird_nicht_gesendet(): void
    {
        Http::fake();
        $konto = $this->konto();
        $unterhaltung = $this->unterhaltung($konto);

        // Letzte Kundennachricht vor 30 Stunden.
        $eingang = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'body' => 'Frage', 'from_staff' => false,
        ]);
        $eingang->forceFill(['created_at' => now()->subHours(30)])->saveQuietly();

        $antwort = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'body' => 'Spaete Antwort', 'from_staff' => true,
        ]);

        (new SendOutboundMessageJob($antwort->id))->handle(app(ChannelManager::class));

        Http::assertNothingSent();
        $frisch = $antwort->fresh();
        $this->assertSame(CustomerMessage::STATUS_FAILED, $frisch->status);
        $this->assertStringContainsString('24-Stunden-Fenster', (string) $frisch->failure_reason);
    }

    /** Fall 6: INNERHALB des Fensters geht die Nachricht raus. */
    public function test_innerhalb_des_fensters_wird_gesendet(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'messages' => [['id' => 'wamid.OK']],
        ], 200)]);
        $unterhaltung = $this->unterhaltung();

        $eingang = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'body' => 'Frage', 'from_staff' => false,
        ]);
        $eingang->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();

        $antwort = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'body' => 'Antwort', 'from_staff' => true,
        ]);

        (new SendOutboundMessageJob($antwort->id))->handle(app(ChannelManager::class));

        $this->assertSame('wamid.OK', $antwort->fresh()->external_message_id);
    }

    /**
     * Fall 7: OHNE bekannte Kundennachricht wird NICHT gesperrt. Das ist
     * der Fall "wir wissen es nicht" - dort ist der echte Fehler von Meta
     * ehrlicher als eine Sperre auf Verdacht.
     */
    public function test_ohne_bekannten_eingang_wird_nicht_gesperrt(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.E']]], 200)]);
        $unterhaltung = $this->unterhaltung();

        $antwort = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'body' => 'Erstkontakt', 'from_staff' => true,
        ]);

        (new SendOutboundMessageJob($antwort->id))->handle(app(ChannelManager::class));

        $this->assertSame('wamid.E', $antwort->fresh()->external_message_id);
    }

    /** Fall 8: Ein Anhang stoesst den Datei-Abruf an. */
    public function test_anhang_stoesst_den_dateiabruf_an(): void
    {
        Queue::fake();
        $konto = $this->konto();

        app(ConversationEngine::class)->handleInbound(
            new InboundMessage(
                externalUserId: '491701234567', externalMessageId: 'wamid.M',
                text: 'Mein Zaehler', type: 'image',
                attachments: [new InboundAttachment(type: 'image', externalMediaId: 'media-1', mimeType: 'image/jpeg')],
            ),
            $this->kanal(), $konto
        );

        Queue::assertPushed(FetchInboundMediaJob::class);
    }

    /** Fall 9: Die Datei landet auf der PRIVATEN Disk unter dem Kunden. */
    public function test_mediendatei_wird_privat_gespeichert(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/media-1' => Http::response(['url' => 'https://lookaside.test/datei', 'mime_type' => 'image/jpeg'], 200),
            'lookaside.test/*' => Http::response('BILDDATEN', 200),
        ]);

        $konto = $this->konto();
        $unterhaltung = $this->unterhaltung($konto);
        $nachricht = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'body' => 'x', 'from_staff' => false,
        ]);
        $anhang = CustomerMessageAttachment::create([
            'message_id' => $nachricht->id, 'file_name' => 'anhang', 'file_path' => '',
            'type' => 'image', 'mime_type' => 'image/jpeg', 'external_media_id' => 'media-1',
        ]);

        (new FetchInboundMediaJob($anhang->id))->handle(app(ChannelManager::class));

        $frisch = $anhang->fresh();
        $this->assertNotSame('', $frisch->file_path);
        $this->assertSame('local', $frisch->disk);
        $this->assertSame(9, $frisch->file_size);
        Storage::disk('local')->assertExists($frisch->file_path);
        // Ohne gelieferten Namen wird einer aus Typ und MIME gebildet.
        $this->assertSame('image.jpg', $frisch->file_name);
    }

    /** Fall 10: Ein bereits geholter Anhang wird nicht erneut geladen. */
    public function test_bereits_geholte_datei_wird_nicht_erneut_geladen(): void
    {
        Http::fake();
        $nachricht = CustomerMessage::create([
            'conversation_id' => $this->unterhaltung()->id, 'body' => 'x', 'from_staff' => false,
        ]);
        $anhang = CustomerMessageAttachment::create([
            'message_id' => $nachricht->id, 'file_name' => 'da.jpg',
            'file_path' => 'customers/1/messages/da.jpg', 'external_media_id' => 'media-1',
        ]);

        (new FetchInboundMediaJob($anhang->id))->handle(app(ChannelManager::class));

        Http::assertNothingSent();
    }

    /**
     * Fall 11: Ein fremder Dateiname darf nie zum Pfad werden - sonst
     * schriebe ein Absender in fremde Ordner.
     */
    public function test_fremder_dateiname_wird_auf_den_reinen_namen_reduziert(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/media-2' => Http::response(['url' => 'https://lookaside.test/d', 'mime_type' => 'application/pdf'], 200),
            'lookaside.test/*' => Http::response('PDF', 200),
        ]);

        $nachricht = CustomerMessage::create([
            'conversation_id' => $this->unterhaltung()->id, 'body' => 'x', 'from_staff' => false,
        ]);
        $anhang = CustomerMessageAttachment::create([
            'message_id' => $nachricht->id, 'file_name' => '../../../etc/passwd.pdf',
            'file_path' => '', 'type' => 'document', 'external_media_id' => 'media-2',
        ]);

        (new FetchInboundMediaJob($anhang->id))->handle(app(ChannelManager::class));

        $frisch = $anhang->fresh();
        $this->assertSame('passwd.pdf', $frisch->file_name);
        $this->assertStringNotContainsString('..', $frisch->file_path);
    }

    /** Fall 12: Die Groessengrenze gilt auch fuer fremde Dateien. */
    public function test_zu_grosse_datei_wird_nicht_gespeichert(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/media-3' => Http::response(['url' => 'https://lookaside.test/gross', 'mime_type' => 'image/jpeg'], 200),
            'lookaside.test/*' => Http::response(str_repeat('X', 11 * 1024 * 1024), 200),
        ]);

        $nachricht = CustomerMessage::create([
            'conversation_id' => $this->unterhaltung()->id, 'body' => 'x', 'from_staff' => false,
        ]);
        $anhang = CustomerMessageAttachment::create([
            'message_id' => $nachricht->id, 'file_name' => 'gross.jpg', 'file_path' => '',
            'type' => 'image', 'external_media_id' => 'media-3',
        ]);

        (new FetchInboundMediaJob($anhang->id))->handle(app(ChannelManager::class));

        $this->assertSame('', $anhang->fresh()->file_path);
    }

    /** Fall 13: Das Fenster ist Plattformwissen - der Kern liefert nur die Tatsache. */
    public function test_der_kern_kennt_die_frist_nicht(): void
    {
        $unterhaltung = $this->unterhaltung();
        $this->assertNull($unterhaltung->lastInboundAt());

        $eingang = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id, 'body' => 'Frage', 'from_staff' => false,
        ]);
        $eingang->forceFill(['created_at' => Carbon::parse('2026-09-01 10:00:00')])->saveQuietly();

        $this->assertTrue(
            $unterhaltung->fresh()->lastInboundAt()->equalTo(Carbon::parse('2026-09-01 10:00:00'))
        );
        // Die Frist selbst steht im Adapter, nicht in der Unterhaltung.
        $this->assertSame(24, WhatsAppAdapter::SERVICE_WINDOW_HOURS);
    }
}
