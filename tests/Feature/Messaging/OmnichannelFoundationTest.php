<?php

namespace Tests\Feature\Messaging;

use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\ChannelEvent;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\User;
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\CustomerResolver;
use App\Services\Messaging\Dto\InboundAttachment;
use App\Services\Messaging\Dto\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Omnichannel Phase B - Fundament.
 *
 * Diese Faelle sichern genau die Eigenschaften, ohne die der Umbau
 * nicht in Betrieb gehen darf: der Bestand geht nicht verloren, eine
 * doppelte Zustellung erzeugt keine zweite Nachricht, und eine
 * unklare Zuordnung wird nie geraten.
 */
class OmnichannelFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(array $attrs = []): Customer
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'email' => $attrs['email'] ?? 'kunde'.uniqid().'@example.de',
        ]);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => '26'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
        ], $attrs));
    }

    private function portalKanal(): Channel
    {
        return Channel::where('key', Channel::PORTAL)->firstOrFail();
    }

    private function nachricht(string $absender, array $attrs = []): InboundMessage
    {
        return new InboundMessage(
            externalUserId: $absender,
            externalMessageId: $attrs['msg'] ?? 'ext-'.uniqid(),
            text: $attrs['text'] ?? 'Hallo, ich habe eine Frage.',
            senderPhone: $attrs['phone'] ?? null,
            senderEmail: $attrs['email'] ?? null,
            attachments: $attrs['attachments'] ?? [],
        );
    }

    /** Fall 1: Die Kanaele des Bestands existieren nach der Migration. */
    public function test_die_kanaele_des_bestands_sind_angelegt(): void
    {
        $this->assertNotNull(Channel::where('key', Channel::PORTAL)->first());
        $this->assertNotNull(Channel::where('key', Channel::INTERNAL)->first());

        // Der interne Chat hat bewusst KEINE Kunden - er darf strukturell
        // nicht ins Kundenportal auslieferbar sein (Spec Teil 8).
        $this->assertFalse(Channel::where('key', Channel::INTERNAL)->first()->supports('supportsCustomers'));
        $this->assertTrue($this->portalKanal()->supports('supportsCustomers'));
    }

    /** Fall 2: Eine unbekannte Faehigkeit gilt als NICHT vorhanden. */
    public function test_unbekannte_faehigkeit_wird_nie_optimistisch_angenommen(): void
    {
        $this->assertFalse($this->portalKanal()->supports('supportsTelepathie'));
    }

    /** Fall 3: Eingehende Nachricht erzeugt Unterhaltung und Nachricht. */
    public function test_eingehende_nachricht_erzeugt_unterhaltung_und_nachricht(): void
    {
        $kunde = $this->kunde(['phone' => '0170 1234567']);
        $engine = app(ConversationEngine::class);

        $message = $engine->handleInbound(
            $this->nachricht('491701234567', ['phone' => '+49 170 1234567']),
            $this->portalKanal()
        );

        $this->assertNotNull($message);
        $this->assertSame(CustomerMessage::DIRECTION_INCOMING, $message->direction);
        // Die alte Lesart muss mitlaufen - sonst sieht der bestehende
        // Portal-Chat die Nachricht gar nicht.
        $this->assertFalse($message->from_staff);
        $this->assertSame((string) $kunde->id, (string) $message->customer_id);

        $unterhaltung = Conversation::firstOrFail();
        $this->assertSame((string) $kunde->id, (string) $unterhaltung->customer_id);
        $this->assertSame(Conversation::STATUS_OPEN, $unterhaltung->status);
        $this->assertNotNull($unterhaltung->last_message_at);
    }

    /**
     * Fall 4: DIE Eigenschaft, ohne die kein Webhook angebunden werden
     * darf - dieselbe Plattform-Nachricht ergibt keine zweite Zeile.
     */
    public function test_dieselbe_nachricht_wird_nie_zweimal_gespeichert(): void
    {
        $this->kunde(['phone' => '0170 1234567']);
        $engine = app(ConversationEngine::class);
        $eingang = $this->nachricht('491701234567', ['msg' => 'wamid.GLEICH', 'phone' => '0170 1234567']);

        $this->assertNotNull($engine->handleInbound($eingang, $this->portalKanal()));
        $this->assertNull($engine->handleInbound($eingang, $this->portalKanal()));

        $this->assertSame(1, CustomerMessage::count());
        $this->assertSame(1, Conversation::count());
    }

    /** Fall 5: Auch das Ereignis-Register beansprucht nur einmal. */
    public function test_ereignis_register_laesst_ein_ereignis_nur_einmal_durch(): void
    {
        $this->assertTrue(ChannelEvent::claim(null, 'evt-1'));
        $this->assertFalse(ChannelEvent::claim(null, 'evt-1'));
        $this->assertTrue(ChannelEvent::claim(null, 'evt-2'));
    }

    /**
     * Fall 6: NIE RATEN. Passen zwei Kunden auf dieselbe Nummer, wird
     * keiner genommen - eine falsch zugeordnete Nachricht zeigt einem
     * Kunden die Unterhaltung eines anderen.
     */
    public function test_bei_zwei_treffern_wird_kein_kunde_zugeordnet(): void
    {
        $this->kunde(['phone' => '0170 1234567']);
        $this->kunde(['phone' => '+49 170 1234567']);

        $kunde = app(CustomerResolver::class)->resolve(
            $this->nachricht('491701234567', ['phone' => '01701234567']),
            $this->portalKanal(),
            null
        );

        $this->assertNull($kunde);
    }

    /**
     * Fall 7: Ohne Kundenakte geht die Nachricht trotzdem NICHT
     * verloren - eine verworfene Nachricht bekaeme niemand zurueck.
     */
    public function test_unbekannter_absender_verliert_seine_nachricht_nicht(): void
    {
        $message = app(ConversationEngine::class)->handleInbound(
            $this->nachricht('49999999999'),
            $this->portalKanal()
        );

        $this->assertNotNull($message);
        $this->assertNull($message->customer_id);
        $this->assertNull(Conversation::firstOrFail()->customer_id);
    }

    /** Fall 8: Ein leeres Ereignis erzeugt keine leere Blase im Chat. */
    public function test_leeres_ereignis_erzeugt_keine_nachricht(): void
    {
        $leer = new InboundMessage(externalUserId: '4917', externalMessageId: 'x', text: '  ');

        $this->assertNull(app(ConversationEngine::class)->handleInbound($leer, $this->portalKanal()));
        $this->assertSame(0, CustomerMessage::count());
    }

    /**
     * Fall 9: Die Kundenakte kann sich NACHTRAEGLICH klaeren. Dann wird
     * ergaenzt - eine bestehende Zuordnung aber nie ueberschrieben.
     */
    public function test_kundenakte_wird_nachtraeglich_ergaenzt_aber_nie_ueberschrieben(): void
    {
        $engine = app(ConversationEngine::class);
        $engine->handleInbound($this->nachricht('4917000', ['msg' => 'a']), $this->portalKanal());
        $this->assertNull(Conversation::firstOrFail()->customer_id);

        $kunde = $this->kunde(['phone' => '0170 0000123']);
        $engine->handleInbound(
            $this->nachricht('4917000', ['msg' => 'b', 'phone' => '0170 0000123']),
            $this->portalKanal()
        );

        $this->assertSame((string) $kunde->id, (string) Conversation::firstOrFail()->customer_id);
        $this->assertSame(1, Conversation::count());
    }

    /** Fall 10: Anhaenge entstehen als Datensatz, auch bevor die Datei da ist. */
    public function test_anhang_entsteht_als_datensatz_vor_dem_download(): void
    {
        $message = app(ConversationEngine::class)->handleInbound(
            $this->nachricht('4917111', ['attachments' => [
                new InboundAttachment(type: 'image', externalMediaId: 'media-1', mimeType: 'image/jpeg'),
            ]]),
            $this->portalKanal()
        );

        $anhang = $message->attachments()->firstOrFail();
        $this->assertSame('media-1', $anhang->external_media_id);
        $this->assertSame('image/jpeg', $anhang->mime_type);
    }

    /**
     * Fall 11: Ein Status geht nur VORWAERTS. Statusmeldungen treffen
     * regelmaessig in falscher Reihenfolge ein - eine gelesene Nachricht
     * darf nie wieder auf "zugestellt" zurueckfallen.
     */
    public function test_status_faellt_nie_zurueck(): void
    {
        $kunde = $this->kunde();
        $message = CustomerMessage::create([
            'customer_id' => $kunde->id,
            'body' => 'Antwort',
            'from_staff' => true,
            'external_message_id' => 'wamid.OUT',
            'status' => CustomerMessage::STATUS_SENT,
        ]);
        $engine = app(ConversationEngine::class);

        $this->assertTrue($engine->handleStatus('wamid.OUT', CustomerMessage::STATUS_READ));
        // Die spaeter eintreffende, AELTERE Meldung darf nichts kippen.
        $this->assertFalse($engine->handleStatus('wamid.OUT', CustomerMessage::STATUS_DELIVERED));

        $this->assertSame(CustomerMessage::STATUS_READ, $message->fresh()->status);
    }

    /** Fall 12: Ein Fehlschlag ist immer die juengere Wahrheit. */
    public function test_fehlschlag_setzt_sich_gegen_jeden_stand_durch(): void
    {
        $kunde = $this->kunde();
        CustomerMessage::create([
            'customer_id' => $kunde->id, 'body' => 'x', 'from_staff' => true,
            'external_message_id' => 'wamid.F', 'status' => CustomerMessage::STATUS_READ,
        ]);

        $this->assertTrue(app(ConversationEngine::class)
            ->handleStatus('wamid.F', CustomerMessage::STATUS_FAILED, 'Empfaenger unbekannt'));

        $message = CustomerMessage::where('external_message_id', 'wamid.F')->firstOrFail();
        $this->assertSame(CustomerMessage::STATUS_FAILED, $message->status);
        $this->assertSame('Empfaenger unbekannt', $message->failure_reason);
    }

    /**
     * Fall 13: SICHERHEIT. Zugangsdaten sind verschluesselt gespeichert
     * und verlassen das Modell nicht ueber toArray()/toJson() - sonst
     * genuegt ein einziges versehentliches `return $account;` in einem
     * Controller, um ein Token auszuliefern.
     */
    public function test_zugangsdaten_sind_verschluesselt_und_nie_in_der_ausgabe(): void
    {
        $konto = ChannelAccount::create([
            'channel_id' => $this->portalKanal()->id,
            'name' => 'Testkonto',
            'credentials' => ['access_token' => 'GEHEIM-TOKEN-123'],
        ]);

        $roh = (string) \DB::table('channel_accounts')->where('id', $konto->id)->value('credentials');
        $this->assertStringNotContainsString('GEHEIM-TOKEN-123', $roh);

        $this->assertStringNotContainsString('GEHEIM-TOKEN-123', json_encode($konto->fresh()->toArray()));
        $this->assertArrayNotHasKey('credentials', $konto->fresh()->toArray());

        // Ueber den ausdruecklichen Weg bleibt der Wert natuerlich lesbar.
        $this->assertSame('GEHEIM-TOKEN-123', $konto->fresh()->credential('access_token'));
    }

    /** Fall 14: Ein Zugang ohne hinterlegten Ablauf haelt den Betrieb nicht an. */
    public function test_zugang_ohne_ablauf_gilt_als_gueltig(): void
    {
        $konto = ChannelAccount::create([
            'channel_id' => $this->portalKanal()->id, 'name' => 'Dauer-Token',
            'credentials' => ['token' => 'x'],
        ]);

        $this->assertFalse($konto->tokenExpired());

        $konto->update(['token_expires_at' => now()->subDay()]);
        $this->assertTrue($konto->fresh()->tokenExpired());
    }
}
