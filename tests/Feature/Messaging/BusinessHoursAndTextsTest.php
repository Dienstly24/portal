<?php

namespace Tests\Feature\Messaging;

use App\Jobs\AnswerCustomerMessageJob;
use App\Models\Channel;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Ai\Assistant\AiSettingsResolver;
use App\Services\Ai\Assistant\AssistantReplies;
use App\Services\Ai\Assistant\AssistantTexts;
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\Dto\InboundMessage;
use App\Support\AiMode;
use App\Support\BusinessHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Geschaeftszeiten (Abschnitt 64) und Textbausteine (Abschnitt 65).
 *
 * Der wichtigste Fall ist die ZEITZONE: gespeichert wird UTC, gemeint
 * ist deutsche Ortszeit. Zwei Stunden Abweichung sehen plausibel aus -
 * deshalb wird hier ausdruecklich ueber die Sommerzeit geprueft.
 */
class BusinessHoursAndTextsTest extends TestCase
{
    use RefreshDatabase;

    private function hours(): BusinessHours
    {
        return app(BusinessHours::class);
    }

    private function kunde(): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => 'k'.uniqid().'@example.de']);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
            'phone' => '0170 7770001',
        ]);
    }

    private function eingang(string $msg = 'm1', string $text = 'Hallo, haben Sie geoeffnet?'): void
    {
        app(ConversationEngine::class)->handleInbound(
            new InboundMessage(
                externalUserId: '491707770001', externalMessageId: $msg,
                text: $text, senderPhone: '0170 7770001',
            ),
            Channel::where('key', Channel::PORTAL)->firstOrFail()
        );
    }

    /** Fall 1: Ohne Hauptschalter gilt immer "geoeffnet" - die Regel wirkt nirgends. */
    public function test_ohne_hauptschalter_ist_immer_geoeffnet(): void
    {
        $this->assertTrue($this->hours()->open());
        $this->assertFalse($this->hours()->enforced());
    }

    /**
     * Fall 2: DIE ZEITZONEN-FALLE. 07:30 UTC ist im Sommer 09:30 in
     * Deutschland - also GEOEFFNET. Wer roh gegen UTC prueft, sperrt hier
     * faelschlich zu.
     */
    public function test_geschaeftszeiten_gelten_in_deutscher_ortszeit(): void
    {
        SystemSetting::set(BusinessHours::ENABLED, '1');
        $this->hours()->save([
            'mon' => ['open' => true, 'from' => '09:00', 'to' => '18:00'],
        ]);

        // Montag, 6. Juli 2026, 07:30 UTC = 09:30 Ortszeit (Sommerzeit).
        $this->assertTrue($this->hours()->open(Carbon::parse('2026-07-06 07:30:00', 'UTC')));

        // 06:30 UTC = 08:30 Ortszeit - noch zu.
        $this->assertFalse($this->hours()->open(Carbon::parse('2026-07-06 06:30:00', 'UTC')));

        // Im WINTER verschiebt sich dieselbe Grenze: 08:30 UTC = 09:30.
        $this->assertTrue($this->hours()->open(Carbon::parse('2026-01-05 08:30:00', 'UTC')));
        $this->assertFalse($this->hours()->open(Carbon::parse('2026-01-05 07:30:00', 'UTC')));
    }

    /** Fall 3: Ein geschlossener Tag bleibt zu. */
    public function test_geschlossener_tag_bleibt_zu(): void
    {
        SystemSetting::set(BusinessHours::ENABLED, '1');
        $this->hours()->save(['sun' => ['open' => false, 'from' => '09:00', 'to' => '18:00']]);

        // Sonntag, 5. Juli 2026, 12:00 Ortszeit.
        $this->assertFalse($this->hours()->open(Carbon::parse('2026-07-05 10:00:00', 'UTC')));
    }

    /**
     * Fall 4: Ein Zeitraum ueber Mitternacht. Ohne diesen Fall waere so
     * ein Tag dauerhaft geschlossen - und niemand saehe warum.
     */
    public function test_zeitraum_ueber_mitternacht(): void
    {
        SystemSetting::set(BusinessHours::ENABLED, '1');
        $this->hours()->save(['mon' => ['open' => true, 'from' => '20:00', 'to' => '02:00']]);

        // Montag 22:00 Ortszeit = 20:00 UTC (Sommer).
        $this->assertTrue($this->hours()->open(Carbon::parse('2026-07-06 20:00:00', 'UTC')));
        // Montag 12:00 Ortszeit - dazwischen, also zu.
        $this->assertFalse($this->hours()->open(Carbon::parse('2026-07-06 10:00:00', 'UTC')));
    }

    /** Fall 5: Eine kaputte Zeitangabe wird nie gespeichert. */
    public function test_kaputte_zeitangabe_wird_verworfen(): void
    {
        $this->hours()->save(['mon' => ['open' => true, 'from' => '25:99', 'to' => 'abc']]);

        $plan = $this->hours()->schedule();
        $this->assertSame('09:00', $plan['mon']['from']);
        $this->assertSame('09:00', $plan['mon']['to']);
    }

    /**
     * Fall 6: Ausserhalb der Zeiten wird die KI NICHT angestossen, der
     * Kunde bekommt aber eine Antwort statt Stille.
     */
    public function test_ausserhalb_der_zeiten_antwortet_die_ki_nicht_inhaltlich(): void
    {
        Queue::fake();
        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set(AiSettingsResolver::GLOBAL_MODE_KEY, AiMode::AUTO_REPLY);
        SystemSetting::set(BusinessHours::ENABLED, '1');
        $this->hours()->save(['sun' => ['open' => false, 'from' => '09:00', 'to' => '18:00']]);
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00', 'UTC')); // Sonntag
        $this->kunde();

        $this->eingang();

        Queue::assertNotPushed(AnswerCustomerMessageJob::class);
        $hinweis = CustomerMessage::where('message_type', 'system')->first();
        $this->assertNotNull($hinweis);
        $this->assertTrue($hinweis->from_staff);
        $this->assertStringContainsString('Geschäftszeiten', $hinweis->body);

        Carbon::setTestNow();
    }

    /**
     * Fall 7: Der Hinweis kommt HOECHSTENS EINMAL. Fuenf Abendnachrichten
     * duerfen nicht fuenf gleiche Bausteine erzeugen - das liest sich wie
     * eine kaputte Maschine.
     */
    public function test_abwesenheitshinweis_kommt_nicht_mehrfach(): void
    {
        Queue::fake();
        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set(AiSettingsResolver::GLOBAL_MODE_KEY, AiMode::AUTO_REPLY);
        SystemSetting::set(BusinessHours::ENABLED, '1');
        $this->hours()->save(['sun' => ['open' => false, 'from' => '09:00', 'to' => '18:00']]);
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00', 'UTC'));
        $this->kunde();

        $this->eingang('m1');
        $this->eingang('m2');
        $this->eingang('m3');

        $this->assertSame(1, CustomerMessage::where('message_type', 'system')->count());

        Carbon::setTestNow();
    }

    /** Fall 8: Innerhalb der Zeiten laeuft alles wie gewohnt. */
    public function test_innerhalb_der_zeiten_arbeitet_die_ki_normal(): void
    {
        Queue::fake();
        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set(AiSettingsResolver::GLOBAL_MODE_KEY, AiMode::AUTO_REPLY);
        SystemSetting::set(BusinessHours::ENABLED, '1');
        $this->hours()->save(['mon' => ['open' => true, 'from' => '09:00', 'to' => '18:00']]);
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'UTC')); // Montag 12:00 Ortszeit
        $this->kunde();

        $this->eingang();

        Queue::assertPushed(AnswerCustomerMessageJob::class);
        $this->assertSame(0, CustomerMessage::where('message_type', 'system')->count());

        Carbon::setTestNow();
    }

    /** Fall 9: Ohne eigene Fassung gilt die gepruefte Vorgabe. */
    public function test_ohne_eigene_fassung_gilt_die_vorgabe(): void
    {
        $texte = app(AssistantTexts::class);

        $this->assertSame(
            AssistantReplies::HANDOVER['de'],
            $texte->get(AssistantTexts::HANDOVER, 'de')
        );
        $this->assertSame(
            AssistantReplies::HANDOVER['ar'],
            $texte->get(AssistantTexts::HANDOVER, 'ar')
        );
    }

    /** Fall 10: Eine eigene Fassung gilt - und nur in ihrer Sprache. */
    public function test_eigene_fassung_gilt_nur_in_ihrer_sprache(): void
    {
        $texte = app(AssistantTexts::class);
        $texte->put(AssistantTexts::HANDOVER, 'de', 'Wir kuemmern uns persoenlich darum.');

        $this->assertSame('Wir kuemmern uns persoenlich darum.', $texte->get(AssistantTexts::HANDOVER, 'de'));
        // Arabisch bleibt die Vorgabe - nie ein deutscher Text an einen
        // arabisch schreibenden Kunden.
        $this->assertSame(AssistantReplies::HANDOVER['ar'], $texte->get(AssistantTexts::HANDOVER, 'ar'));
    }

    /** Fall 11: Leer gespeichert heisst "wieder die Vorgabe". */
    public function test_leerer_text_faellt_auf_die_vorgabe_zurueck(): void
    {
        $texte = app(AssistantTexts::class);
        $texte->put(AssistantTexts::FALLBACK, 'de', 'Eigener Text');
        $this->assertSame('Eigener Text', $texte->get(AssistantTexts::FALLBACK, 'de'));

        $texte->put(AssistantTexts::FALLBACK, 'de', '');
        $this->assertSame(AssistantReplies::FALLBACK['de'], $texte->get(AssistantTexts::FALLBACK, 'de'));
    }

    /** Fall 12: Unbekannte Schluessel und Sprachen werden nie gespeichert. */
    public function test_unbekannte_schluessel_werden_verworfen(): void
    {
        $texte = app(AssistantTexts::class);
        $texte->put('gibtesnicht', 'de', 'x');
        $texte->put(AssistantTexts::HANDOVER, 'fr', 'x');

        $this->assertSame('', (string) SystemSetting::get('ai_text_gibtesnicht_de', ''));
        $this->assertSame('', (string) SystemSetting::get('ai_text_handover_fr', ''));
    }

    /** Fall 13: Admin pflegt Zeiten und Texte ueber die Seite. */
    public function test_admin_pflegt_zeiten_und_texte(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'a'.uniqid().'@dienstly24.de']);

        $this->actingAs($admin)->put('/admin/ki-anbieter/geschaeftszeiten', [
            'enforced' => '1',
            'days' => ['mon' => ['open' => '1', 'from' => '08:00', 'to' => '17:00']],
        ])->assertRedirect();

        $this->assertTrue($this->hours()->enforced());
        $this->assertSame('08:00', $this->hours()->schedule()['mon']['from']);

        $this->actingAs($admin)->put('/admin/ki-anbieter/textbausteine', [
            'texts' => [AssistantTexts::OUT_OF_OFFICE => ['de' => 'Wir sind ab 8 Uhr wieder da.']],
        ])->assertRedirect();

        $this->assertSame('Wir sind ab 8 Uhr wieder da.',
            app(AssistantTexts::class)->get(AssistantTexts::OUT_OF_OFFICE, 'de'));
    }
}
