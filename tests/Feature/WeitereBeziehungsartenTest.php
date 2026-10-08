<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerFamilyRelation;
use App\Models\CustomerRelationship;
use App\Models\User;
use App\Services\Family\FamilyRelationService;
use App\Services\Relationships\CustomerRelationshipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PR-5a des Dubletten-/Familien-Plans (08.10.2026): weitere Beziehungsarten -
 * eingetragene Lebenspartnerschaft, Lebensgefaehrten (nicht verheiratet) und
 * Grosselternteil - Enkel (gerichtet). Gleichlauf mit den Familienrollen in
 * beide Richtungen, Rollen nach Geschlecht (unbekannt -> neutral), keine
 * Abhaengigkeit zu den Grosseltern.
 */
class WeitereBeziehungsartenTest extends TestCase
{
    use RefreshDatabase;

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

    private function zeilen(Customer $a, Customer $b)
    {
        [$x, $y] = CustomerRelationship::pairKey((string) $a->id, (string) $b->id);

        return CustomerRelationship::where('customer_a_id', $x)->where('customer_b_id', $y)->get();
    }

    private function rolle(Customer $von, Customer $zu): ?string
    {
        return CustomerFamilyRelation::where('customer_id', $von->id)->where('related_customer_id', $zu->id)->value('relationship_type');
    }

    public function test_partnerarten_legen_die_passende_rolle_in_beide_richtungen_an(): void
    {
        $service = app(CustomerRelationshipService::class);
        foreach (['lebenspartnerschaft' => 'lebenspartner', 'lebensgefaehrten' => 'partner'] as $art => $rolle) {
            $a = $this->kunde('Erste '.$art);
            $b = $this->kunde('Zweite '.$art);
            $rel = $service->set($a, $b, $art);

            $this->assertNull($rel->parent_customer_id, $art.' ist symmetrisch');
            $this->assertSame($rolle, $this->rolle($a, $b));
            $this->assertSame($rolle, $this->rolle($b, $a));
            $this->assertTrue($service->isConfirmed($rel), $art.' ist mit Rolle bestaetigt');
        }
    }

    public function test_grosseltern_enkel_speichert_die_richtung_und_rollen_nach_geschlecht(): void
    {
        $oma = $this->kunde('Fatima Haddad', ['gender' => 'female', 'birth_date' => '1950-03-01']);
        $enkel = $this->kunde('Omar Haddad', ['gender' => 'male', 'birth_date' => '2012-05-01']);

        $this->actingAs($this->admin())->post(route('admin.customer.relationships.store', $enkel->id), [
            'related_customer_id' => (string) $oma->id, 'type' => 'grosseltern_enkel', 'parent' => 'other',
        ])->assertSessionHasNoErrors();

        $rel = $this->zeilen($oma, $enkel)->sole();
        $this->assertSame('grosseltern_enkel', $rel->type);
        $this->assertSame((string) $oma->id, (string) $rel->parent_customer_id);
        $this->assertSame('Enkel', $rel->labelFor((string) $oma->id));
        $this->assertSame('Großelternteil', $rel->labelFor((string) $enkel->id));
        $this->assertSame('Fatima Haddad ist Großelternteil von Omar Haddad', $rel->directionText());

        // "Omar ist Enkel von Fatima", "Fatima ist Grossmutter von Omar".
        $this->assertSame('enkel', $this->rolle($oma, $enkel));
        $this->assertSame('grossmutter', $this->rolle($enkel, $oma));
    }

    public function test_grosseltern_rollen_neutral_wenn_geschlecht_unbekannt(): void
    {
        $gross = $this->kunde('Gross Unbekannt');
        $enkel = $this->kunde('Enkel Unbekannt');
        app(CustomerRelationshipService::class)->set($gross, $enkel, 'grosseltern_enkel', (string) $gross->id);

        $this->assertSame('enkelkind', $this->rolle($gross, $enkel));
        $this->assertSame('grosselternteil', $this->rolle($enkel, $gross));
    }

    public function test_enkel_ist_nie_abhaengig_von_den_grosseltern(): void
    {
        $opa = $this->kunde('Opa Abhaengig', ['gender' => 'male']);
        $enkelin = $this->kunde('Enkelin Klein', ['gender' => 'female', 'birth_date' => now()->subYears(5)->toDateString()]);
        app(CustomerRelationshipService::class)->set($opa, $enkelin, 'grosseltern_enkel', (string) $opa->id);

        $this->assertSame('enkelin', $this->rolle($opa, $enkelin));
        $this->assertFalse((bool) CustomerFamilyRelation::where('customer_id', $opa->id)->value('is_dependent'));
    }

    public function test_grosseltern_enkel_verlangt_eine_richtung(): void
    {
        $a = $this->kunde('Ohne Richtung A');
        $b = $this->kunde('Ohne Richtung B');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.customers.duplicates.dismiss'), [
            'customer_a' => (string) $a->id, 'customer_b' => (string) $b->id, 'type' => 'grosseltern_enkel',
        ])->assertSessionHasErrors('parent_customer_id');
        $this->actingAs($admin)->post(route('admin.customer.relationships.store', $a->id), [
            'related_customer_id' => (string) $b->id, 'type' => 'grosseltern_enkel',
        ])->assertSessionHasErrors('parent');

        // Letzte Sperre im Modell.
        [$x, $y] = CustomerRelationship::pairKey((string) $a->id, (string) $b->id);
        $this->expectException(\InvalidArgumentException::class);
        CustomerRelationship::create(['customer_a_id' => $x, 'customer_b_id' => $y, 'type' => 'grosseltern_enkel']);
    }

    public function test_sammelaktion_kennt_keine_gerichtete_art(): void
    {
        $this->assertNotContains('grosseltern_enkel', CustomerRelationship::BULK_TYPES);
        $this->assertContains('lebensgefaehrten', CustomerRelationship::BULK_TYPES);
        $this->assertContains('lebenspartnerschaft', CustomerRelationship::BULK_TYPES);
    }

    public function test_registerkarte_familie_schreibt_die_neuen_arten_mit(): void
    {
        $opa = $this->kunde('Ali Nasser', ['gender' => 'male']);
        $enkel = $this->kunde('Sami Nasser', ['gender' => 'male']);
        $family = app(FamilyRelationService::class);

        // "Ali ist Grossvater von Sami" aus Sicht von Sami.
        $family->link($enkel, $opa, 'grossvater');
        $rel = $this->zeilen($opa, $enkel)->sole();
        $this->assertSame('grosseltern_enkel', $rel->type);
        $this->assertSame((string) $opa->id, (string) $rel->parent_customer_id);
        $this->assertSame('enkel', $this->rolle($opa, $enkel));

        $family->link($opa, $enkel, 'partner');
        $this->assertSame(['lebensgefaehrten'], $this->zeilen($opa, $enkel)->pluck('type')->all());
    }

    public function test_familienkarte_zeigt_enkel_und_grosseltern_in_eigenen_gruppen(): void
    {
        $oma = $this->kunde('Mona Saad', ['gender' => 'female']);
        $enkel = $this->kunde('Karim Saad', ['gender' => 'male']);
        $partner = $this->kunde('Rami Saad', ['gender' => 'male']);
        $service = app(CustomerRelationshipService::class);
        $service->set($oma, $enkel, 'grosseltern_enkel', (string) $oma->id);
        $service->set($oma, $partner, 'lebensgefaehrten');

        $uebersicht = app(FamilyRelationService::class)->overview($oma);
        $this->assertCount(1, $uebersicht['grandchildren']);
        $this->assertCount(1, $uebersicht['spouses']);
        $this->assertCount(0, $uebersicht['others']);

        $this->actingAs($this->admin())->get(route('admin.customer', $oma->id))
            ->assertOk()->assertSee('Enkel')->assertSee('Karim Saad')->assertSee('Lebensgefährte/in');
        $this->actingAs($this->admin())->get(route('admin.customer', $enkel->id))
            ->assertOk()->assertSee('Großmutter')->assertSee('Großeltern');
    }

    public function test_dialog_bietet_die_neuen_arten_an(): void
    {
        $a = $this->kunde('Dialog A');
        $b = $this->kunde('Dialog B');
        app(CustomerRelationshipService::class)->markNotDuplicate((string) $a->id, (string) $b->id);

        $this->actingAs($this->admin())->get(route('admin.customer', $a->id))
            ->assertOk()
            ->assertSee('Eingetragene Lebenspartnerschaft')
            ->assertSee('Lebensgefährten (nicht verheiratet)')
            ->assertSee('Großelternteil – Enkel');
    }
}
