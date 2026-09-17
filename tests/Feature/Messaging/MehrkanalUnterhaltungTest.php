<?php

namespace Tests\Feature\Messaging;

use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\Customer;
use App\Models\CustomerChannelIdentity;
use App\Models\CustomerMessage;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Messaging\ChannelRoutingService;
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\Dto\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2: EINE Unterhaltung, MEHRERE Kanaele (Auftrag Abschnitte 2, 7, 14).
 *
 * Der Massstab dieser Datei ist nicht "es laesst sich zusammenfuehren",
 * sondern: es wird NIE geraten, jede Nachricht behaelt ihre Herkunft,
 * die Antwort geht dorthin, wo der Kunde wartet - und der Weg zurueck
 * bleibt offen.
 */
class MehrkanalUnterhaltungTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(string $name = 'Max Muster'): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
        ]);
    }

    private function kanal(string $key): Channel
    {
        $kanal = Channel::where('key', $key)->firstOrFail();
        $kanal->update(['is_active' => true]);

        return $kanal;
    }

    private function konto(Channel $kanal): ChannelAccount
    {
        return ChannelAccount::create([
            'channel_id' => $kanal->id,
            'name' => 'Konto '.$kanal->key,
            'is_active' => true,
            'credentials' => ['access_token' => 'T', 'phone_number_id' => '111'],
        ]);
    }

    private function engine(): ConversationEngine
    {
        return app(ConversationEngine::class);
    }

    private function eingang(
        Channel $kanal,
        string $extern,
        string $text = 'Hallo',
        ?ChannelAccount $konto = null,
        ?string $kennung = null,
    ): ?CustomerMessage {
        return $this->engine()->handleInbound(
            new InboundMessage(
                externalUserId: $extern,
                externalMessageId: $kennung ?: 'ext-'.uniqid(),
                text: $text,
            ),
            $kanal,
            $konto
        );
    }

    private function autoJoin(bool $an): void
    {
        SystemSetting::set(ChannelRoutingService::SETTING_AUTO_JOIN, $an ? '1' : '0');
    }

    // ---------------------------------------------------------------
    // Herkunft je Nachricht
    // ---------------------------------------------------------------

    /**
     * Die Grundlage von allem: OHNE den Kanal an der Nachricht waere
     * nach einer Zusammenfuehrung nicht mehr feststellbar, woher sie kam -
     * und der Rueckweg haette keine Grundlage.
     */
    public function test_1_jede_nachricht_traegt_ihren_kanal(): void
    {
        $kanal = $this->kanal('whatsapp');
        $konto = $this->konto($kanal);

        $nachricht = $this->eingang($kanal, '491701234567', 'Hallo', $konto);

        $this->assertNotNull($nachricht);
        $this->assertSame($kanal->id, $nachricht->channel_id);
        $this->assertSame($konto->id, $nachricht->channel_account_id);
    }

    /** Jede Unterhaltung traegt vom ersten Moment an ihren Kanal. */
    public function test_2_jede_unterhaltung_bekommt_ihren_kanal_eintrag(): void
    {
        $kanal = $this->kanal('portal');

        $unterhaltung = Conversation::create([
            'channel_id' => $kanal->id,
            'external_user_id' => 'kunde-1',
            'status' => Conversation::STATUS_OPEN,
        ]);

        $this->assertSame(1, $unterhaltung->channels()->count());
        $this->assertSame(
            ConversationChannel::JOIN_INITIAL,
            $unterhaltung->channels()->value('join_method')
        );
    }

    /**
     * Gesucht wird ueber die ZUGEHOERIGKEIT. Wer weiter nach
     * `conversations.channel_id` sucht, findet einen spaeter
     * dazugekommenen Kanal nie und legt bei JEDER Nachricht eine neue
     * Unterhaltung an.
     */
    public function test_3_eine_zweite_nachricht_findet_dieselbe_unterhaltung(): void
    {
        $kanal = $this->kanal('whatsapp');
        $konto = $this->konto($kanal);

        $this->eingang($kanal, '491701234567', 'Erste', $konto);
        $this->eingang($kanal, '491701234567', 'Zweite', $konto);

        $this->assertSame(1, Conversation::count());
        $this->assertSame(2, CustomerMessage::count());
    }

    // ---------------------------------------------------------------
    // Zusammenfuehren - und wann NICHT
    // ---------------------------------------------------------------

    /**
     * VOREINSTELLUNG AUS: nach dem Deployment verhaelt sich das System
     * exakt wie vorher. Eine Aenderung, die bestehende Unterhaltungen
     * anders fuehrt, schaltet sich nicht selbst scharf.
     */
    public function test_4_ohne_schalter_entsteht_wie_bisher_eine_zweite_unterhaltung(): void
    {
        $this->autoJoin(false);
        $kunde = $this->kunde();

        $wa = $this->kanal('whatsapp');
        $portal = $this->kanal('portal');
        $this->verknuepfe($kunde, $wa, '491701234567');
        $this->verknuepfe($kunde, $portal, 'portal-1');

        $this->eingang($wa, '491701234567', 'Über WhatsApp');
        $this->eingang($portal, 'portal-1', 'Im Portal');

        $this->assertSame(2, Conversation::count());
    }

    /** Mit Schalter: derselbe Kunde, ein neuer Weg, EIN Vorgang. */
    public function test_5_mit_schalter_wird_der_zweite_kanal_angeschlossen(): void
    {
        $this->autoJoin(true);
        $kunde = $this->kunde();

        $wa = $this->kanal('whatsapp');
        $portal = $this->kanal('portal');
        $this->verknuepfe($kunde, $wa, '491701234567');
        $this->verknuepfe($kunde, $portal, 'portal-1');

        $this->eingang($wa, '491701234567', 'Über WhatsApp');
        $this->eingang($portal, 'portal-1', 'Im Portal');

        $this->assertSame(1, Conversation::count());

        $unterhaltung = Conversation::first();
        $this->assertSame(2, $unterhaltung->channels()->count());
        $this->assertSame(2, $unterhaltung->messages()->count());
        $this->assertTrue($unterhaltung->isMultiChannel());

        // Der zweite Kanal ist als DAZUGEKOMMEN vermerkt - sonst liesse
        // sich eine falsche Verbindung spaeter nicht einordnen.
        $this->assertSame(
            ConversationChannel::JOIN_AUTO,
            $unterhaltung->channels()->where('channel_id', $portal->id)->value('join_method')
        );
    }

    /**
     * ZWEI Kandidaten heissen NICHTS ZUORDNEN - dieselbe Regel wie im
     * Kundenabgleich und im Provisions-Import. Jede Wahl waere eine
     * Vermutung.
     */
    public function test_6_bei_zwei_offenen_unterhaltungen_wird_nicht_geraten(): void
    {
        $this->autoJoin(true);
        $kunde = $this->kunde();

        $wa = $this->kanal('whatsapp');
        $portal = $this->kanal('portal');
        $intern = $this->kanal('internal');

        foreach ([[$wa, '4917000001'], [$intern, 'intern-1']] as [$k, $extern]) {
            Conversation::create([
                'customer_id' => $kunde->id,
                'channel_id' => $k->id,
                'external_user_id' => $extern,
                'status' => Conversation::STATUS_OPEN,
                'last_message_at' => now(),
            ]);
        }

        $this->verknuepfe($kunde, $portal, 'portal-1');
        $this->eingang($portal, 'portal-1', 'Im Portal');

        // Es entsteht eine DRITTE, statt an eine der beiden zu raten.
        $this->assertSame(3, Conversation::count());
    }

    /**
     * Eine Nachricht von einer UNBEKANNTEN Nummer gehoert per Definition
     * zu niemandem. Sie an einen bestehenden Vorgang zu haengen waere
     * reines Raten.
     */
    public function test_7_ohne_kundenakte_wird_nie_angeschlossen(): void
    {
        $this->autoJoin(true);
        $kunde = $this->kunde();

        $wa = $this->kanal('whatsapp');
        $portal = $this->kanal('portal');

        Conversation::create([
            'customer_id' => $kunde->id,
            'channel_id' => $wa->id,
            'external_user_id' => '4917000001',
            'status' => Conversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);

        // Kein verknuepfter Kunde fuer diese Kennung.
        $this->eingang($portal, 'voellig-unbekannt', 'Wer bin ich?');

        $this->assertSame(2, Conversation::count());
        $this->assertNull(
            Conversation::whereNull('customer_id')->firstOrFail()->customer_id
        );
    }

    /**
     * Ein Vorgang von vor Monaten ist eine ANDERE Sache. Ihn
     * fortzuschreiben waere kein Zusammenhang, sondern eine Behauptung.
     */
    public function test_8_eine_zu_alte_unterhaltung_wird_nicht_fortgesetzt(): void
    {
        $this->autoJoin(true);
        $kunde = $this->kunde();

        $wa = $this->kanal('whatsapp');
        $portal = $this->kanal('portal');

        Conversation::create([
            'customer_id' => $kunde->id,
            'channel_id' => $wa->id,
            'external_user_id' => '4917000001',
            'status' => Conversation::STATUS_OPEN,
            'last_message_at' => now()->subDays(ChannelRoutingService::JOIN_MAX_AGE_DAYS + 1),
        ]);

        $this->verknuepfe($kunde, $portal, 'portal-1');
        $this->eingang($portal, 'portal-1', 'Neue Sache');

        $this->assertSame(2, Conversation::count());
    }

    /** Archiv und Abschluss sind bewusste Entscheidungen von Menschen. */
    public function test_9_geschlossene_und_archivierte_unterhaltungen_bleiben_unberuehrt(): void
    {
        $this->autoJoin(true);
        $kunde = $this->kunde();

        $wa = $this->kanal('whatsapp');
        $portal = $this->kanal('portal');

        Conversation::create([
            'customer_id' => $kunde->id,
            'channel_id' => $wa->id,
            'external_user_id' => '4917000001',
            'status' => Conversation::STATUS_CLOSED,
            'closed_at' => now(),
            'last_message_at' => now(),
        ]);

        $this->verknuepfe($kunde, $portal, 'portal-1');
        $this->eingang($portal, 'portal-1', 'Neue Sache');

        $this->assertSame(2, Conversation::count());
    }

    // ---------------------------------------------------------------
    // Antwortweg
    // ---------------------------------------------------------------

    /**
     * Geantwortet wird dort, wo der Kunde ZULETZT geschrieben hat -
     * nicht dort, wo die Unterhaltung begann. Sonst antwortet man im
     * Portal, waehrend der Kunde auf sein Telefon schaut.
     */
    public function test_10_der_antwortweg_ist_der_letzte_eingang(): void
    {
        $this->autoJoin(true);
        $kunde = $this->kunde();

        $wa = $this->kanal('whatsapp');
        $portal = $this->kanal('portal');
        $this->verknuepfe($kunde, $wa, '491701234567');
        $this->verknuepfe($kunde, $portal, 'portal-1');

        $this->eingang($wa, '491701234567', 'Zuerst über WhatsApp');
        $this->eingang($portal, 'portal-1', 'Jetzt im Portal');

        $unterhaltung = Conversation::firstOrFail();

        $this->assertSame(
            $portal->id,
            app(ChannelRoutingService::class)->defaultChannelId($unterhaltung)
        );
    }

    /**
     * DEM BROWSER WIRD NICHTS GEGLAUBT: dass die Oberflaeche einen Kanal
     * anbietet, ist keine Erlaubnis.
     */
    public function test_11_ein_fremder_kanal_wird_beim_antworten_abgelehnt(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kunde = $this->kunde();

        $wa = $this->kanal('whatsapp');
        $fremd = $this->kanal('internal');

        $unterhaltung = Conversation::create([
            'customer_id' => $kunde->id,
            'channel_id' => $wa->id,
            'external_user_id' => '491701234567',
            'status' => Conversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.postfach.reply', $unterhaltung->id), [
                'body' => 'Geht das?',
                'channel_id' => $fremd->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, $unterhaltung->messages()->count());
    }

    // ---------------------------------------------------------------
    // Der Rueckweg
    // ---------------------------------------------------------------

    /**
     * Ohne den Rueckweg waere eine falsche Verbindung endgueltig - und
     * damit waere das Zusammenfuehren gar nicht vertretbar. Er ist
     * verlustfrei, WEIL jede Nachricht ihren Kanal traegt.
     */
    public function test_12_ein_verbundener_kanal_laesst_sich_wieder_trennen(): void
    {
        $this->autoJoin(true);
        $admin = User::factory()->create(['role' => 'admin']);
        $kunde = $this->kunde();

        $wa = $this->kanal('whatsapp');
        $portal = $this->kanal('portal');
        $this->verknuepfe($kunde, $wa, '491701234567');
        $this->verknuepfe($kunde, $portal, 'portal-1');

        $this->eingang($wa, '491701234567', 'WhatsApp-Nachricht');
        $this->eingang($portal, 'portal-1', 'Portal-Nachricht');

        $unterhaltung = Conversation::firstOrFail();
        $this->assertSame(2, $unterhaltung->messages()->count());

        $this->actingAs($admin)
            ->post(route('admin.postfach.detach_channel', $unterhaltung->id), [
                'channel_id' => $portal->id,
            ])->assertRedirect();

        $this->assertSame(2, Conversation::count());
        $this->assertSame(1, $unterhaltung->fresh()->messages()->count());

        $neue = Conversation::where('id', '!=', $unterhaltung->id)->firstOrFail();
        $this->assertSame(1, $neue->messages()->count());
        $this->assertSame('Portal-Nachricht', $neue->messages()->value('body'));
        // Die herausgeloeste Unterhaltung beginnt jetzt auf diesem Kanal.
        $this->assertSame(
            ConversationChannel::JOIN_INITIAL,
            $neue->channels()->value('join_method')
        );
    }

    /** Der ERSTE Kanal ist die Unterhaltung selbst - er geht nie weg. */
    public function test_13_der_erste_kanal_laesst_sich_nicht_trennen(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $wa = $this->kanal('whatsapp');

        $unterhaltung = Conversation::create([
            'customer_id' => $this->kunde()->id,
            'channel_id' => $wa->id,
            'external_user_id' => '491701234567',
            'status' => Conversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.postfach.detach_channel', $unterhaltung->id), ['channel_id' => $wa->id])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, Conversation::count());
    }

    // ---------------------------------------------------------------
    // Postfach
    // ---------------------------------------------------------------

    /** Der Kanal-Reiter muss auch fortgesetzte Unterhaltungen zeigen. */
    public function test_14_der_kanalfilter_findet_eine_angeschlossene_unterhaltung(): void
    {
        $this->autoJoin(true);
        $admin = User::factory()->create(['role' => 'admin']);
        $kunde = $this->kunde('Filter Kunde');

        $wa = $this->kanal('whatsapp');
        $portal = $this->kanal('portal');
        $this->verknuepfe($kunde, $wa, '491701234567');
        $this->verknuepfe($kunde, $portal, 'portal-1');

        $this->eingang($wa, '491701234567', 'Über WhatsApp');
        $this->eingang($portal, 'portal-1', 'Im Portal');

        // Die Unterhaltung BEGANN auf WhatsApp - im Portal-Reiter muss
        // sie trotzdem auftauchen.
        $this->actingAs($admin)
            ->get(route('admin.postfach', ['kanal' => 'portal']))
            ->assertOk()
            ->assertSee('Filter Kunde');
    }

    /**
     * Zwischen Deployment und Nachtrag hat der Bestand noch keinen
     * Eintrag. Ohne Rueckfall waere das Postfach in dieser Zeit LEER -
     * und zwar ohne Fehlermeldung.
     */
    public function test_15_der_altbestand_ohne_eintrag_bleibt_sichtbar(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kunde = $this->kunde('Altbestand Kunde');
        $wa = $this->kanal('whatsapp');

        $unterhaltung = Conversation::create([
            'customer_id' => $kunde->id,
            'channel_id' => $wa->id,
            'external_user_id' => '491701234567',
            'status' => Conversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);

        // Den Zustand VOR Phase 2 herstellen.
        ConversationChannel::where('conversation_id', $unterhaltung->id)->delete();
        $this->assertSame(0, $unterhaltung->channels()->count());

        $this->actingAs($admin)
            ->get(route('admin.postfach', ['kanal' => 'whatsapp']))
            ->assertOk()
            ->assertSee('Altbestand Kunde');
    }

    // ---------------------------------------------------------------
    // Nachtrag
    // ---------------------------------------------------------------

    /** Ein Nachtrag, den man nach einem Abbruch nicht wiederholen darf, ist wertlos. */
    public function test_16_der_nachtrag_ist_idempotent_und_traegt_den_kanal_nach(): void
    {
        $kunde = $this->kunde();
        $wa = $this->kanal('whatsapp');

        $unterhaltung = Conversation::create([
            'customer_id' => $kunde->id,
            'channel_id' => $wa->id,
            'external_user_id' => '491701234567',
            'status' => Conversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);

        $nachricht = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id,
            'customer_id' => $kunde->id,
            'body' => 'Alt',
            'from_staff' => false,
        ]);

        // Den Zustand VOR Phase 2 herstellen.
        ConversationChannel::where('conversation_id', $unterhaltung->id)->delete();
        $nachricht->forceFill(['channel_id' => null, 'channel_account_id' => null])->saveQuietly();
        $unterhaltung->forceFill(['last_channel_id' => null])->saveQuietly();

        $this->artisan('messaging:kanal-nachtragen')->assertExitCode(0);
        $this->artisan('messaging:kanal-nachtragen')->assertExitCode(0);

        $this->assertSame(1, $unterhaltung->fresh()->channels()->count());
        $this->assertSame($wa->id, $nachricht->fresh()->channel_id);
        $this->assertSame($wa->id, $unterhaltung->fresh()->last_channel_id);
    }

    /**
     * Auch OHNE Nachtrag muss alles funktionieren: der Rueckfall auf den
     * Kanal der Unterhaltung deckt den Altbestand ab.
     */
    public function test_17_ohne_nachtrag_gilt_der_kanal_der_unterhaltung(): void
    {
        $wa = $this->kanal('whatsapp');

        $unterhaltung = Conversation::create([
            'customer_id' => $this->kunde()->id,
            'channel_id' => $wa->id,
            'external_user_id' => '491701234567',
            'status' => Conversation::STATUS_OPEN,
        ]);

        $nachricht = CustomerMessage::create([
            'conversation_id' => $unterhaltung->id,
            'body' => 'Ohne Kanal',
            'from_staff' => false,
        ]);
        $nachricht->forceFill(['channel_id' => null])->saveQuietly();

        $this->assertNull($nachricht->fresh()->channel_id);
        $this->assertSame($wa->id, $nachricht->fresh()->channelId());
    }

    /** Verknuepft einen Kunden mit einer Kennung in einem Kanal. */
    private function verknuepfe(Customer $kunde, Channel $kanal, string $extern): void
    {
        CustomerChannelIdentity::create([
            'customer_id' => $kunde->id,
            'channel_id' => $kanal->id,
            'external_user_id' => $extern,
            'match_method' => CustomerChannelIdentity::METHOD_MANUAL,
        ]);
    }
}
