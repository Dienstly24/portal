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
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\Dto\InboundMessage;
use App\Support\AiMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * COEXISTENCE: dieselbe Nummer wird auf ZWEI Wegen bedient - hier und in
 * der WhatsApp Business App auf dem Telefon. Die Plattform meldet uns
 * dann auch, was jemand dort getippt hat, ueber DENSELBEN Webhook wie
 * eine Kundennachricht.
 *
 * Ohne Unterscheidung waere die eigene Antwort eine Kundenfrage: sie
 * zaehlt als ungelesen, verschiebt die Zustaendigkeit und die KI
 * antwortet darauf. Deren Antwort erzeugt die naechste Meldung - die
 * Schleife laeuft beim KUNDEN aus, nicht in einem Protokoll.
 */
class CoexistenceEchoTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'app-secret-123';

    /** Unsere Geschaeftsnummer, wie Meta sie im Kopf der Nutzlast fuehrt. */
    private const EIGENE = '+49 170 9999999';

    private const KUNDE = '491701234567';

    /**
     * KI SCHARF schalten. Ohne das waere "das Echo stoesst die KI nicht
     * an" wertlos - sie waere ohnehin aus, und der Test wuerde auch mit
     * kaputtem Schutz gruen.
     */
    private function kiAn(): void
    {
        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set('ai_assistant_auto_reply', '1');
        Channel::where('key', 'whatsapp')->update(['ai_mode' => AiMode::AUTO_REPLY]);
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

    private function kunde(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
            'mobile' => '+'.self::KUNDE,
        ]);
    }

    /**
     * Nutzlast mit frei waehlbarem Feldnamen: `messages` fuer eine echte
     * Kundennachricht, `message_echoes` fuer die Meldung ueber unsere
     * eigene.
     *
     * @return array<string,mixed>
     */
    private function nutzlast(string $feld, array $eintrag): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['value' => [
                'metadata' => [
                    'phone_number_id' => '111222333',
                    'display_phone_number' => self::EIGENE,
                ],
                'contacts' => [['wa_id' => self::KUNDE, 'profile' => ['name' => 'Max Muster']]],
                $feld => [$eintrag],
            ]]]]],
        ];
    }

    /** @return array<string,mixed> */
    private function eintrag(string $von, string $id, string $text, ?string $an = null): array
    {
        $e = [
            'from' => $von, 'id' => $id, 'timestamp' => '1780000000',
            'type' => 'text', 'text' => ['body' => $text],
        ];
        if ($an !== null) {
            $e['to'] = $an;
        }

        return $e;
    }

    private function zustellen(array $payload): void
    {
        $roh = json_encode($payload);
        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', (string) $roh, self::SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], (string) $roh)->assertOk();
    }

    /**
     * Fall 1: DER KERNFALL. Was ein Mitarbeiter in der Business App
     * getippt hat, wird NIE eine Kundennachricht.
     */
    public function test_echo_der_business_app_ist_keine_kundennachricht(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->konto();
        $this->kunde();

        $this->zustellen($this->nutzlast('message_echoes', $this->eintrag(
            von: '491709999999', id: 'wamid.ECHO', text: 'Antwort vom Telefon', an: self::KUNDE
        )));

        $nachricht = CustomerMessage::firstOrFail();
        $this->assertSame(CustomerMessage::DIRECTION_OUTGOING, $nachricht->direction);
        $this->assertTrue($nachricht->from_staff);
        $this->assertSame(CustomerMessage::SENDER_EMPLOYEE, $nachricht->sender_type);
    }

    /** Fall 2: Sie erzeugt keinen Ungelesen-Stand. */
    public function test_echo_erzeugt_keinen_ungelesen_stand(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->konto();
        $this->kunde();

        $this->zustellen($this->nutzlast('message_echoes', $this->eintrag(
            von: '491709999999', id: 'wamid.ECHO2', text: 'Gesendet', an: self::KUNDE
        )));

        $this->assertNotNull(CustomerMessage::firstOrFail()->read_at);
        $this->assertSame(0, CustomerMessage::fromCustomer()->unread()->count());
    }

    /** Fall 3: Sie stoesst die KI NICHT an - hier bricht die Schleife. */
    public function test_echo_stoesst_die_ki_nicht_an(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->kiAn();
        $this->konto();
        $this->kunde();

        $this->zustellen($this->nutzlast('message_echoes', $this->eintrag(
            von: '491709999999', id: 'wamid.ECHO3', text: 'Gesendet', an: self::KUNDE
        )));

        Queue::assertNotPushed(AnswerCustomerMessageJob::class);
    }

    /** Fall 4: Sie aendert die Zustaendigkeit nicht. */
    public function test_echo_aendert_die_zustaendigkeit_nicht(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $konto = $this->konto();
        $kunde = $this->kunde();
        $betreuer = User::factory()->create(['role' => 'employee']);
        $kunde->betreuer()->attach($betreuer->id, ['is_primary' => true]);

        // Unterhaltung existiert bereits und ist NICHT zugewiesen.
        $unterhaltung = Conversation::create([
            'channel_id' => Channel::where('key', 'whatsapp')->firstOrFail()->id,
            'channel_account_id' => $konto->id,
            'customer_id' => $kunde->id,
            'external_user_id' => self::KUNDE,
            'status' => Conversation::STATUS_OPEN,
        ]);

        $this->zustellen($this->nutzlast('message_echoes', $this->eintrag(
            von: '491709999999', id: 'wamid.ECHO4', text: 'Gesendet', an: self::KUNDE
        )));

        $this->assertNull($unterhaltung->fresh()->assigned_employee_id);
    }

    /**
     * Fall 5: Der Echo-Schutz haengt NICHT an einem Feldnamen. Steht als
     * Absender unsere eigene Nummer, ist es unsere Nachricht - auch wenn
     * die Plattform sie unter `messages` meldet (so hat Meta die
     * Coexistence-Echos schon anders benannt).
     */
    public function test_eigene_nummer_als_absender_zaehlt_auch_unter_messages(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->kiAn();
        $this->konto();
        $this->kunde();

        $this->zustellen($this->nutzlast('messages', $this->eintrag(
            von: '491709999999', id: 'wamid.ECHO5', text: 'Vom Telefon', an: self::KUNDE
        )));

        $this->assertSame(
            CustomerMessage::DIRECTION_OUTGOING,
            CustomerMessage::firstOrFail()->direction
        );
        Queue::assertNotPushed(AnswerCustomerMessageJob::class);
    }

    /**
     * Fall 6: Die Schreibweise darf nicht entscheiden. Im Kopf steht die
     * Nummer mit Plus und Leerzeichen, beim Absender als reine Ziffern -
     * ein Zeichenvergleich waere immer ungleich und der Schutz lautlos
     * wirkungslos.
     */
    public function test_schreibweise_der_nummer_bricht_den_schutz_nicht(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->konto();
        $this->kunde();

        $this->zustellen($this->nutzlast('messages', $this->eintrag(
            von: '+49 170 999 99 99', id: 'wamid.ECHO6', text: 'Vom Telefon', an: self::KUNDE
        )));

        $this->assertSame(
            CustomerMessage::DIRECTION_OUTGOING,
            CustomerMessage::firstOrFail()->direction
        );
    }

    /**
     * Fall 7: Die Unterhaltung ist die mit dem KUNDEN - nicht eine mit
     * uns selbst. Sonst laege die eigene Antwort in einem zweiten,
     * sinnlosen Strang.
     */
    public function test_echo_landet_in_der_unterhaltung_mit_dem_kunden(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->konto();
        $kunde = $this->kunde();

        $this->zustellen($this->nutzlast('message_echoes', $this->eintrag(
            von: '491709999999', id: 'wamid.ECHO7', text: 'Gesendet', an: self::KUNDE
        )));

        $this->assertSame(1, Conversation::count());
        $unterhaltung = Conversation::firstOrFail();
        $this->assertSame(self::KUNDE, $unterhaltung->external_user_id);
        $this->assertSame((string) $kunde->id, (string) $unterhaltung->customer_id);
    }

    /** Fall 8: Ein Echo wird NIE erneut an die Plattform geschickt. */
    public function test_echo_wird_nicht_zurueckgesendet(): void
    {
        Http::fake();
        $this->konto();
        $this->kunde();

        $this->zustellen($this->nutzlast('message_echoes', $this->eintrag(
            von: '491709999999', id: 'wamid.ECHO8', text: 'Gesendet', an: self::KUNDE
        )));

        Http::assertNothingSent();
    }

    /** Fall 9: Ein Echo holt eine GESCHLOSSENE Unterhaltung nicht zurueck. */
    public function test_echo_oeffnet_eine_geschlossene_unterhaltung_nicht(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $konto = $this->konto();
        $kunde = $this->kunde();
        $unterhaltung = Conversation::create([
            'channel_id' => Channel::where('key', 'whatsapp')->firstOrFail()->id,
            'channel_account_id' => $konto->id,
            'customer_id' => $kunde->id,
            'external_user_id' => self::KUNDE,
            'status' => Conversation::STATUS_CLOSED,
            'closed_at' => now(),
        ]);

        $this->zustellen($this->nutzlast('message_echoes', $this->eintrag(
            von: '491709999999', id: 'wamid.ECHO9', text: 'Schlusswort', an: self::KUNDE
        )));

        $this->assertSame(Conversation::STATUS_CLOSED, $unterhaltung->fresh()->status);
    }

    /**
     * Fall 10: Die ECHTE Kundennachricht bleibt unveraendert - der Schutz
     * darf nicht die Gegenrichtung mitnehmen.
     */
    public function test_echte_kundennachricht_bleibt_eingehend(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->konto();
        $kunde = $this->kunde();
        $betreuer = User::factory()->create(['role' => 'employee']);
        $kunde->betreuer()->attach($betreuer->id, ['is_primary' => true]);

        $this->zustellen($this->nutzlast('messages', $this->eintrag(
            von: self::KUNDE, id: 'wamid.ECHT', text: 'Ich habe eine Frage'
        )));

        $nachricht = CustomerMessage::firstOrFail();
        $this->assertSame(CustomerMessage::DIRECTION_INCOMING, $nachricht->direction);
        $this->assertNull($nachricht->read_at);
        $this->assertSame(
            $betreuer->id,
            Conversation::firstOrFail()->assigned_employee_id
        );
    }

    /** Fall 11: Ein doppelt zugestelltes Echo erzeugt keine zweite Zeile. */
    public function test_doppeltes_echo_erzeugt_nur_eine_nachricht(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $this->konto();
        $this->kunde();
        $nutzlast = $this->nutzlast('message_echoes', $this->eintrag(
            von: '491709999999', id: 'wamid.ECHO11', text: 'Gesendet', an: self::KUNDE
        ));

        $this->zustellen($nutzlast);
        $this->zustellen($nutzlast);

        $this->assertSame(1, CustomerMessage::count());
    }

    /**
     * Fall 12: Der KERN kennt den Fall allgemein, nicht als
     * Plattform-Eigenheit. Ein Adapter meldet die Tatsache; die Regel
     * gilt fuer jeden kuenftigen Kanal mit zwei Bedienwegen.
     */
    public function test_der_kern_behandelt_den_fall_kanalunabhaengig(): void
    {
        Queue::fake([AnswerCustomerMessageJob::class]);
        $konto = $this->konto();
        $kunde = $this->kunde();

        app(ConversationEngine::class)->handleInbound(
            new InboundMessage(
                externalUserId: self::KUNDE,
                externalMessageId: 'wamid.KERN',
                text: 'Von uns',
                fromBusiness: true,
            ),
            Channel::where('key', 'whatsapp')->firstOrFail(),
            $konto
        );

        $this->assertSame(
            CustomerMessage::DIRECTION_OUTGOING,
            CustomerMessage::firstOrFail()->direction
        );
        Queue::assertNotPushed(AnswerCustomerMessageJob::class);
    }
}
