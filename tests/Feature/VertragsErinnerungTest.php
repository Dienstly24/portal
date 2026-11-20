<?php

namespace Tests\Feature;

use App\Events\Messaging\InboundMessageReceived;
use App\Listeners\Messaging\KurzreferenzNotieren;
use App\Mail\VertragsErinnerungMail;
use App\Models\Channel;
use App\Models\Contract;
use App\Models\ContractSwitchReminder;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\User;
use App\Services\Ai\Assistant\AssistantTexts;
use App\Services\ContractSwitchReminderService;
use App\Services\Erinnerung\VertragsErinnerung;
use App\Support\ChatReferenz;
use App\Support\ErinnerungsAnrede;
use App\Support\ErinnerungsTexte;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Vertrags-Erinnerung im Tippgeber-Rahmen (Betreiber-Vorgabe 20.11.2026):
 * Vorlage A/B/keine, leere Zeilen, Anrede, Kontaktwege ohne Portal,
 * Abmeldung, verbotene Formulierungen, Kurzreferenz in WhatsApp.
 */
class VertragsErinnerungTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function kunde(array $user = [], array $kunde = []): Customer
    {
        $u = User::factory()->create(array_merge(['role' => 'customer', 'name' => 'Jamil Alsaleh Alsayed', 'email' => 'jamil@kunde.de'], $user));

        return Customer::create(array_merge([
            'user_id' => $u->id, 'customer_number' => '2600777', 'preferred_lang' => 'de',
            'marketing_consent' => true,
        ], $kunde));
    }

    private function wgv(Customer $kunde, array $mehr = []): Contract
    {
        return Contract::create(array_merge([
            'customer_id' => $kunde->id, 'type' => 'kfz', 'insurer' => 'WGV',
            'status' => 'active', 'start_date' => '2026-01-01', 'end_date' => '2027-01-01',
        ], $mehr));
    }

    // ---------------- Vorlagenwahl ----------------

    public function test_vorlage_a_bis_einschliesslich_fristtag(): void
    {
        $vertrag = $this->wgv($this->kunde());

        Carbon::setTestNow('2026-11-20');
        $e = VertragsErinnerung::bauen($vertrag, 'R-ABCDEF', null);
        $this->assertSame('a', $e->variante);

        Carbon::setTestNow('2026-11-30 23:00');
        $this->assertSame('a', VertragsErinnerung::bauen($vertrag, null, null)->variante);
    }

    public function test_vorlage_b_nach_der_frist_vor_der_verlaengerung(): void
    {
        $vertrag = $this->wgv($this->kunde());

        Carbon::setTestNow('2026-12-01');
        $this->assertSame('b', VertragsErinnerung::bauen($vertrag, null, null)->variante);
        Carbon::setTestNow('2026-12-05');
        $this->assertSame('b', VertragsErinnerung::bauen($vertrag, null, null)->variante);
        Carbon::setTestNow('2026-12-31');
        $this->assertSame('b', VertragsErinnerung::bauen($vertrag, null, null)->variante);
    }

    public function test_keine_mail_ab_der_verlaengerung(): void
    {
        $vertrag = $this->wgv($this->kunde());

        Carbon::setTestNow('2027-01-01');
        $this->assertNull(VertragsErinnerung::bauen($vertrag, null, null));
        Carbon::setTestNow('2027-02-10');
        $this->assertNull(VertragsErinnerung::bauen($vertrag, null, null));
    }

    // ---------------- Inhalt ----------------

    public function test_wgv_ohne_vertragsnummer_ohne_geschlecht_vorlage_a(): void
    {
        Carbon::setTestNow('2026-11-20');
        $e = VertragsErinnerung::bauen($this->wgv($this->kunde()), 'R-ABCDEF', 'https://x.test/abmelden/t');
        $html = (new VertragsErinnerungMail($e))->render();

        $this->assertSame('Ihre Kfz-Versicherung verlängert sich am 01.01.2027 automatisch', $e->betreff);
        $this->assertStringContainsString('Guten Tag Jamil Alsaleh Alsayed,', $html);
        $this->assertStringContainsString('Ihre Kfz-Versicherung bei WGV endet nicht einfach', $html);
        $this->assertStringContainsString('spätestens am 30.11.2026 beim Versicherer', $html);
        $this->assertStringContainsString('Kündigung muss eingehen bis:', $html);
        $this->assertStringNotContainsString('Vertragsnummer', $html, 'leere Zeile wird ausgeblendet');
        $this->assertStringContainsString('Hier abbestellen', $html);
        $this->assertStringContainsString('https://x.test/abmelden/t', $html);
    }

    public function test_vorlage_b_ohne_fristzeile(): void
    {
        Carbon::setTestNow('2026-12-05');
        $e = VertragsErinnerung::bauen($this->wgv($this->kunde(), ['contract_number' => 'KF-123']), null, null);
        $html = (new VertragsErinnerungMail($e))->render();

        $this->assertStringContainsString('Die reguläre Kündigungsfrist zu diesem Termin ist bereits abgelaufen.', $html);
        $this->assertStringContainsString('Sonderkündigungsrecht', $html);
        $this->assertStringNotContainsString('Kündigung muss eingehen bis', $html);
        $this->assertStringContainsString('Vertragsnummer:', $html);
        $this->assertStringContainsString('KF-123', $html);
    }

    public function test_ohne_versicherer_kein_leeres_bei(): void
    {
        Carbon::setTestNow('2026-11-20');
        $e = VertragsErinnerung::bauen($this->wgv($this->kunde(), ['insurer' => '']), null, null);

        $this->assertStringStartsWith('Ihre Kfz-Versicherung endet nicht einfach', $e->absaetze[0]);
        $this->assertArrayNotHasKey('Versicherer', $e->zeilen);
    }

    public function test_anrede_mit_geschlecht_und_rueckfall(): void
    {
        $this->assertSame('Herr Al Rahman', ErinnerungsAnrede::fuer($this->kunde(['name' => 'Yusuf Al Rahman', 'email' => 'a@b.de'], ['gender' => 'male', 'customer_number' => '1'])));
        $this->assertSame('Frau Musterfrau', ErinnerungsAnrede::fuer($this->kunde(['name' => 'Erika Musterfrau', 'email' => 'c@b.de'], ['gender' => 'female', 'customer_number' => '2'])));
        $this->assertSame('Max Muster', ErinnerungsAnrede::fuer($this->kunde(['name' => 'Max Muster', 'email' => 'd@b.de'], ['gender' => null, 'customer_number' => '3'])));
        $this->assertSame('', ErinnerungsAnrede::fuer($this->kunde(['name' => '', 'email' => 'e@b.de'], ['customer_number' => '4'])));
        $this->assertSame('', ErinnerungsAnrede::fuer(null));

        Carbon::setTestNow('2026-11-20');
        $ohneName = $this->kunde(['name' => '', 'email' => 'f@b.de'], ['customer_number' => '5']);
        $html = (new VertragsErinnerungMail(VertragsErinnerung::bauen($this->wgv($ohneName), null, null)))->render();
        $this->assertStringContainsString('Guten Tag,', $html);
        $this->assertStringNotContainsString('null', strtolower(strip_tags($html)));
    }

    public function test_arabisch_nach_gespeicherter_sprache(): void
    {
        Carbon::setTestNow('2026-11-20');
        $kunde = $this->kunde([], ['preferred_lang' => 'ar', 'gender' => 'male']);
        $e = VertragsErinnerung::bauen($this->wgv($kunde), 'R-ABCDEF', null);
        $html = (new VertragsErinnerungMail($e))->render();

        $this->assertSame('ar', $e->sprache);
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('يتجدّد تأمين سيارتك تلقائياً في 01.01.2027', $e->betreff);
        $this->assertStringContainsString('مرحباً السيد Alsayed', $html);
        $this->assertStringContainsString('30.11.2026', $html);
    }

    public function test_kontaktwege_ohne_portal_und_ohne_vertragsnummer_in_whatsapp(): void
    {
        Carbon::setTestNow('2026-11-20');
        $vertrag = $this->wgv($this->kunde(), ['contract_number' => 'GEHEIM-4711']);
        $e = VertragsErinnerung::bauen($vertrag, 'R-ABCDEF', null);
        $mail = new VertragsErinnerungMail($e);
        $html = $mail->render();

        $this->assertDoesNotMatchRegularExpression('~https?://[^/"\s]+/(portal|login|register|magic-login)~', $html);
        $this->assertStringContainsString('/chat?ref=', $e->chatUrl);
        $this->assertNotNull(ChatReferenz::pruefen(urldecode(substr($e->chatUrl, strpos($e->chatUrl, 'ref=') + 4))));
        $this->assertStringStartsWith('https://wa.me/491799673909?text=', $e->whatsappUrl);
        $wa = rawurldecode($e->whatsappUrl);
        $this->assertStringContainsString('Hallo Dienstly24, ich habe eine Erinnerung zu meiner Kfz-Versicherung erhalten. Referenz: R-ABCDEF', $wa);
        $this->assertStringNotContainsString('GEHEIM-4711', $wa);
        $this->assertStringContainsString('Im Chat schreiben', $html);
        $this->assertStringContainsString('Per WhatsApp schreiben', $html);
        $this->assertStringContainsString('Oder antworten Sie einfach auf diese E-Mail.', $html);
    }

    public function test_klartextfassung_traegt_die_rohen_links(): void
    {
        Carbon::setTestNow('2026-11-20');
        $e = VertragsErinnerung::bauen($this->wgv($this->kunde()), 'R-ABCDEF', 'https://x.test/abmelden/t');
        $text = view('emails.erinnerung.vertrag_text', ['e' => $e])->render();

        $this->assertStringContainsString('Im Chat schreiben: '.$e->chatUrl, $text);
        $this->assertStringContainsString('Per WhatsApp schreiben: '.$e->whatsappUrl, $text);
        $this->assertStringContainsString('Hier abbestellen: https://x.test/abmelden/t', $text);
        $this->assertStringNotContainsString('<', $text);
    }

    public function test_keine_verbotenen_formulierungen_in_den_freigegebenen_texten(): void
    {
        $alles = mb_strtolower(json_encode([ErinnerungsTexte::TEXTE, ErinnerungsTexte::RAHMEN], JSON_UNESCAPED_UNICODE));
        foreach (['beratung', 'berate', 'vermitt', 'makler', 'bestes angebot', 'beste angebot',
            'besseres angebot', 'sparen', 'ersparnis', 'günstiger', 'empfehl', 'begleite', 'portal'] as $wort) {
            $this->assertStringNotContainsString($wort, $alles, "verboten: $wort");
        }
    }

    // ---------------- Versand ----------------

    public function test_abgemeldeter_kunde_bekommt_nichts(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-11-20');
        $this->wgv($this->kunde([], ['marketing_consent' => false, 'unsubscribed_at' => now()]));

        $this->assertSame(0, app(ContractSwitchReminderService::class)->run());
        Mail::assertNothingQueued();
    }

    public function test_versand_speichert_kurzreferenz_und_nutzt_sie_im_whatsapp_text(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-11-20');
        $vertrag = $this->wgv($this->kunde());

        $this->assertSame(1, app(ContractSwitchReminderService::class)->run());

        $ref = ContractSwitchReminder::where('contract_id', $vertrag->id)->value('kurzreferenz');
        $this->assertMatchesRegularExpression('/^R-[A-HJ-NP-Z2-9]{6}$/', $ref);
        Mail::assertQueued(VertragsErinnerungMail::class, fn ($m) => str_contains(rawurldecode((string) $m->erinnerung->whatsappUrl), $ref)
            && $m->erinnerung->variante === 'a');
    }

    public function test_fehler_behoben_kein_versand_mehr_am_tag_nach_der_frist_wenn_a_schon_raus(): void
    {
        Mail::fake();
        $vertrag = $this->wgv($this->kunde());
        Carbon::setTestNow('2026-11-10');
        $this->assertSame(1, app(ContractSwitchReminderService::class)->run());

        // Vorher ging am 01.12. (Ende minus 1 Monat) noch eine Mail raus.
        Carbon::setTestNow('2026-12-01');
        $this->assertSame(0, app(ContractSwitchReminderService::class)->run());
        $this->assertSame(1, $vertrag->switchReminders()->count());
    }

    // ---------------- WhatsApp ----------------

    public function test_kurzreferenz_im_whatsapp_erzeugt_nur_interne_notiz(): void
    {
        $kunde = $this->kunde();
        $vertrag = $this->wgv($kunde, ['contract_number' => 'KF-9']);
        ContractSwitchReminder::create(['contract_id' => $vertrag->id, 'stage' => 'first', 'anchor' => '2027-01-01',
            'sent_at' => now(), 'kurzreferenz' => 'R-ABC234']);

        $unterhaltung = Conversation::create(['channel_id' => Channel::idFor(Channel::WEBCHAT), 'status' => 'open']);
        $nachricht = CustomerMessage::create(['conversation_id' => $unterhaltung->id, 'body' => 'Hallo, Referenz: r-abc234 bitte',
            'from_staff' => false]);

        $listener = new KurzreferenzNotieren;
        $listener->handle(new InboundMessageReceived($unterhaltung, $nachricht));
        $listener->handle(new InboundMessageReceived($unterhaltung, $nachricht));

        $this->assertNull($unterhaltung->fresh()->customer_id, 'nie automatisch zuordnen');
        $notiz = ConversationNote::sole();
        $this->assertStringContainsString('2600777', $notiz->body);
        $this->assertStringContainsString('KF-9', $notiz->body);
        $this->assertStringContainsString('NICHT automatisch zugeordnet', $notiz->body);
    }

    public function test_abwesenheitshinweis_traegt_tippgeber_hinweis_einmal(): void
    {
        $de = AssistantTexts::mitTippgeberHinweis('Wir sind ab 8 Uhr wieder da.', 'de');
        $this->assertStringContainsString('Wir sind kein Versicherungsvermittler', $de);
        $this->assertSame($de, AssistantTexts::mitTippgeberHinweis($de, 'de'));
        $this->assertStringContainsString('لسنا وسيط تأمين', AssistantTexts::mitTippgeberHinweis('نص', 'ar'));
    }
}
