<?php

namespace Tests\Feature\Messaging;

use App\Jobs\AnswerCustomerMessageJob;
use App\Models\AiConversation;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Ai\Assistant\AiSettingsResolver;
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\Dto\InboundMessage;
use App\Support\AiMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Der KI-Assistent im Omnichannel (Auftrag Abschnitte 48-51, 63, 79-93).
 *
 * Zwei Dinge werden hier festgehalten, weil sie sonst leise brechen:
 * die HIERARCHIE muss deterministisch sein, und der BESTAND muss sich
 * nach dem Deploy exakt so verhalten wie davor.
 */
class AiChannelIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(array $attrs = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => 'k'.uniqid().'@example.de']);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => '26'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
            'phone' => '0170 4440001',
        ], $attrs));
    }

    private function unterhaltung(?Customer $kunde = null, ?ChannelAccount $konto = null): Conversation
    {
        return Conversation::create([
            'customer_id' => $kunde?->id,
            'channel_id' => Channel::idFor(Channel::PORTAL),
            'channel_account_id' => $konto?->id,
            'status' => Conversation::STATUS_OPEN,
        ]);
    }

    private function konto(): ChannelAccount
    {
        return ChannelAccount::create([
            'channel_id' => Channel::idFor(Channel::PORTAL),
            'name' => 'Konto A',
        ]);
    }

    private function resolver(): AiSettingsResolver
    {
        return app(AiSettingsResolver::class);
    }

    /**
     * Fall 1: RUECKWAERTSKOMPATIBEL. Ohne ausdrueckliche Betriebsart wird
     * sie aus den bestehenden Schaltern abgeleitet - der Bestand
     * verhaelt sich nach dem Deploy wie davor.
     */
    public function test_ohne_auswahl_folgt_die_betriebsart_den_alten_schaltern(): void
    {
        SystemSetting::set('ai_assistant_enabled', '0');
        $this->assertSame(AiMode::OFF, $this->resolver()->globalMode());

        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set('ai_assistant_auto_reply', '1');
        $this->assertSame(AiMode::AUTO_REPLY, $this->resolver()->globalMode());

        // Hauptschalter an, automatische Antworten aus = zuarbeiten,
        // aber nichts an den Kunden senden.
        SystemSetting::set('ai_assistant_auto_reply', '0');
        $this->assertSame(AiMode::AI_ASSIST, $this->resolver()->globalMode());
    }

    /** Fall 2: Eine ausdrueckliche globale Auswahl schlaegt die Ableitung. */
    public function test_ausdrueckliche_globale_auswahl_gewinnt(): void
    {
        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set('ai_assistant_auto_reply', '1');
        SystemSetting::set(AiSettingsResolver::GLOBAL_MODE_KEY, AiMode::AI_FIRST);

        $this->assertSame(AiMode::AI_FIRST, $this->resolver()->globalMode());
    }

    /** Fall 3: Ein UNBEKANNTER Wert wird nie geraten - er gilt als nicht gesetzt. */
    public function test_unbekannte_betriebsart_wird_verworfen(): void
    {
        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set('ai_assistant_auto_reply', '1');
        SystemSetting::set(AiSettingsResolver::GLOBAL_MODE_KEY, 'turbo');

        $this->assertSame(AiMode::AUTO_REPLY, $this->resolver()->globalMode());
        $this->assertFalse(AiMode::valid('turbo'));
    }

    /**
     * Fall 4: DIE HIERARCHIE. Von spezifisch nach allgemein, und jede
     * Ebene wirkt nur, wenn sie ausdruecklich gesetzt ist.
     */
    public function test_hierarchie_greift_von_spezifisch_nach_allgemein(): void
    {
        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set(AiSettingsResolver::GLOBAL_MODE_KEY, AiMode::AUTO_REPLY);

        $kanal = Channel::where('key', Channel::PORTAL)->firstOrFail();
        $konto = $this->konto();
        $kunde = $this->kunde();
        $unterhaltung = $this->unterhaltung($kunde, $konto);
        $resolver = $this->resolver();

        // Nichts gesetzt -> global.
        $this->assertSame('Global', $resolver->explain($unterhaltung)['source']);

        // Kanal aus.
        $kanal->update(['ai_mode' => AiMode::OFF]);
        $this->assertSame(AiMode::OFF, $resolver->modeFor($unterhaltung->fresh()));

        // Kanalkonto schlaegt Kanal.
        $konto->update(['ai_mode' => AiMode::AI_FIRST]);
        $this->assertSame(AiMode::AI_FIRST, $resolver->modeFor($unterhaltung->fresh()));

        // Kunde schlaegt Kanalkonto (das Beispiel aus Abschnitt 85).
        $kunde->update(['ai_mode' => AiMode::HUMAN_ONLY]);
        $this->assertSame(AiMode::HUMAN_ONLY, $resolver->modeFor($unterhaltung->fresh()));

        // Unterhaltung schlaegt alles.
        $unterhaltung->update(['ai_mode' => AiMode::AUTO_REPLY]);
        $stand = $resolver->explain($unterhaltung->fresh());
        $this->assertSame(AiMode::AUTO_REPLY, $stand['mode']);
        $this->assertSame('Unterhaltung', $stand['source']);
    }

    /**
     * Fall 5: Der Hauptschalter ist die NOTBREMSE und steht ueber der
     * Hierarchie. Ein Notaus, den eine Kundeneinstellung aushebeln kann,
     * ist kein Notaus.
     */
    public function test_notbremse_schlaegt_jede_ausnahme(): void
    {
        SystemSetting::set('ai_assistant_enabled', '0');
        $kunde = $this->kunde(['ai_mode' => AiMode::AUTO_REPLY]);
        $unterhaltung = $this->unterhaltung($kunde);
        $unterhaltung->update(['ai_mode' => AiMode::AUTO_REPLY]);

        $this->assertFalse($this->resolver()->mayAutoReply($unterhaltung->fresh()));
    }

    /**
     * Fall 6: Der Vorschlags-Modus sendet NICHTS automatisch - dort
     * entscheidet ein Mensch ueber das Senden.
     */
    public function test_vorschlags_modus_sendet_nichts_von_selbst(): void
    {
        SystemSetting::set('ai_assistant_enabled', '1');
        $unterhaltung = $this->unterhaltung($this->kunde());
        $unterhaltung->update(['ai_mode' => AiMode::AI_ASSIST]);

        $this->assertFalse($this->resolver()->mayAutoReply($unterhaltung->fresh()));
        // Das Modell darf trotzdem befragt werden - fuer den Entwurf.
        $this->assertTrue($this->resolver()->mayUseModel($unterhaltung->fresh()));
    }

    /**
     * Fall 7: OHNE KUNDENAKTE antwortet die KI nie (Betreiber-Entscheidung
     * 06.09.2026) - die Unterhaltung entsteht trotzdem und geht an einen
     * Menschen.
     */
    public function test_unbekannter_absender_bekommt_keine_ki_antwort(): void
    {
        Queue::fake();
        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set(AiSettingsResolver::GLOBAL_MODE_KEY, AiMode::AUTO_REPLY);

        app(ConversationEngine::class)->handleInbound(
            new InboundMessage(externalUserId: '49999000', externalMessageId: 'u1', text: 'Hallo?'),
            Channel::where('key', Channel::PORTAL)->firstOrFail()
        );

        Queue::assertNotPushed(AnswerCustomerMessageJob::class);
        $this->assertDatabaseHas('activity_logs', ['action' => 'ai_skipped']);
        // Verloren geht nichts.
        $this->assertSame(1, Conversation::count());
    }

    /** Fall 8: Ist die KI fuer den Kanal aus, wird gar nichts angestossen. */
    public function test_abgeschaltete_ki_stoesst_keinen_job_an(): void
    {
        Queue::fake();
        SystemSetting::set('ai_assistant_enabled', '1');
        $kunde = $this->kunde();
        Channel::where('key', Channel::PORTAL)->update(['ai_mode' => AiMode::OFF]);
        cache()->flush();

        app(ConversationEngine::class)->handleInbound(
            new InboundMessage(
                externalUserId: '491704440001', externalMessageId: 'a1',
                text: 'Frage', senderPhone: '0170 4440001',
            ),
            Channel::where('key', Channel::PORTAL)->firstOrFail()
        );

        Queue::assertNotPushed(AnswerCustomerMessageJob::class);
    }

    /** Fall 9: Ist sie an, laeuft der Anstoss ueber den KERN - nicht ueber den Kanal. */
    public function test_eingehende_nachricht_stoesst_die_ki_ueber_den_kern_an(): void
    {
        Queue::fake();
        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set(AiSettingsResolver::GLOBAL_MODE_KEY, AiMode::AUTO_REPLY);
        $this->kunde();

        app(ConversationEngine::class)->handleInbound(
            new InboundMessage(
                externalUserId: '491704440001', externalMessageId: 'b1',
                text: 'Frage', senderPhone: '0170 4440001',
            ),
            Channel::where('key', Channel::PORTAL)->firstOrFail()
        );

        Queue::assertPushed(AnswerCustomerMessageJob::class);
    }

    /**
     * Fall 10: DER GRUND fuer den Steuerstand je Unterhaltung. Zwei
     * Kanaele desselben Kunden duerfen sich nicht gegenseitig abschalten.
     */
    public function test_pause_in_einem_kanal_schaltet_den_anderen_nicht_ab(): void
    {
        $kunde = $this->kunde();
        $a = $this->unterhaltung($kunde);
        $b = $this->unterhaltung($kunde);

        $steuerA = AiConversation::forConversation($a);
        $steuerB = AiConversation::forConversation($b);

        $this->assertNotSame($steuerA->id, $steuerB->id);

        $steuerA->forceFill(['ai_active' => false])->save();

        $this->assertFalse(AiConversation::forConversation($a)->canAutoReply());
        $this->assertTrue(AiConversation::forConversation($b)->canAutoReply());
    }

    /**
     * Fall 11: Eine DAUERHAFTE Abschaltung am Kunden wirkt aber fort -
     * sonst haette sie mit der naechsten neuen Unterhaltung still ihre
     * Wirkung verloren.
     */
    public function test_dauerhafte_abschaltung_gilt_auch_fuer_neue_unterhaltungen(): void
    {
        $kunde = $this->kunde();
        AiConversation::forCustomer($kunde->id)
            ->forceFill(['ai_active' => false, 'auto_resume' => false])->save();

        $neu = AiConversation::forConversation($this->unterhaltung($kunde));

        $this->assertFalse($neu->ai_active);
    }

    /** Fall 12: Ein Steuerstand je Unterhaltung, auch bei mehrfachem Aufruf. */
    public function test_steuerstand_entsteht_nur_einmal_je_unterhaltung(): void
    {
        $unterhaltung = $this->unterhaltung($this->kunde());

        $erst = AiConversation::forConversation($unterhaltung);
        $zweit = AiConversation::forConversation($unterhaltung);

        $this->assertSame($erst->id, $zweit->id);
        $this->assertSame(1, AiConversation::whereNotNull('omnichannel_conversation_id')->count());
    }
}
