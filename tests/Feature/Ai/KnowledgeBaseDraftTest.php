<?php

namespace Tests\Feature\Ai;

use App\Models\AiKnowledgeEntry;
use App\Models\ServicePage;
use App\Models\User;
use App\Services\Ai\Assistant\KnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wissensbasis-Entwuerfe aus den Leistungsseiten (Betreiber-Auftrag
 * 18.08.2026).
 *
 * Der Assistent war fertig, aber unbrauchbar: leere Wissensbasis heisst,
 * er uebergibt jede allgemeine Frage an das Team. Diese Tests sichern die
 * drei Zusagen des neuen Wegs: es wird NICHTS erfunden (nur vorhandene
 * Texte werden uebertragen), nichts wird automatisch aktiv, und ein
 * zweiter Lauf legt nichts doppelt an.
 */
class KnowledgeBaseDraftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Vite-Assets werden in der Testumgebung nicht gebaut.
        $this->withoutVite();
    }

    private function leistungsseite(array $ueberschreiben = []): ServicePage
    {
        return ServicePage::create(array_merge([
            'slug' => 'kfz-versicherung',
            'category' => 'versicherung',
            'title_de' => 'Kfz-Versicherung',
            'title_ar' => 'تأمين السيارة',
            'intro_de' => 'Die Kfz-Versicherung schützt vor den finanziellen Folgen eines Unfalls.',
            'intro_ar' => 'تأمين السيارة بيحميك من التبعات المالية للحادث.',
            'highlights_de' => "Haftpflicht ist Pflicht\nTeilkasko bei Diebstahl",
            'highlights_ar' => "تأمين المسؤولية إلزامي\nالتأمين الجزئي للسرقة",
            'providers' => "Allianz\nHUK-COBURG",
            'faq' => [[
                'q_de' => 'Welche Kfz-Versicherung ist Pflicht?',
                'q_ar' => 'أي تأمين سيارة إلزامي؟',
                'a_de' => 'Die Kfz-Haftpflichtversicherung ist gesetzlich vorgeschrieben.',
                'a_ar' => 'تأمين المسؤولية إلزامي قانونياً.',
            ]],
            'is_active' => true,
            'sort_order' => 1,
        ], $ueberschreiben));
    }

    /** Ohne --schreiben passiert nichts - der Befehl zeigt nur. */
    public function test_zeigt_ohne_schreiben_nur_an(): void
    {
        $this->leistungsseite();

        $this->artisan('ki:wissensbasis-vorschlag')->assertExitCode(0);

        $this->assertSame(0, AiKnowledgeEntry::count());
    }

    /** Entwuerfe entstehen INAKTIV - Freigabe bleibt Menschensache. */
    public function test_legt_entwuerfe_inaktiv_an(): void
    {
        $this->leistungsseite();

        $this->artisan('ki:wissensbasis-vorschlag --schreiben')->assertExitCode(0);

        $this->assertGreaterThan(0, AiKnowledgeEntry::count());
        $this->assertSame(0, AiKnowledgeEntry::where('active', true)->count());
    }

    /**
     * Der Inhalt stammt WOERTLICH von der Seite. Waere hier eine
     * Umformulierung erlaubt, stuende in der Wissensbasis eine Aussage,
     * die niemand geprueft hat - und der Assistent gaebe sie weiter.
     */
    public function test_uebernimmt_die_texte_woertlich_in_beiden_sprachen(): void
    {
        $this->leistungsseite();

        $this->artisan('ki:wissensbasis-vorschlag --schreiben')->assertExitCode(0);

        $de = AiKnowledgeEntry::where('source_key', 'servicepage:kfz-versicherung:faq:0:de')->first();
        $this->assertNotNull($de);
        $this->assertSame('faq', $de->category);
        $this->assertSame('de', $de->language);
        $this->assertSame('Welche Kfz-Versicherung ist Pflicht?', $de->title);
        $this->assertStringContainsString('Die Kfz-Haftpflichtversicherung ist gesetzlich vorgeschrieben.', $de->content);

        $ar = AiKnowledgeEntry::where('source_key', 'servicepage:kfz-versicherung:faq:0:ar')->first();
        $this->assertNotNull($ar);
        $this->assertSame('ar', $ar->language);
        $this->assertStringContainsString('تأمين المسؤولية إلزامي قانونياً.', $ar->content);

        $produkt = AiKnowledgeEntry::where('source_key', 'servicepage:kfz-versicherung:produkt:de')->first();
        $this->assertNotNull($produkt);
        $this->assertSame('produkt', $produkt->category);
        $this->assertStringContainsString('Die Kfz-Versicherung schützt', $produkt->content);
        $this->assertStringContainsString('Haftpflicht ist Pflicht', $produkt->content);
        $this->assertStringContainsString('HUK-COBURG', $produkt->content);
    }

    /** Zweiter Lauf legt nichts doppelt an (Quelle ist der Schluessel). */
    public function test_zweiter_lauf_erzeugt_keine_duplikate(): void
    {
        $this->leistungsseite();

        $this->artisan('ki:wissensbasis-vorschlag --schreiben')->assertExitCode(0);
        $anzahl = AiKnowledgeEntry::count();

        $this->artisan('ki:wissensbasis-vorschlag --schreiben')->assertExitCode(0);

        $this->assertSame($anzahl, AiKnowledgeEntry::count());
    }

    /** Von Hand geaenderte Entwuerfe werden nie ueberschrieben. */
    public function test_ruehrt_bestehende_eintraege_nicht_an(): void
    {
        $this->leistungsseite();
        $this->artisan('ki:wissensbasis-vorschlag --schreiben')->assertExitCode(0);

        $eintrag = AiKnowledgeEntry::where('source_key', 'servicepage:kfz-versicherung:faq:0:de')->firstOrFail();
        $eintrag->update(['content' => 'Vom Mitarbeiter korrigierter Text.', 'active' => true]);

        $this->artisan('ki:wissensbasis-vorschlag --schreiben')->assertExitCode(0);

        $eintrag->refresh();
        $this->assertSame('Vom Mitarbeiter korrigierter Text.', $eintrag->content);
        $this->assertTrue($eintrag->active);
    }

    /** Eine inaktive Leistungsseite ist nicht veroeffentlicht - sie zaehlt nicht. */
    public function test_ignoriert_inaktive_leistungsseiten(): void
    {
        $this->leistungsseite(['is_active' => false]);

        $this->artisan('ki:wissensbasis-vorschlag --schreiben')->assertExitCode(1);

        $this->assertSame(0, AiKnowledgeEntry::count());
    }

    /** Entwuerfe bleiben fuer den Assistenten unsichtbar, bis sie frei sind. */
    public function test_entwuerfe_werden_von_der_suche_nicht_gefunden(): void
    {
        $this->leistungsseite();
        $this->artisan('ki:wissensbasis-vorschlag --schreiben')->assertExitCode(0);

        $suche = app(KnowledgeBase::class);
        $this->assertCount(0, $suche->search('Kfz-Versicherung Pflicht', 'de'));

        AiKnowledgeEntry::query()->update(['active' => true]);
        $this->assertGreaterThan(0, $suche->search('Kfz-Versicherung Pflicht', 'de')->count());
    }

    /** Sammelfreigabe: nur das Angekreuzte wird aktiv. */
    public function test_sammelfreigabe_gibt_nur_die_auswahl_frei(): void
    {
        $this->leistungsseite();
        $this->artisan('ki:wissensbasis-vorschlag --schreiben')->assertExitCode(0);

        $admin = User::factory()->create(['role' => 'admin']);
        $freizugeben = AiKnowledgeEntry::where('category', 'faq')->pluck('id')->all();

        $this->actingAs($admin)
            ->post(route('admin.ai_knowledge.bulk'), ['aktion' => 'freigeben', 'ids' => $freizugeben])
            ->assertRedirect();

        $this->assertSame(count($freizugeben), AiKnowledgeEntry::where('active', true)->count());
        $this->assertSame(
            count($freizugeben),
            AiKnowledgeEntry::whereIn('id', $freizugeben)->where('active', true)->count()
        );
    }

    /** Die Pflegeseite zeigt Entwuerfe als solche und bietet die Sammelaktion an. */
    public function test_pflegeseite_zeigt_entwuerfe_und_sammelaktion(): void
    {
        $this->leistungsseite();
        $this->artisan('ki:wissensbasis-vorschlag --schreiben')->assertExitCode(0);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.ai_knowledge', ['status' => 'entwurf']))
            ->assertOk()
            ->assertSee('warten auf Freigabe', false)
            ->assertSee('Entwurf – nicht aktiv', false)
            ->assertSee('Leistungsseite kfz-versicherung', false);
    }

    /** Mitarbeiter duerfen die Wissensbasis nicht freigeben (gilt fuer ALLE Kunden). */
    public function test_mitarbeiter_darf_nicht_sammelfreigeben(): void
    {
        $this->leistungsseite();
        $this->artisan('ki:wissensbasis-vorschlag --schreiben')->assertExitCode(0);

        $mitarbeiter = User::factory()->create(['role' => 'employee']);
        $ids = AiKnowledgeEntry::pluck('id')->all();

        $this->actingAs($mitarbeiter)
            ->post(route('admin.ai_knowledge.bulk'), ['aktion' => 'freigeben', 'ids' => $ids])
            // Die Rollen-Middleware leitet in den erlaubten Bereich um,
            // statt einen Fehler zu zeigen - freigegeben wird nichts.
            ->assertRedirect(route('admin.dashboard'));

        $this->assertSame(0, AiKnowledgeEntry::where('active', true)->count());
    }
}
