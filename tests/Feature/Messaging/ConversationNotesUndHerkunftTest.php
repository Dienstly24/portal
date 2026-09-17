<?php

namespace Tests\Feature\Messaging;

use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Customer;
use App\Models\CustomerChannelIdentity;
use App\Models\CustomerMessage;
use App\Models\CustomerMessageAttachment;
use App\Models\User;
use App\Services\Messaging\CustomerResolver;
use App\Services\Messaging\Dto\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 1 der Unified Conversation Platform (17.09.2026) - die drei
 * Luecken aus der Bestandsaufnahme:
 *
 *  1. Der Anhang einer Unterhaltung OHNE Kundenakte war fuer genau die
 *     Mitarbeiter gesperrt, die sie zuordnen sollen.
 *  2. Einer Kundenzuordnung sah man nicht an, ob ein Mensch sie
 *     getroffen hat oder eine Telefonnummer zufaellig passte.
 *  3. Es gab keinen Ort fuer eine Bemerkung zu EINEM Vorgang.
 */
class ConversationNotesUndHerkunftTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(string $name = 'Max Muster', array $felder = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => '26'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
        ], $felder));
    }

    private function kanal(): Channel
    {
        $kanal = Channel::where('key', 'whatsapp')->firstOrFail();
        $kanal->update(['is_active' => true]);

        return $kanal;
    }

    private function konto(): ChannelAccount
    {
        return ChannelAccount::create([
            'channel_id' => $this->kanal()->id,
            'name' => 'Geschaeftsnummer',
            'is_active' => true,
            'credentials' => ['access_token' => 'T', 'phone_number_id' => '111'],
        ]);
    }

    private function unterhaltung(?Customer $kunde = null, string $extern = '491701234567'): Conversation
    {
        return Conversation::create([
            'channel_id' => $this->kanal()->id,
            'customer_id' => $kunde?->id,
            'external_user_id' => $extern,
            'status' => Conversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);
    }

    private function mitarbeiterOhnePortfolio(): User
    {
        return User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
    }

    // ---------------------------------------------------------------
    // 1. Anhaenge einer Unterhaltung ohne Kundenakte
    // ---------------------------------------------------------------

    /**
     * DER GEMELDETE FEHLER: der Mitarbeiter sieht die Unterhaltung im
     * Postfach (so gewollt - sie gehoert niemandem), bekommt aber fuer
     * jeden Anhang darin eine 403. Also ausgerechnet die Datei, mit der
     * er die Nachricht zuordnen koennte.
     */
    public function test_1_anhang_einer_unterhaltung_ohne_kundenakte_ist_fuer_jeden_mitarbeiter_zugaenglich(): void
    {
        Storage::fake('local');
        $mitarbeiter = $this->mitarbeiterOhnePortfolio();
        $unterhaltung = $this->unterhaltung(null);

        $anhang = $this->anhangIn($unterhaltung);

        $this->actingAs($mitarbeiter)
            ->get(route('admin.messages.attachment', $anhang->id))
            ->assertOk();
    }

    /**
     * Die Gegenprobe - und der eigentliche Grund, warum die Regel aus
     * DERSELBEN Quelle kommen muss wie die Liste: ein fremder Kunde
     * bleibt fremd. Waere die Korrektur oben ein blosses "erlaube alles
     * ohne Kunden" gewesen, muesste man das hier einzeln nachziehen.
     */
    public function test_2_anhang_eines_fremden_kunden_bleibt_gesperrt(): void
    {
        Storage::fake('local');
        $mitarbeiter = $this->mitarbeiterOhnePortfolio();
        $fremder = $this->kunde('Fremd Kunde');
        $unterhaltung = $this->unterhaltung($fremder);

        $anhang = $this->anhangIn($unterhaltung, $fremder);

        $this->actingAs($mitarbeiter)
            ->get(route('admin.messages.attachment', $anhang->id))
            ->assertForbidden();
    }

    /** Eigener Kunde: unveraendert erlaubt. */
    public function test_3_anhang_des_eigenen_kunden_bleibt_erlaubt(): void
    {
        Storage::fake('local');
        $mitarbeiter = $this->mitarbeiterOhnePortfolio();
        $meiner = $this->kunde('Mein Kunde');
        $mitarbeiter->assignedCustomers()->attach((string) $meiner->id);

        $anhang = $this->anhangIn($this->unterhaltung($meiner), $meiner);

        $this->actingAs($mitarbeiter)
            ->get(route('admin.messages.attachment', $anhang->id))
            ->assertOk();
    }

    /**
     * Eine Nachricht aus der Zeit VOR den Unterhaltungen haengt nur am
     * Kunden. Der zweite Weg der Regel darf dabei nicht verloren gehen -
     * sonst waere der gesamte Altbestand an Anhaengen gesperrt.
     */
    public function test_4_altbestand_ohne_unterhaltung_folgt_weiterhin_dem_portfolio(): void
    {
        Storage::fake('local');
        $mitarbeiter = $this->mitarbeiterOhnePortfolio();
        $meiner = $this->kunde('Alt Bestand');
        $mitarbeiter->assignedCustomers()->attach((string) $meiner->id);

        $nachricht = CustomerMessage::create([
            'customer_id' => $meiner->id,
            'body' => 'Alte Nachricht ohne Unterhaltung',
            'from_staff' => false,
        ]);

        $anhang = $this->dateiAn($nachricht);

        $this->actingAs($mitarbeiter)
            ->get(route('admin.messages.attachment', $anhang->id))
            ->assertOk();
    }

    private function anhangIn(Conversation $u, ?Customer $kunde = null): CustomerMessageAttachment
    {
        $nachricht = CustomerMessage::create([
            'conversation_id' => $u->id,
            'customer_id' => $kunde?->id,
            'body' => 'Anbei mein Versicherungsschein',
            'from_staff' => false,
        ]);

        return $this->dateiAn($nachricht);
    }

    private function dateiAn(CustomerMessage $nachricht): CustomerMessageAttachment
    {
        $pfad = 'customers/chat/schein.pdf';
        Storage::disk('local')->put($pfad, '%PDF-1.4 test');

        return CustomerMessageAttachment::create([
            'message_id' => $nachricht->id,
            'file_name' => 'schein.pdf',
            'file_path' => $pfad,
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'file_size' => 13,
        ]);
    }

    // ---------------------------------------------------------------
    // 2. Herkunft der Kundenzuordnung
    // ---------------------------------------------------------------

    /**
     * Eine ueber die Telefonnummer gefundene Akte ist ein INDIZ. Die
     * Zuordnung wird trotzdem gespeichert und benutzt - sie ist nur
     * nicht geprueft, und das muss man ihr ansehen.
     */
    public function test_5_zuordnung_ueber_die_telefonnummer_gilt_als_ungeprueft(): void
    {
        $kunde = $this->kunde('Telefon Kunde', ['phone' => '0170 123 45 67']);
        $kanal = $this->kanal();
        $konto = $this->konto();

        $treffer = app(CustomerResolver::class)->resolve(
            new InboundMessage(
                externalUserId: '491701234567',
                externalMessageId: 'wamid.1',
                text: 'Hallo',
                senderPhone: '491701234567',
            ),
            $kanal,
            $konto,
        );

        $this->assertNotNull($treffer, 'Die Zuordnung soll funktionieren - nur eben unbestaetigt.');
        $this->assertSame((string) $kunde->id, (string) $treffer->id);

        $identitaet = CustomerChannelIdentity::where('external_user_id', '491701234567')->firstOrFail();
        $this->assertSame(CustomerChannelIdentity::METHOD_PHONE, $identitaet->match_method);
        $this->assertTrue($identitaet->needsVerification());
    }

    /**
     * Ordnet ein MENSCH zu, gilt die Zuordnung sofort. Ihn danach noch
     * um eine Bestaetigung zu bitten waere sinnlos - er ist die
     * Bestaetigung.
     */
    public function test_6_zuordnung_durch_einen_mitarbeiter_ist_sofort_bestaetigt(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kunde = $this->kunde('Manuell Kunde');
        $unterhaltung = $this->unterhaltung(null, '4917600000');
        $this->konto();

        $this->actingAs($admin)
            ->post(route('admin.postfach.link_customer', $unterhaltung->id), [
                'customer_id' => $kunde->id,
            ])->assertRedirect();

        $identitaet = CustomerChannelIdentity::where('external_user_id', '4917600000')->firstOrFail();
        $this->assertSame(CustomerChannelIdentity::METHOD_MANUAL, $identitaet->match_method);
        $this->assertFalse($identitaet->needsVerification());
        $this->assertSame($admin->id, $identitaet->verified_by);
    }

    /**
     * DIE WICHTIGSTE REGEL DIESES TEILS: eine bestaetigte Zuordnung darf
     * von der naechsten automatischen Erkennung nie wieder auf "Indiz"
     * zurueckfallen. Sonst waere die Bestaetigung bei der naechsten
     * Nachricht weg und niemand kaeme je durch die Liste.
     */
    public function test_7_eine_bestaetigte_zuordnung_faellt_nie_wieder_zurueck(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kunde = $this->kunde('Bestaetigt Kunde', ['phone' => '0170 999 88 77']);
        $kanal = $this->kanal();
        $konto = $this->konto();

        $identitaet = CustomerChannelIdentity::create([
            'customer_id' => $kunde->id,
            'channel_id' => $kanal->id,
            'channel_account_id' => $konto->id,
            'external_user_id' => '491709998877',
            'match_method' => CustomerChannelIdentity::METHOD_MANUAL,
        ]);
        $identitaet->confirm($admin);

        // Eine weitere Nachricht derselben Nummer laeuft ueber Stufe 1.
        app(CustomerResolver::class)->resolve(
            new InboundMessage(
                externalUserId: '491709998877',
                externalMessageId: 'wamid.2',
                text: 'Noch eine Frage',
                senderPhone: '491709998877',
            ),
            $kanal,
            $konto,
        );

        $identitaet->refresh();
        $this->assertSame(CustomerChannelIdentity::METHOD_MANUAL, $identitaet->match_method);
        $this->assertNotNull($identitaet->verified_at);
        $this->assertFalse($identitaet->needsVerification());
    }

    /**
     * Der Altbestand traegt keine Herkunft - er wird deshalb NICHT
     * rueckwirkend als ungeprueft gemeldet. Eine Warnung an jedem
     * Datensatz haette die echten Faelle darin untergehen lassen.
     */
    public function test_8_altbestand_ohne_vermerkte_herkunft_gilt_nicht_als_ungeprueft(): void
    {
        $identitaet = CustomerChannelIdentity::create([
            'customer_id' => $this->kunde('Alt Identitaet')->id,
            'channel_id' => $this->kanal()->id,
            'external_user_id' => '4915100000',
        ]);

        $this->assertNull($identitaet->match_method);
        $this->assertFalse($identitaet->needsVerification());
        $this->assertSame('Herkunft nicht vermerkt', $identitaet->methodLabel());
    }

    /** Bestaetigen ist eine Mitarbeiter-Aktion und wirkt sofort. */
    public function test_9_der_mitarbeiter_kann_die_zuordnung_bestaetigen(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kunde = $this->kunde('Zu Bestaetigen');
        $kanal = $this->kanal();
        $konto = $this->konto();

        $unterhaltung = Conversation::create([
            'channel_id' => $kanal->id,
            'channel_account_id' => $konto->id,
            'customer_id' => $kunde->id,
            'external_user_id' => '491700001111',
            'status' => Conversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);

        $identitaet = CustomerChannelIdentity::create([
            'customer_id' => $kunde->id,
            'channel_id' => $kanal->id,
            'channel_account_id' => $konto->id,
            'external_user_id' => '491700001111',
            'match_method' => CustomerChannelIdentity::METHOD_PHONE,
        ]);

        $this->assertTrue($identitaet->needsVerification());

        $this->actingAs($admin)
            ->post(route('admin.postfach.confirm_identity', $unterhaltung->id))
            ->assertRedirect();

        $identitaet->refresh();
        $this->assertFalse($identitaet->needsVerification());
        $this->assertSame($admin->id, $identitaet->verified_by);
    }

    /** Der Hinweis steht auch wirklich auf der Seite. */
    public function test_10_die_unbestaetigte_zuordnung_wird_im_postfach_angezeigt(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kunde = $this->kunde('Anzeige Kunde');
        $kanal = $this->kanal();
        $konto = $this->konto();

        $unterhaltung = Conversation::create([
            'channel_id' => $kanal->id,
            'channel_account_id' => $konto->id,
            'customer_id' => $kunde->id,
            'external_user_id' => '491700002222',
            'status' => Conversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);

        CustomerChannelIdentity::create([
            'customer_id' => $kunde->id,
            'channel_id' => $kanal->id,
            'channel_account_id' => $konto->id,
            'external_user_id' => '491700002222',
            'match_method' => CustomerChannelIdentity::METHOD_PHONE,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.postfach', ['unterhaltung' => $unterhaltung->id]))
            ->assertOk()
            ->assertSee('Zuordnung noch nicht bestätigt');
    }

    // ---------------------------------------------------------------
    // 3. Interne Notizen
    // ---------------------------------------------------------------

    public function test_11_mitarbeiter_kann_eine_interne_notiz_anlegen(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $unterhaltung = $this->unterhaltung($this->kunde('Notiz Kunde'));

        $this->actingAs($admin)
            ->post(route('admin.postfach.note', $unterhaltung->id), [
                'body' => 'Wartet auf die Bestaetigung der Werkstatt.',
            ])->assertRedirect();

        $notiz = ConversationNote::where('conversation_id', $unterhaltung->id)->firstOrFail();
        $this->assertSame('Wartet auf die Bestaetigung der Werkstatt.', $notiz->body);
        $this->assertSame($admin->id, $notiz->user_id);
    }

    /**
     * DIE VORAUSSETZUNG DES GANZEN BAUSTEINS. Sie ist hier
     * STRUKTURELL erfuellt: die Notiz liegt in einer Tabelle, die der
     * Nachrichtenstrom nicht kennt. Ein Flag an `customer_messages`
     * waere nur so lange sicher, wie jede Abfrage daran denkt.
     */
    public function test_12_eine_notiz_ist_keine_nachricht_und_erreicht_den_kunden_nie(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kunde = $this->kunde('Portal Kunde');
        $unterhaltung = $this->unterhaltung($kunde);

        $this->actingAs($admin)->post(route('admin.postfach.note', $unterhaltung->id), [
            'body' => 'INTERNER-VERMERK-GEHEIM',
        ])->assertRedirect();

        // Kein Nachrichtendatensatz - und damit nichts, was ein Versand,
        // der Portal-Chat, die Suche oder die KI je zu sehen bekaeme.
        $this->assertSame(0, CustomerMessage::where('conversation_id', $unterhaltung->id)->count());
        $this->assertSame(0, $unterhaltung->messages()->count());
        $this->assertSame(1, $unterhaltung->notes()->count());

        // Und im Kundenportal steht sie auch nicht.
        $this->actingAs($kunde->user)
            ->get(route('portal.messages'))
            ->assertDontSee('INTERNER-VERMERK-GEHEIM');
    }

    /**
     * Ohne ausdrueckliche Wahl gilt die STRENGERE Stufe. Eine Notiz, bei
     * der niemand ueber die Sichtbarkeit nachgedacht hat, bleibt
     * drinnen.
     */
    public function test_13_ohne_angabe_ist_eine_notiz_nur_intern(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $unterhaltung = $this->unterhaltung($this->kunde('Vorgabe Kunde'));

        $this->actingAs($admin)->post(route('admin.postfach.note', $unterhaltung->id), [
            'body' => 'Ohne Angabe der Sichtbarkeit.',
        ])->assertRedirect();

        $this->assertSame(
            ConversationNote::VISIBILITY_INTERNAL,
            ConversationNote::where('conversation_id', $unterhaltung->id)->value('visibility')
        );
    }

    /** Eine Notiz gehoert zum Vorgang, nicht zum Kunden. */
    public function test_14_die_notiz_haengt_an_der_unterhaltung_nicht_am_kunden(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kunde = $this->kunde('Zwei Vorgaenge');
        $erste = $this->unterhaltung($kunde, '491700003333');
        $zweite = $this->unterhaltung($kunde, '491700004444');

        $this->actingAs($admin)->post(route('admin.postfach.note', $erste->id), [
            'body' => 'Nur zum ersten Vorgang.',
        ])->assertRedirect();

        $this->assertSame(1, $erste->notes()->count());
        $this->assertSame(0, $zweite->notes()->count());
    }

    /** Fremde Unterhaltung: keine Notiz. Dieselbe Grenze wie die Liste. */
    public function test_15_ohne_zugriff_auf_die_unterhaltung_keine_notiz(): void
    {
        $mitarbeiter = $this->mitarbeiterOhnePortfolio();
        $fremder = $this->kunde('Fremd Vorgang');
        $unterhaltung = $this->unterhaltung($fremder);

        $this->actingAs($mitarbeiter)
            ->post(route('admin.postfach.note', $unterhaltung->id), ['body' => 'Darf nicht'])
            ->assertNotFound();

        $this->assertSame(0, $unterhaltung->notes()->count());
    }

    /**
     * Unveraenderlich: das Modell hat kein `updated_at`. Eine Spalte,
     * die es nicht gibt, kann auch nicht still gepflegt werden.
     */
    public function test_16_eine_notiz_kennt_kein_updated_at(): void
    {
        $notiz = ConversationNote::create([
            'conversation_id' => $this->unterhaltung($this->kunde('Unveraenderlich'))->id,
            'user_id' => User::factory()->create(['role' => 'admin'])->id,
            'body' => 'Fest.',
        ]);

        $this->assertNull(ConversationNote::UPDATED_AT);
        $this->assertArrayNotHasKey('updated_at', $notiz->fresh()->getAttributes());
    }

    /** Die Notiz steht im Postfach - sichtbar getrennt vom Verlauf. */
    public function test_17_die_notiz_erscheint_im_postfach(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $unterhaltung = $this->unterhaltung($this->kunde('Sichtbar Kunde'));

        ConversationNote::create([
            'conversation_id' => $unterhaltung->id,
            'user_id' => $admin->id,
            'body' => 'Rueckruf am Montag vereinbart.',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.postfach', ['unterhaltung' => $unterhaltung->id]))
            ->assertOk()
            ->assertSee('Rueckruf am Montag vereinbart.')
            ->assertSee('Interne Notizen');
    }
}
