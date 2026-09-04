<?php

namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiKnowledgeEntry;
use App\Models\AiKnowledgeGap;
use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\Assistant\Tools\AssistantToolContext;
use App\Services\Ai\Assistant\Tools\SearchKnowledgeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wissensluecken und Sammelerfassung (Betreiber-Auftrag 18.08.2026).
 *
 * Der Assistent lernt NICHT von selbst - das ist Absicht: er darf nichts
 * behaupten, was kein Mensch freigegeben hat. Was er kann, ist melden,
 * wonach gefragt wurde, ohne dass eine Antwort hinterlegt ist. Diese Tests
 * sichern diese Rueckmeldung und den Weg "einmal beantworten, ab dann
 * beantwortet es der Assistent selbst".
 */
class KnowledgeGapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function suche(): SearchKnowledgeTool
    {
        return app(SearchKnowledgeTool::class);
    }

    private function kontext(): AssistantToolContext
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'email' => 'kunde'.uniqid().'@example.de',
            'name' => 'Abdulwahab Ibrahim',
        ]);
        $kunde = Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
        ]);

        return new AssistantToolContext($kunde, AiConversation::forCustomer($kunde->id), 'de');
    }

    /** Erfolglose Suche wird als Luecke festgehalten - vorher ging sie stumm verloren. */
    public function test_erfolglose_suche_wird_als_luecke_festgehalten(): void
    {
        $this->suche()->run(['suchbegriff' => 'Stromangebote'], $this->kontext());

        $gap = AiKnowledgeGap::first();
        $this->assertNotNull($gap);
        $this->assertSame('Stromangebote', $gap->topic);
        $this->assertSame(AiKnowledgeGap::SCOPE_CUSTOMER, $gap->scope);
        $this->assertSame(1, $gap->hits);
        $this->assertSame(AiKnowledgeGap::STATUS_OPEN, $gap->status);
    }

    /** Dasselbe Thema zaehlt hoch, statt die Liste zu fluten. */
    public function test_gleiches_thema_zaehlt_hoch_statt_sich_zu_wiederholen(): void
    {
        $kontext = $this->kontext();
        $this->suche()->run(['suchbegriff' => 'Stromangebote Tarife'], $kontext);
        $this->suche()->run(['suchbegriff' => 'tarife stromangebote'], $kontext);

        $this->assertSame(1, AiKnowledgeGap::count());
        $this->assertSame(2, AiKnowledgeGap::first()->hits);
    }

    /** Ein Treffer ist keine Luecke. */
    public function test_treffer_erzeugt_keine_luecke(): void
    {
        AiKnowledgeEntry::create([
            'title' => 'Stromangebote',
            'category' => 'faq',
            'content' => 'Wir vergleichen Strom- und Gastarife anbieterunabhaengig.',
            'active' => true,
        ]);

        $this->suche()->run(['suchbegriff' => 'Stromangebote'], $this->kontext());

        $this->assertSame(0, AiKnowledgeGap::count());
    }

    /** Kein Kundenbezug in der Luecke - sie beschreibt UNSERE Wissensbasis. */
    public function test_luecke_speichert_keinen_kundenbezug(): void
    {
        $this->suche()->run(['suchbegriff' => 'Stromangebote'], $this->kontext());

        $spalten = array_keys(AiKnowledgeGap::first()->getAttributes());
        $this->assertNotContains('customer_id', $spalten);
        $this->assertNotContains('conversation_id', $spalten);
    }

    /** Antwort schreiben schliesst die Luecke und legt den Eintrag an. */
    public function test_antwort_auf_luecke_legt_eintrag_an_und_schliesst_sie(): void
    {
        $this->suche()->run(['suchbegriff' => 'Stromangebote'], $this->kontext());
        $gap = AiKnowledgeGap::firstOrFail();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('admin.ai_knowledge_gaps.answer', $gap->id), [
                'title' => 'Stromangebote',
                'category' => 'faq',
                'content' => 'Ja - wir vergleichen Strom- und Gastarife und melden uns mit passenden Angeboten.',
                'active' => 1,
            ])
            ->assertRedirect();

        $gap->refresh();
        $this->assertSame(AiKnowledgeGap::STATUS_DONE, $gap->status);
        $this->assertSame($admin->id, $gap->resolved_by);

        $eintrag = AiKnowledgeEntry::firstOrFail();
        $this->assertTrue($eintrag->active);
        $this->assertSame($gap->resolved_entry_id, $eintrag->id);
    }

    /** Ab dann beantwortet der Assistent es selbst - genau der Sinn der Uebung. */
    public function test_nach_der_antwort_findet_die_suche_den_eintrag(): void
    {
        $kontext = $this->kontext();
        $this->suche()->run(['suchbegriff' => 'Stromangebote'], $kontext);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.ai_knowledge_gaps.answer', AiKnowledgeGap::firstOrFail()->id), [
            'title' => 'Stromangebote',
            'category' => 'faq',
            'content' => 'Ja - wir vergleichen Strom- und Gastarife.',
            'active' => 1,
        ]);

        $ergebnis = $this->suche()->run(['suchbegriff' => 'Stromangebote'], $kontext);

        $this->assertSame(1, $ergebnis['treffer']);
        $this->assertSame(1, AiKnowledgeGap::count());
    }

    /** Ein Eintrag, den die Suche findet, schliesst offene Luecken automatisch. */
    public function test_neuer_aktiver_eintrag_schliesst_passende_luecke(): void
    {
        $this->suche()->run(['suchbegriff' => 'Kuendigungsfrist Kfz'], $this->kontext());
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.ai_knowledge.store'), [
            'title' => 'Kuendigungsfrist Kfz',
            'category' => 'faq',
            'content' => 'In der Regel ein Monat zum Ablauf des Vertrags.',
            'active' => 1,
        ])->assertRedirect();

        $this->assertSame(AiKnowledgeGap::STATUS_DONE, AiKnowledgeGap::firstOrFail()->status);
    }

    /** Ein ENTWURF schliesst nichts - der Assistent findet ihn ja nicht. */
    public function test_entwurf_schliesst_keine_luecke(): void
    {
        $this->suche()->run(['suchbegriff' => 'Kuendigungsfrist Kfz'], $this->kontext());
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.ai_knowledge.store'), [
            'title' => 'Kuendigungsfrist Kfz',
            'category' => 'faq',
            'content' => 'In der Regel ein Monat zum Ablauf des Vertrags.',
        ])->assertRedirect();

        $this->assertSame(AiKnowledgeGap::STATUS_OPEN, AiKnowledgeGap::firstOrFail()->status);
    }

    /** Ignorierte Themen kommen zurueck, wenn erneut danach gefragt wird. */
    public function test_ignorierte_luecke_wird_bei_erneuter_frage_nicht_still_uebergangen(): void
    {
        $kontext = $this->kontext();
        $this->suche()->run(['suchbegriff' => 'Wetter morgen'], $kontext);
        $gap = AiKnowledgeGap::firstOrFail();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('admin.ai_knowledge_gaps.status', $gap->id), ['status' => 'ignoriert'])
            ->assertRedirect();
        $this->assertSame(AiKnowledgeGap::STATUS_IGNORED, $gap->fresh()->status);

        $this->suche()->run(['suchbegriff' => 'wetter morgen'], $kontext);

        // Der Zaehler laeuft weiter, der Status bleibt die Entscheidung des
        // Mitarbeiters - ignoriert heisst ignoriert.
        $this->assertSame(2, $gap->fresh()->hits);
        $this->assertSame(AiKnowledgeGap::STATUS_IGNORED, $gap->fresh()->status);
    }

    /** Sammelerfassung: viele Fragen in einem Rutsch. */
    public function test_sammelerfassung_legt_alle_paare_an(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.ai_knowledge.import'), [
            'text' => "F: Habt ihr Stromangebote?\nA: Ja, wir vergleichen Strom- und Gastarife.\nAuch Gas gehoert dazu.\n\n"
                ."F: Was kostet die Beratung?\nA: Die Beratung ist kostenlos.\n\n"
                ."س: عندكم عروض كهرباء؟\nج: نعم، منقارنلك التعرفات.",
            'category' => 'faq',
            'language' => 'de',
            'active' => 1,
        ])->assertRedirect();

        $this->assertSame(3, AiKnowledgeEntry::count());
        $mehrzeilig = AiKnowledgeEntry::where('title', 'Habt ihr Stromangebote?')->firstOrFail();
        $this->assertStringContainsString('Auch Gas gehoert dazu.', $mehrzeilig->content);
        $this->assertTrue($mehrzeilig->active);
    }

    /** Halbe Bloecke werden uebersprungen, statt halbe Antworten anzulegen. */
    public function test_sammelerfassung_ueberspringt_unvollstaendige_bloecke(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.ai_knowledge.import'), [
            'text' => "F: Frage ohne Antwort\n\nF: Frage mit Antwort\nA: Die Antwort.",
            'category' => 'faq',
        ])->assertRedirect();

        $this->assertSame(1, AiKnowledgeEntry::count());
        $this->assertSame('Frage mit Antwort', AiKnowledgeEntry::firstOrFail()->title);
    }

    /** Text ohne erkennbares Format wird abgelehnt, nicht halb verarbeitet. */
    public function test_sammelerfassung_lehnt_unlesbaren_text_ab(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.ai_knowledge.import'), [
            'text' => 'Irgendein Fliesstext ohne Frage- und Antwortmarken.',
            'category' => 'faq',
        ])->assertSessionHasErrors('text');

        $this->assertSame(0, AiKnowledgeEntry::count());
    }

    /** Die Luecken-Seite zeigt das haeufigste Thema oben. */
    public function test_seite_zeigt_haeufigstes_thema_zuerst(): void
    {
        AiKnowledgeGap::record('Selten gefragt');
        for ($i = 0; $i < 5; $i++) {
            AiKnowledgeGap::record('Stromangebote');
        }

        $admin = User::factory()->create(['role' => 'admin']);
        $antwort = $this->actingAs($admin)->get(route('admin.ai_knowledge_gaps'))->assertOk();

        $html = $antwort->getContent();
        $this->assertLessThan(
            strpos($html, 'Selten gefragt'),
            strpos($html, 'Stromangebote'),
            'Das haeufigste Thema muss oben stehen.'
        );
        $this->assertStringContainsString('5× gefragt', $html);
    }

    /** Mitarbeiter haben mit der Wissensbasis nichts zu tun. */
    public function test_mitarbeiter_kommt_nicht_an_die_luecken(): void
    {
        $mitarbeiter = User::factory()->create(['role' => 'employee']);

        $this->actingAs($mitarbeiter)
            ->get(route('admin.ai_knowledge_gaps'))
            ->assertRedirect(route('admin.dashboard'));
    }
}
