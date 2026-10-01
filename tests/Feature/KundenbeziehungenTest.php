<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerFamilyRelation;
use App\Models\CustomerRelationship;
use App\Models\User;
use App\Services\DocumentIntake\DocumentIntakeService;
use App\Services\Family\FamilyRelationService;
use App\Services\Matching\CustomerMergeService;
use App\Services\Matching\DuplicateDetectionService;
use App\Services\Relationships\CustomerRelationshipService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Allgemeine Kundenbeziehungen statt nur "Ehepaar" (Betreiber-Auftrag
 * 01.10.2026): jede Art, symmetrisch vs. gerichtet, Gleichlauf mit den
 * Familienrollen, Altbestand, Dokumenten-Eingang, Migration, Ausschluss aus
 * der Dubletten-Pruefung.
 */
class KundenbeziehungenTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_01_100000_beziehungsarten_an_customer_relationships.php';

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function kunde(string $name, array $attrs = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => Str::uuid().'@example.com']);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(Str::random(8)),
        ], $attrs));
    }

    /** Zwei Kunden, die die Dubletten-Pruefung als Verdachtsfall meldet (gleiche Telefonnummer). */
    private function verdachtsPaar(string $suffix, array $attrsA = [], array $attrsB = []): array
    {
        $phone = '030'.random_int(100000, 999999);

        return [
            $this->kunde('Anna Test'.$suffix, array_merge(['phone' => $phone], $attrsA)),
            $this->kunde('Bernd Test'.$suffix, array_merge(['phone' => $phone], $attrsB)),
        ];
    }

    private function zeilen(Customer $a, Customer $b)
    {
        [$x, $y] = CustomerRelationship::pairKey((string) $a->id, (string) $b->id);

        return CustomerRelationship::where('customer_a_id', $x)->where('customer_b_id', $y)->get();
    }

    private function rolle(Customer $von, Customer $zu): ?string
    {
        return CustomerFamilyRelation::where('customer_id', $von->id)->where('related_customer_id', $zu->id)->value('relationship_type');
    }

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    private function paarIstVerdacht(Customer $a, Customer $b): bool
    {
        foreach (app(DuplicateDetectionService::class)->scan()['pairs'] as $p) {
            $ids = [(string) $p['primary']->id, (string) $p['duplicate']->id];
            if (in_array((string) $a->id, $ids, true) && in_array((string) $b->id, $ids, true)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Arten anlegen + Ausschluss aus der Dubletten-Pruefung
    // ------------------------------------------------------------------

    public function test_jede_art_laesst_sich_festlegen_und_nimmt_das_paar_aus_der_dubletten_pruefung(): void
    {
        $admin = $this->admin();
        foreach (CustomerRelationship::RELATION_TYPES as $i => $type) {
            [$a, $b] = $this->verdachtsPaar((string) $i);
            $this->assertTrue($this->paarIstVerdacht($a, $b), "Vorbedingung: {$type}-Paar ist Verdachtsfall");

            $this->actingAs($admin)->post(route('admin.customers.duplicates.dismiss'), array_filter([
                'customer_a' => (string) $a->id,
                'customer_b' => (string) $b->id,
                'type' => $type,
                'parent_customer_id' => $type === 'elternteil_kind' ? (string) $a->id : null,
                'note' => $type === 'sonstiges' ? 'Arbeitskollegen' : null,
            ]))->assertRedirect()->assertSessionHasNoErrors();

            $zeilen = $this->zeilen($a, $b);
            $this->assertCount(1, $zeilen, $type);
            $this->assertSame($type, $zeilen->first()->type);
            $this->assertFalse($this->paarIstVerdacht($a, $b), "{$type}: Paar darf nicht mehr als Dublette erscheinen");
        }
    }

    public function test_kein_duplikat_bleibt_unveraendert_und_verallgemeinert_keine_beziehung(): void
    {
        [$a, $b] = $this->verdachtsPaar('K');
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.customers.duplicates.dismiss'), ['customer_a' => (string) $a->id, 'customer_b' => (string) $b->id])
            ->assertRedirect();
        $this->assertSame(['not_duplicate'], $this->zeilen($a, $b)->pluck('type')->all());
        $this->assertFalse($this->paarIstVerdacht($a, $b));

        // Ein spaeteres "Kein Duplikat" ueberschreibt eine echte Beziehung NICHT.
        $this->actingAs($admin)->post(route('admin.customers.duplicates.dismiss'), [
            'customer_a' => (string) $a->id, 'customer_b' => (string) $b->id, 'type' => 'geschwister',
        ]);
        $this->actingAs($admin)->post(route('admin.customers.duplicates.dismiss'), ['customer_a' => (string) $b->id, 'customer_b' => (string) $a->id]);
        $this->assertSame(['geschwister'], $this->zeilen($a, $b)->pluck('type')->all());
    }

    // ------------------------------------------------------------------
    // Symmetrisch vs. gerichtet
    // ------------------------------------------------------------------

    public function test_symmetrische_art_wird_unabhaengig_von_der_reihenfolge_nur_einmal_gespeichert(): void
    {
        [$a, $b] = $this->verdachtsPaar('S');
        $admin = $this->admin();
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            $this->actingAs($admin)->post(route('admin.customers.duplicates.dismiss'), [
                'customer_a' => (string) $x->id, 'customer_b' => (string) $y->id, 'type' => 'geschwister',
            ])->assertRedirect();
        }

        $zeilen = $this->zeilen($a, $b);
        $this->assertCount(1, $zeilen);
        $this->assertTrue((string) $zeilen->first()->customer_a_id < (string) $zeilen->first()->customer_b_id);
        $this->assertNull($zeilen->first()->parent_customer_id);
    }

    public function test_elternteil_kind_speichert_die_richtung_ausdruecklich(): void
    {
        [$a, $b] = $this->verdachtsPaar('R');
        // Elternteil ist bewusst die Seite, die NICHT customer_a_id wird.
        [$x, $y] = CustomerRelationship::pairKey((string) $a->id, (string) $b->id);
        $elternteil = $y === (string) $a->id ? $a : $b;
        $kind = $elternteil->is($a) ? $b : $a;

        $this->actingAs($this->admin())->post(route('admin.customers.duplicates.dismiss'), [
            'customer_a' => (string) $kind->id, 'customer_b' => (string) $elternteil->id,
            'type' => 'elternteil_kind', 'parent_customer_id' => (string) $elternteil->id,
        ])->assertSessionHasNoErrors();

        $rel = $this->zeilen($a, $b)->first();
        $this->assertSame((string) $elternteil->id, (string) $rel->parent_customer_id);
        $this->assertSame((string) $y, (string) $rel->customer_b_id);
        $this->assertSame('Kind', $rel->labelFor((string) $elternteil->id));
        $this->assertSame('Elternteil', $rel->labelFor((string) $kind->id));
    }

    public function test_elternteil_muss_einer_der_beiden_kunden_sein(): void
    {
        [$a, $b] = $this->verdachtsPaar('P');
        $fremd = $this->kunde('Fremd Person');

        $this->actingAs($this->admin())->post(route('admin.customers.duplicates.dismiss'), [
            'customer_a' => (string) $a->id, 'customer_b' => (string) $b->id,
            'type' => 'elternteil_kind', 'parent_customer_id' => (string) $fremd->id,
        ])->assertSessionHas('error');
        $this->assertCount(0, $this->zeilen($a, $b));

        // Ohne Elternteil: Pflichtfeld.
        $this->actingAs($this->admin())->post(route('admin.customers.duplicates.dismiss'), [
            'customer_a' => (string) $a->id, 'customer_b' => (string) $b->id, 'type' => 'elternteil_kind',
        ])->assertSessionHasErrors('parent_customer_id');

        // Letzte Sperre im Modell - auch ohne Service.
        [$x, $y] = CustomerRelationship::pairKey((string) $a->id, (string) $b->id);
        try {
            CustomerRelationship::create(['customer_a_id' => $x, 'customer_b_id' => $y, 'type' => 'elternteil_kind', 'parent_customer_id' => (string) $fremd->id]);
            $this->fail('Fremder Elternteil darf nicht gespeichert werden');
        } catch (\InvalidArgumentException) {
        }
        try {
            CustomerRelationship::create(['customer_a_id' => $x, 'customer_b_id' => $y, 'type' => 'geschwister', 'parent_customer_id' => $x]);
            $this->fail('Elternteil nur bei Elternteil – Kind');
        } catch (\InvalidArgumentException) {
        }
        $this->assertCount(0, $this->zeilen($a, $b));
    }

    public function test_paar_und_art_nur_einmal_aber_mehrere_arten_je_paar(): void
    {
        [$a, $b] = $this->verdachtsPaar('U');
        $service = app(CustomerRelationshipService::class);
        $service->set($a, $b, 'geschwister');
        $service->set($a, $b, 'gleicher_haushalt');
        $service->set($b, $a, 'geschwister');

        $this->assertEqualsCanonicalizing(['geschwister', 'gleicher_haushalt'], $this->zeilen($a, $b)->pluck('type')->all());

        // UNIQUE (a, b, type) in der Datenbank.
        [$x, $y] = CustomerRelationship::pairKey((string) $a->id, (string) $b->id);
        $this->expectException(QueryException::class);
        DB::table('customer_relationships')->insert([
            'id' => (string) Str::uuid(), 'customer_a_id' => $x, 'customer_b_id' => $y, 'type' => 'geschwister',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_eine_familienart_ersetzt_die_andere(): void
    {
        [$a, $b] = $this->verdachtsPaar('F');
        $service = app(CustomerRelationshipService::class);
        $service->set($a, $b, 'ehepartner');
        $service->set($a, $b, 'nachbar');
        $service->set($a, $b, 'geschwister');

        $this->assertEqualsCanonicalizing(['geschwister', 'nachbar'], $this->zeilen($a, $b)->pluck('type')->all());
        $this->assertSame('geschwister', $this->rolle($a, $b));
    }

    public function test_sammelaktion_kennt_kein_elternteil_kind(): void
    {
        [$a, $b] = $this->verdachtsPaar('B');
        $this->actingAs($this->admin())->post(route('admin.customers.duplicates.dismiss_bulk'), [
            'pairs' => ["{$a->id}|{$b->id}"], 'type' => 'elternteil_kind',
        ])->assertSessionHasErrors('type');
        $this->assertCount(0, $this->zeilen($a, $b));

        $this->actingAs($this->admin())->post(route('admin.customers.duplicates.dismiss_bulk'), [
            'pairs' => ["{$a->id}|{$b->id}"], 'type' => 'gleicher_haushalt',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['gleicher_haushalt'], $this->zeilen($a, $b)->pluck('type')->all());
    }

    public function test_sonstiges_braucht_eine_beschreibung(): void
    {
        [$a, $b] = $this->verdachtsPaar('O');
        $this->actingAs($this->admin())->post(route('admin.customers.duplicates.dismiss'), [
            'customer_a' => (string) $a->id, 'customer_b' => (string) $b->id, 'type' => 'sonstiges',
        ])->assertSessionHasErrors('note');
        $this->assertCount(0, $this->zeilen($a, $b));
    }

    // ------------------------------------------------------------------
    // Gleichlauf mit den Familienrollen
    // ------------------------------------------------------------------

    public function test_familienart_legt_die_rolle_in_beide_richtungen_an(): void
    {
        [$a, $b] = $this->verdachtsPaar('G');
        $this->actingAs($this->admin())->post(route('admin.customers.duplicates.dismiss'), [
            'customer_a' => (string) $a->id, 'customer_b' => (string) $b->id, 'type' => 'ehepartner',
        ]);

        $this->assertSame('ehepartner', $this->rolle($a, $b));
        $this->assertSame('ehepartner', $this->rolle($b, $a));
    }

    public function test_elternteil_kind_rollen_nach_geschlecht_und_neutral_wenn_unbekannt(): void
    {
        $service = app(CustomerRelationshipService::class);

        $vater = $this->kunde('Omar Najm', ['gender' => 'male', 'birth_date' => '1980-01-01']);
        $tochter = $this->kunde('Lina Najm', ['gender' => 'female', 'birth_date' => '2005-01-01']);
        $service->set($vater, $tochter, 'elternteil_kind', (string) $vater->id);
        $this->assertSame('tochter', $this->rolle($vater, $tochter));
        $this->assertSame('vater', $this->rolle($tochter, $vater));

        $elternteil = $this->kunde('Sam Muster');
        $kind = $this->kunde('Kim Muster', ['gender' => 'diverse']);
        $service->set($kind, $elternteil, 'elternteil_kind', (string) $elternteil->id);
        $this->assertSame('kind', $this->rolle($elternteil, $kind));
        $this->assertSame('elternteil', $this->rolle($kind, $elternteil));
    }

    public function test_aendern_und_entfernen_bleiben_im_gleichlauf(): void
    {
        [$a, $b] = $this->verdachtsPaar('A');
        $service = app(CustomerRelationshipService::class);
        $admin = $this->admin();
        $rel = $service->set($a, $b, 'elternteil_kind', (string) $a->id);
        $this->assertSame('kind', $this->rolle($a, $b));

        // Richtung umdrehen -> Rollen drehen mit.
        $this->actingAs($admin)->post(route('admin.customers.relationships.type', $rel->id), [
            'type' => 'elternteil_kind', 'parent_customer_id' => (string) $b->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame('kind', $this->rolle($b, $a));
        $this->assertSame('elternteil', $this->rolle($a, $b));

        // Familienart -> Geschwister: Rolle wird ersetzt.
        $rel = $this->zeilen($a, $b)->first();
        $this->actingAs($admin)->post(route('admin.customers.relationships.type', $rel->id), ['type' => 'geschwister']);
        $this->assertSame('geschwister', $this->rolle($a, $b));
        $this->assertSame('geschwister', $this->rolle($b, $a));

        // Familienart -> Nachbar: Rolle verschwindet.
        $rel = $this->zeilen($a, $b)->first();
        $this->actingAs($admin)->post(route('admin.customers.relationships.type', $rel->id), ['type' => 'nachbar']);
        $this->assertNull($this->rolle($a, $b));
        $this->assertSame(['nachbar'], $this->zeilen($a, $b)->pluck('type')->all());

        // Entfernen einer Familienart entfernt auch die Rolle.
        $rel = $service->set($a, $b, 'ehepartner');
        $this->actingAs($admin)->delete(route('admin.customers.relationships.delete', $rel->id))->assertRedirect();
        $this->assertNull($this->rolle($a, $b));
        $this->assertNull($this->rolle($b, $a));
        $this->assertSame(['nachbar'], $this->zeilen($a, $b)->pluck('type')->all());
    }

    public function test_registerkarte_familie_schreibt_die_beziehung_mit(): void
    {
        $vater = $this->kunde('Jehad Ebraheem', ['gender' => 'male']);
        $tochter = $this->kunde('Zania Ebraheem', ['gender' => 'female']);
        $family = app(FamilyRelationService::class);

        $family->link($vater, $tochter, 'tochter');
        $rel = $this->zeilen($vater, $tochter)->first();
        $this->assertSame('elternteil_kind', $rel->type);
        $this->assertSame((string) $vater->id, (string) $rel->parent_customer_id);

        // Rolle aendern -> Art folgt.
        $family->link($vater, $tochter, 'ehepartner');
        $this->assertSame(['ehepartner'], $this->zeilen($vater, $tochter)->pluck('type')->all());

        // Loesen: Familienart weg, "kein Duplikat" bleibt wahr.
        $relation = CustomerFamilyRelation::where('customer_id', $vater->id)->first();
        $family->unlink($relation);
        $this->assertSame(['not_duplicate'], $this->zeilen($vater, $tochter)->pluck('type')->all());
        $this->assertSame(0, CustomerFamilyRelation::count());
    }

    // ------------------------------------------------------------------
    // Altbestand (unbestaetigt) und Dokumenten-Eingang
    // ------------------------------------------------------------------

    public function test_altbestand_ehepaar_ohne_rolle_ist_unbestaetigt_und_wird_beim_bestaetigen_vervollstaendigt(): void
    {
        [$a, $b] = $this->verdachtsPaar('L');
        [$x, $y] = CustomerRelationship::pairKey((string) $a->id, (string) $b->id);
        $rel = CustomerRelationship::create(['customer_a_id' => $x, 'customer_b_id' => $y, 'type' => 'ehepartner']);
        $service = app(CustomerRelationshipService::class);
        $admin = $this->admin();

        $this->assertFalse($service->isConfirmed($rel));
        $this->actingAs($admin)->get(route('admin.customers.relationships', ['filter' => 'ehepaar_unbestaetigt']))
            ->assertOk()->assertSee('Ehepaar (unbestätigt) (1)', false)->assertSee($a->user->name)->assertSee('Bestätigen');

        $this->actingAs($admin)->post(route('admin.customers.relationships.confirm', $rel->id))->assertRedirect();
        $this->assertSame('ehepartner', $this->rolle($a, $b));
        $this->assertTrue($service->isConfirmed($rel));
        $this->actingAs($admin)->get(route('admin.customers.relationships', ['filter' => 'ehepaar_unbestaetigt']))
            ->assertOk()->assertDontSee($a->user->name);
    }

    public function test_bearbeiten_eines_altbestands_legt_die_rolle_an(): void
    {
        [$a, $b] = $this->verdachtsPaar('E');
        [$x, $y] = CustomerRelationship::pairKey((string) $a->id, (string) $b->id);
        $rel = CustomerRelationship::create(['customer_a_id' => $x, 'customer_b_id' => $y, 'type' => 'ehepartner']);

        $this->actingAs($this->admin())->post(route('admin.customers.relationships.type', $rel->id), [
            'type' => 'ehepartner', 'note' => 'geprueft',
        ])->assertSessionHasNoErrors();

        $this->assertSame('ehepartner', $this->rolle($a, $b));
        $this->assertSame('geprueft', $this->zeilen($a, $b)->first()->note);
    }

    public function test_dokumenten_eingang_schreibt_sonstige_verwandte_ohne_familienrolle(): void
    {
        $a = $this->kunde('Ahmad Najm');
        $b = $this->kunde('Sara Najm');

        app(DocumentIntakeService::class)->linkSameFamilyName([$a, $b], 'Gesundheitskarten-Stapel', null);

        $rel = $this->zeilen($a, $b)->first();
        $this->assertSame('sonstige_verwandte', $rel->type);
        $this->assertSame(0, CustomerFamilyRelation::count(), 'Die Rolle vergibt ein Mensch - nie der Eingang');
        $this->assertFalse(app(CustomerRelationshipService::class)->isConfirmed($rel));
    }

    public function test_dokumenten_eingang_ueberschreibt_keine_vorhandene_familienart(): void
    {
        $a = $this->kunde('Ahmad Najm');
        $b = $this->kunde('Sara Najm');
        app(CustomerRelationshipService::class)->set($a, $b, 'ehepartner');

        app(DocumentIntakeService::class)->linkSameFamilyName([$a, $b], 'Gesundheitskarten-Stapel', null);

        $this->assertSame(['ehepartner'], $this->zeilen($a, $b)->pluck('type')->all());
        $this->assertSame('ehepartner', $this->rolle($a, $b));
    }

    // ------------------------------------------------------------------
    // Vorschlag, Kundenakte, Zusammenfuehren
    // ------------------------------------------------------------------

    public function test_elternteil_vorschlag_nur_bei_mindestens_16_jahren_abstand(): void
    {
        $alt = $this->kunde('Alt', ['birth_date' => '1970-05-01']);
        $jung = $this->kunde('Jung', ['birth_date' => '1990-05-01']);
        $knapp = $this->kunde('Knapp', ['birth_date' => '1984-06-01']);
        $ohne = $this->kunde('Ohne');

        $this->assertSame((string) $alt->id, CustomerRelationship::suggestParent($jung, $alt));
        $this->assertSame((string) $alt->id, CustomerRelationship::suggestParent($alt, $jung));
        $this->assertNull(CustomerRelationship::suggestParent($alt, $knapp));
        $this->assertNull(CustomerRelationship::suggestParent($alt, $ohne));
    }

    public function test_dubletten_seite_zeigt_beziehung_festlegen_mit_vorschlag(): void
    {
        [$eltern, $kind] = $this->verdachtsPaar('V', ['birth_date' => '1960-01-01'], ['birth_date' => '1995-01-01']);

        $html = $this->actingAs($this->admin())->get(route('admin.customers.duplicates'))->assertOk()->getContent();

        $this->assertStringContainsString('Beziehung festlegen', $html);
        $this->assertStringNotContainsString('💍 Ehepaar</button>', $html);
        $this->assertMatchesRegularExpression('/name="parent_customer_id" value="'.$eltern->id.'"\s+checked/', $html);
        $this->assertStringContainsString('Vorschlag aus den Geburtsdaten', $html);
    }

    /** Im Browser gefunden: ab ZWEI Beziehungen lud die Seite Vertraege nach (Lazy-Loading-Sperre -> 500). */
    public function test_verwandte_kunden_seite_mit_mehreren_beziehungen(): void
    {
        $service = app(CustomerRelationshipService::class);
        foreach (['geschwister', 'nachbar', 'ehepartner'] as $i => $type) {
            [$a, $b] = $this->verdachtsPaar('M'.$i);
            $service->set($a, $b, $type);
        }

        $this->actingAs($this->admin())->get(route('admin.customers.relationships'))
            ->assertOk()->assertSee('Geschwister')->assertSee('Nachbarn')->assertSee('Ehepaar');
    }

    public function test_kundenakte_zeigt_verknuepfte_kunden_und_kann_verknuepfen(): void
    {
        $vater = $this->kunde('Karim Saleh');
        $sohn = $this->kunde('Yusuf Saleh', ['gender' => 'male']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.customer.relationships.store', $vater->id), [
            'related_customer_id' => (string) $sohn->id, 'type' => 'elternteil_kind', 'parent' => 'self', 'note' => 'laut Kunde',
        ])->assertSessionHasNoErrors();

        $rel = $this->zeilen($vater, $sohn)->first();
        $this->assertSame((string) $vater->id, (string) $rel->parent_customer_id);
        $this->assertSame('sohn', $this->rolle($vater, $sohn));

        $this->actingAs($admin)->get(route('admin.customer', $vater->id))
            ->assertOk()->assertSee('Verknüpfte Kunden')->assertSee('Yusuf Saleh')->assertSee('laut Kunde')->assertSee('Kind');
        $this->actingAs($admin)->get(route('admin.customer', $sohn->id))
            ->assertOk()->assertSee('Karim Saleh')->assertSee('Elternteil');

        // Ohne Elternteil-Angabe kein Speichern.
        $dritter = $this->kunde('Dritte Person');
        $this->actingAs($admin)->post(route('admin.customer.relationships.store', $vater->id), [
            'related_customer_id' => (string) $dritter->id, 'type' => 'elternteil_kind',
        ])->assertSessionHasErrors('parent');
    }

    public function test_verknuepfen_nur_im_eigenen_portfolio(): void
    {
        $mitarbeiter = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $eigener = $this->kunde('Eigen Kunde');
        $fremder = $this->kunde('Fremd Kunde');
        $eigener->betreuer()->attach($mitarbeiter->id);

        $this->actingAs($mitarbeiter)->post(route('admin.customer.relationships.store', $eigener->id), [
            'related_customer_id' => (string) $fremder->id, 'type' => 'nachbar',
        ])->assertForbidden();
        $this->assertCount(0, $this->zeilen($eigener, $fremder));
    }

    public function test_zusammenfuehren_haengt_die_richtung_mit_um(): void
    {
        $hauptkunde = $this->kunde('Vater Najm');
        $duplikat = $this->kunde('Vater Najm');
        $kind = $this->kunde('Kind Najm');
        app(CustomerRelationshipService::class)->set($duplikat, $kind, 'elternteil_kind', (string) $duplikat->id);

        app(CustomerMergeService::class)->merge($hauptkunde, $duplikat);

        $rel = $this->zeilen($hauptkunde, $kind)->first();
        $this->assertNotNull($rel);
        $this->assertSame('elternteil_kind', $rel->type);
        $this->assertSame((string) $hauptkunde->id, (string) $rel->parent_customer_id);
    }

    // ------------------------------------------------------------------
    // Migration
    // ------------------------------------------------------------------

    public function test_migration_setzt_den_altbestand_ohne_verlust_um(): void
    {
        $k = fn (string $n) => $this->kunde($n);
        [$e1, $e2] = [$k('Ehe Eins'), $k('Ehe Zwei')];          // spouse, keine Rolle
        [$v, $t] = [$k('Vater Fam'), $k('Tochter Fam')];         // family + Rolle tochter
        [$g1, $g2] = [$k('Bruder Fam'), $k('Schwester Fam')];    // family + Rolle geschwister
        [$f1, $f2] = [$k('Verw Eins'), $k('Verw Zwei')];         // family, keine Rolle
        [$h1, $h2] = [$k('Haus Mutter'), $k('Haus Sohn')];       // household + Rolle sohn
        [$n1, $n2] = [$k('Nicht Eins'), $k('Nicht Zwei')];       // not_duplicate
        [$c1, $c2] = [$k('Konflikt Eins'), $k('Konflikt Zwei')]; // spouse, aber Rolle vater

        $alt = function (Customer $a, Customer $b, string $type) {
            [$x, $y] = CustomerRelationship::pairKey((string) $a->id, (string) $b->id);
            DB::table('customer_relationships')->insert([
                'id' => (string) Str::uuid(), 'customer_a_id' => $x, 'customer_b_id' => $y, 'type' => $type,
                'note' => 'alt', 'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        $rolle = function (Customer $von, Customer $zu, string $role) {
            DB::table('customer_family_relations')->insert([
                'id' => (string) Str::uuid(), 'customer_id' => $von->id, 'related_customer_id' => $zu->id,
                'relationship_type' => $role, 'is_dependent' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        $alt($e1, $e2, 'spouse');
        $alt($v, $t, 'family');
        $rolle($v, $t, 'tochter');
        $rolle($t, $v, 'vater');
        $alt($g1, $g2, 'family');
        $rolle($g1, $g2, 'geschwister');
        $rolle($g2, $g1, 'geschwister');
        $alt($f1, $f2, 'family');
        $alt($h1, $h2, 'household');
        $rolle($h1, $h2, 'sohn');
        $rolle($h2, $h1, 'mutter');
        $alt($n1, $n2, 'not_duplicate');
        $alt($c1, $c2, 'spouse');
        $rolle($c1, $c2, 'vater');
        $rolle($c2, $c1, 'sohn');
        $rollenVorher = CustomerFamilyRelation::count();

        $bericht = $this->migration()->konvertiereDaten();

        $this->assertSame(['ehepartner'], $this->zeilen($e1, $e2)->pluck('type')->all());
        $this->assertNull($this->rolle($e1, $e2), 'Ehepaar-Altbestand bekommt KEINE Rolle');

        $vt = $this->zeilen($v, $t)->first();
        $this->assertSame('elternteil_kind', $vt->type);
        $this->assertSame((string) $v->id, (string) $vt->parent_customer_id);
        $this->assertSame('alt', $vt->note);

        $this->assertSame(['geschwister'], $this->zeilen($g1, $g2)->pluck('type')->all());
        $this->assertSame(['sonstige_verwandte'], $this->zeilen($f1, $f2)->pluck('type')->all());

        $haus = $this->zeilen($h1, $h2)->keyBy('type');
        $this->assertEqualsCanonicalizing(['gleicher_haushalt', 'elternteil_kind'], $haus->keys()->all());
        $this->assertSame((string) $h1->id, (string) $haus['elternteil_kind']->parent_customer_id);

        $this->assertSame(['not_duplicate'], $this->zeilen($n1, $n2)->pluck('type')->all());
        $this->assertSame(['ehepartner'], $this->zeilen($c1, $c2)->pluck('type')->all());

        // Bericht: Zaehlung vorher/nachher, ergaenzte Zeilen, Konflikte.
        $this->assertSame(['family' => 3, 'household' => 1, 'not_duplicate' => 1, 'spouse' => 2], $bericht['vorher']);
        $this->assertSame([
            'ehepartner' => 2, 'elternteil_kind' => 2, 'geschwister' => 1, 'gleicher_haushalt' => 1,
            'not_duplicate' => 1, 'sonstige_verwandte' => 1,
        ], $bericht['nachher']);
        $this->assertSame(1, $bericht['ergaenzt']);
        $this->assertCount(1, $bericht['konflikte']);

        // Nichts geloescht, keine Rolle angelegt.
        $this->assertSame(8, CustomerRelationship::count());
        $this->assertSame($rollenVorher, CustomerFamilyRelation::count());
    }

    public function test_rueckbau_bricht_ab_statt_daten_zu_verlieren(): void
    {
        $migration = $this->migration();
        $service = app(CustomerRelationshipService::class);
        [$a, $b] = [$this->kunde('Rueck A'), $this->kunde('Rueck B')];

        // Rueckfuehrbar: Ehepaar ohne Rolle, Elternteil-Kind MIT Rolle.
        $service->set($a, $b, 'elternteil_kind', (string) $a->id);
        [$c, $d] = [$this->kunde('Rueck C'), $this->kunde('Rueck D')];
        [$x, $y] = CustomerRelationship::pairKey((string) $c->id, (string) $d->id);
        CustomerRelationship::create(['customer_a_id' => $x, 'customer_b_id' => $y, 'type' => 'ehepartner']);
        $this->assertSame([], $migration->pruefeRueckbau());

        // Nicht rueckfuehrbar: neue Art, mehrere Arten je Paar, Richtung ohne Rolle.
        $service->set($c, $d, 'nachbar');
        [$e, $f] = [$this->kunde('Rueck E'), $this->kunde('Rueck F')];
        [$x, $y] = CustomerRelationship::pairKey((string) $e->id, (string) $f->id);
        CustomerRelationship::create(['customer_a_id' => $x, 'customer_b_id' => $y, 'type' => 'elternteil_kind', 'parent_customer_id' => $x]);

        $fehler = $migration->pruefeRueckbau();
        $this->assertCount(3, $fehler);
        $this->assertStringContainsString('mehreren Beziehungsarten', implode(' ', $fehler));
        $this->assertStringContainsString('Nachbar/Sonstiges', implode(' ', $fehler));
        $this->assertStringContainsString('ohne passende Familienrolle', implode(' ', $fehler));
    }
}
