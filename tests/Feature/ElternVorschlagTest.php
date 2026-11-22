<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerFamilyRelation;
use App\Models\User;
use App\Services\Family\FamilyRelationService;
use App\Support\FamilienName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Betreiber-Vorgabe 22.11.2026: "warum verknuepft das System die Kunden
 * nicht, wenn der Familienname gleich ist? Bei Kindern soll es sofort
 * moegliche Eltern vorschlagen." Vorher kannte die Familienkarte nur Akten,
 * die schon als "verwandt" markiert waren - ein einzeln angelegtes Kind
 * stand ohne jeden Vorschlag da.
 */
class ElternVorschlagTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(string $name, ?string $geburt, array $extra = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => Str::slug($name).Str::random(4).'@example.com']);

        return Customer::create(['user_id' => $user->id, 'customer_number' => (string) random_int(2600000, 2699999), 'birth_date' => $geburt] + $extra);
    }

    private function jahreAlt(int $jahre): string
    {
        return Carbon::today()->subYears($jahre)->subDays(10)->toDateString();
    }

    public function test_vater_mit_gleichem_familiennamen_wird_vorgeschlagen(): void
    {
        $vater = $this->kunde('Karim Haddad', $this->jahreAlt(45), ['gender' => 'male']);
        $kind = $this->kunde('Lina Haddad', $this->jahreAlt(9));

        $vorschlaege = app(FamilyRelationService::class)->elternVorschlaege($kind);

        $this->assertCount(1, $vorschlaege, 'Vorher: kein Vorschlag, obwohl der Familienname gleich ist.');
        $this->assertSame((string) $vater->id, (string) $vorschlaege[0]['customer']->id);
        $this->assertSame('vater', $vorschlaege[0]['rolle']);
        $this->assertContains('Gleicher Familienname', $vorschlaege[0]['gruende']);
    }

    public function test_mutter_mit_anderem_nachnamen_an_derselben_anschrift_wird_vorgeschlagen(): void
    {
        $adresse = ['address_street' => 'Hauptstraße', 'address_house_number' => '5', 'address_zip' => '24103', 'address_city' => 'Kiel'];
        $mutter = $this->kunde('Samira Mansour', $this->jahreAlt(40), ['gender' => 'female'] + $adresse);
        $kind = $this->kunde('Lina Haddad', $this->jahreAlt(9), ['address_street' => 'Hauptstr.'] + $adresse);

        $vorschlaege = app(FamilyRelationService::class)->elternVorschlaege($kind);

        $this->assertSame([(string) $mutter->id], $vorschlaege->map(fn ($v) => (string) $v['customer']->id)->all());
        $this->assertSame('mutter', $vorschlaege[0]['rolle']);
        $this->assertContains('Gleiche Anschrift', $vorschlaege[0]['gruende']);
    }

    public function test_schreibweisen_des_artikels_gelten_als_gleich(): void
    {
        $this->assertTrue(FamilienName::gleich('Mona Alnajjar', 'Samir Al Najjar'));
        $this->assertTrue(FamilienName::gleich('Mona Al-Najjar', 'Samir Alnajjar'));
        $this->assertFalse(FamilienName::gleich('Mona Haddad', 'Samir Mansour'));
        $this->assertFalse(FamilienName::gleich('Ali', ''), 'Ohne Namen nie ein Treffer.');
    }

    public function test_zu_junge_und_minderjaehrige_kandidaten_sind_keine_eltern(): void
    {
        $this->kunde('Omar Haddad', $this->jahreAlt(20), ['gender' => 'male']);  // nur 11 Jahre Abstand
        $this->kunde('Rami Haddad', $this->jahreAlt(12), ['gender' => 'male']);  // Geschwister
        $kind = $this->kunde('Lina Haddad', $this->jahreAlt(9));

        $this->assertCount(0, app(FamilyRelationService::class)->elternVorschlaege($kind));
    }

    public function test_erwachsene_und_kinder_mit_verknuepftem_elternteil_bekommen_keine_vorschlaege(): void
    {
        $vater = $this->kunde('Karim Haddad', $this->jahreAlt(45), ['gender' => 'male']);
        $this->kunde('Rana Haddad', $this->jahreAlt(42), ['gender' => 'female']);
        $erwachsen = $this->kunde('Tarek Saleh', $this->jahreAlt(30));
        $kind = $this->kunde('Lina Haddad', $this->jahreAlt(9));
        $service = app(FamilyRelationService::class);

        $this->assertCount(0, $service->elternVorschlaege($erwachsen), 'Kein Kind -> keine Elternvorschlaege.');
        $this->assertCount(2, $service->elternVorschlaege($kind));

        $service->link($kind, $vater, 'vater');
        $this->assertCount(0, $service->elternVorschlaege($kind->fresh()), 'Ein Elternteil ist verknuepft -> die Liste verschwindet.');
    }

    public function test_ohne_geburtsdatum_des_kandidaten_mit_hinweis(): void
    {
        $this->kunde('Karim Haddad', null, ['gender' => 'male']);
        $kind = $this->kunde('Lina Haddad', $this->jahreAlt(9));

        $v = app(FamilyRelationService::class)->elternVorschlaege($kind);
        $this->assertCount(1, $v);
        $this->assertContains('Geburtsdatum fehlt - bitte pruefen', $v[0]['gruende']);
    }

    public function test_kundenakte_zeigt_vorschlag_und_verknuepfen_legt_die_rolle_an(): void
    {
        $vater = $this->kunde('Karim Haddad', $this->jahreAlt(45), ['gender' => 'male']);
        $kind = $this->kunde('Lina Haddad', $this->jahreAlt(9));
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.customer', $kind->id))
            ->assertOk()
            ->assertSee('Mögliche Eltern')
            ->assertSee('Als Elternteil verknüpfen');

        $this->actingAs($admin)->post(route('admin.customer.family.link', $kind->id), [
            'related_customer_id' => (string) $vater->id,
            'relationship_type' => 'vater',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $zeilen = CustomerFamilyRelation::all()
            ->map(fn ($r) => ($r->customer_id == $kind->id ? 'kind' : 'vater').'->'.($r->related_customer_id == $kind->id ? 'kind' : 'vater').':'.$r->relationship_type)
            ->implode(', ');
        $this->assertTrue(CustomerFamilyRelation::where('customer_id', $kind->id)
            ->where('related_customer_id', $vater->id)->where('relationship_type', 'vater')->exists(), 'Gespeichert: ['.$zeilen.']');
    }
}
