<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\ContractRevision;
use App\Models\Customer;
use App\Models\User;
use App\Models\VertragBeteiligter;
use App\Services\Matching\CustomerMergeService;
use App\Services\Vertrag\VertragBeteiligteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Vertragsrollen (PR-6, 08.10.2026): versicherte Personen, abweichender
 * Beitragszahler, Beguenstigte. Der Versicherungsnehmer bleibt
 * contracts.customer_id und wird nie veraendert.
 */
class VertragBeteiligteTest extends TestCase
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

    private function vertrag(Customer $vn, string $type = 'kranken'): Contract
    {
        return Contract::create(['customer_id' => $vn->id, 'type' => $type, 'insurer' => 'KKH', 'status' => 'active',
            'start_date' => now()->subMonth()->toDateString(), 'premium_amount' => 50, 'premium_interval' => 'monthly']);
    }

    private function service(): VertragBeteiligteService
    {
        return app(VertragBeteiligteService::class);
    }

    public function test_versicherte_personen_mit_und_ohne_akte(): void
    {
        $vater = $this->kunde('Maher Abboud');
        $sohn = $this->kunde('Ahmad Abboud');
        $vertrag = $this->vertrag($vater);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.contract.beteiligte.store', $vertrag->id), [
            'rolle' => 'versicherte_person', 'customer_id' => (string) $sohn->id,
        ])->assertSessionHas('success');
        $this->actingAs($admin)->post(route('admin.contract.beteiligte.store', $vertrag->id), [
            'rolle' => 'versicherte_person', 'name' => 'Lina Abboud', 'geburtsdatum' => '2018-04-02',
        ])->assertSessionHas('success');
        // Der VN darf sich selbst mitversichern.
        $this->actingAs($admin)->post(route('admin.contract.beteiligte.store', $vertrag->id), [
            'rolle' => 'versicherte_person', 'customer_id' => (string) $vater->id,
        ])->assertSessionHas('success');

        $this->assertSame(3, VertragBeteiligter::where('contract_id', $vertrag->id)->count());
        $this->assertSame((string) $vater->id, (string) $vertrag->fresh()->customer_id, 'VN bleibt unveraendert');
        $this->assertSame(3, ContractRevision::where('contract_id', $vertrag->id)->where('field', 'beteiligte_versicherte_person')->count());
        $this->assertSame(3, ActivityLog::where('action', 'contract_party_added')->count());

        $this->actingAs($admin)->get(route('admin.contract.edit', $vertrag->id))
            ->assertOk()->assertSee('Personen am Vertrag')->assertSee('Ahmad Abboud')
            ->assertSee('Lina Abboud')->assertSee('ohne Kundenakte')->assertSee('geb. 02.04.2018');
    }

    public function test_beitragszahler_hoechstens_einer_und_nie_der_vn(): void
    {
        $vn = $this->kunde('Versicherungsnehmer');
        $vertrag = $this->vertrag($vn);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.contract.beteiligte.store', $vertrag->id), [
            'rolle' => 'beitragszahler', 'customer_id' => (string) $vn->id,
        ])->assertSessionHas('error');

        $this->actingAs($admin)->post(route('admin.contract.beteiligte.store', $vertrag->id), [
            'rolle' => 'beitragszahler', 'name' => 'Arbeitgeber GmbH',
        ])->assertSessionHas('success');
        $this->actingAs($admin)->post(route('admin.contract.beteiligte.store', $vertrag->id), [
            'rolle' => 'beitragszahler', 'name' => 'Zweiter Zahler',
        ])->assertSessionHas('error');

        $this->assertSame(1, VertragBeteiligter::where('rolle', 'beitragszahler')->count());
    }

    public function test_anteile_der_beguenstigten_nie_ueber_100(): void
    {
        $vn = $this->kunde('Risiko LV');
        $vertrag = $this->vertrag($vn, 'leben');

        $this->service()->hinzufuegen($vertrag, 'beguenstigter', null, ['name' => 'Ehefrau', 'anteil_prozent' => 60]);
        try {
            $this->service()->hinzufuegen($vertrag, 'beguenstigter', null, ['name' => 'Bruder', 'anteil_prozent' => 50]);
            $this->fail('Mehr als 100 % duerfen nicht entstehen');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('100 %', $e->getMessage());
        }
        $this->service()->hinzufuegen($vertrag, 'beguenstigter', null, ['name' => 'Kind', 'anteil_prozent' => '40']);
        $this->assertSame(100.0, (float) VertragBeteiligter::sum('anteil_prozent'));

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->hinzufuegen($vertrag, 'versicherte_person', null, ['name' => 'X', 'anteil_prozent' => 10]);
    }

    public function test_dieselbe_akte_nicht_zweimal_in_derselben_rolle(): void
    {
        $vn = $this->kunde('VN');
        $kind = $this->kunde('Kind');
        $vertrag = $this->vertrag($vn);
        $this->service()->hinzufuegen($vertrag, 'versicherte_person', $kind, []);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->hinzufuegen($vertrag, 'versicherte_person', $kind, []);
    }

    public function test_ohne_akte_und_ohne_namen_wird_abgelehnt(): void
    {
        $vertrag = $this->vertrag($this->kunde('VN'));
        $this->actingAs($this->admin())->post(route('admin.contract.beteiligte.store', $vertrag->id), [
            'rolle' => 'versicherte_person',
        ])->assertSessionHasErrors('name');
        $this->assertSame(0, VertragBeteiligter::count());
    }

    public function test_portfolio_gilt_fuer_vertrag_und_beteiligte_akte(): void
    {
        $mitarbeiter = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $eigener = $this->kunde('Eigener Kunde');
        $fremder = $this->kunde('Geheimer Fremdkunde');
        $eigener->betreuer()->attach($mitarbeiter->id);
        $eigenerVertrag = $this->vertrag($eigener);
        $fremderVertrag = $this->vertrag($fremder);

        // Eine fremde Akte darf nicht ueber den Vertrag eingehaengt werden.
        $this->actingAs($mitarbeiter)->post(route('admin.contract.beteiligte.store', $eigenerVertrag->id), [
            'rolle' => 'versicherte_person', 'customer_id' => (string) $fremder->id,
        ])->assertForbidden();
        // Fremder Vertrag: weder eintragen noch entfernen.
        $this->actingAs($mitarbeiter)->post(route('admin.contract.beteiligte.store', $fremderVertrag->id), [
            'rolle' => 'versicherte_person', 'name' => 'Irgendwer',
        ])->assertForbidden();
        $fremdEintrag = $this->service()->hinzufuegen($fremderVertrag, 'versicherte_person', null, ['name' => 'Kind']);
        $this->actingAs($mitarbeiter)->delete(route('admin.contract.beteiligte.destroy', $fremdEintrag->id))->assertForbidden();
        $this->assertNotNull($fremdEintrag->fresh());

        // Hat ein Admin die fremde Akte eingetragen, sieht der Mitarbeiter
        // keinen Link und keine Kundennummer.
        $this->service()->hinzufuegen($eigenerVertrag, 'beguenstigter', $fremder, []);
        $this->actingAs($mitarbeiter)->get(route('admin.contract.edit', $eigenerVertrag->id))
            ->assertOk()->assertSee('außerhalb Ihres Bestands')
            ->assertDontSee($fremder->customer_number)
            ->assertDontSee(route('admin.customer', $fremder->id), false);
    }

    public function test_entfernen_loescht_nur_die_angabe_und_bleibt_im_verlauf(): void
    {
        $vn = $this->kunde('VN');
        $kind = $this->kunde('Kind Mit Akte');
        $vertrag = $this->vertrag($vn);
        $b = $this->service()->hinzufuegen($vertrag, 'versicherte_person', $kind, []);

        $this->actingAs($this->admin())->delete(route('admin.contract.beteiligte.destroy', $b->id))
            ->assertSessionHas('success');

        $this->assertNull($b->fresh());
        $this->assertNotNull($kind->fresh(), 'Kundenakte bleibt');
        $rev = ContractRevision::where('contract_id', $vertrag->id)->whereNull('new_value')->sole();
        $this->assertStringContainsString('Kind Mit Akte', (string) $rev->old_value);
        $this->assertSame(1, ActivityLog::where('action', 'contract_party_removed')->count());
    }

    public function test_kundenakte_zeigt_beteiligungen_getrennt_von_eigenen_vertraegen(): void
    {
        $vater = $this->kunde('Vater VN');
        $sohn = $this->kunde('Sohn Versichert');
        $vertrag = $this->vertrag($vater);
        $this->service()->hinzufuegen($vertrag, 'versicherte_person', $sohn, []);

        $this->actingAs($this->admin())->get(route('admin.customer', $sohn->id))
            ->assertOk()->assertSee('Beteiligt an Verträgen anderer Kunden')->assertSee('VN: Vater VN');
        $this->assertSame(0, $sohn->contracts()->count(), 'Kein eigener Vertrag');

        // Auf der Akte des VN erscheint der Vertrag nicht als Beteiligung.
        $this->actingAs($this->admin())->get(route('admin.customer', $vater->id))
            ->assertOk()->assertDontSee('Beteiligt an Verträgen anderer Kunden');
    }

    public function test_geloeschte_akte_hinterlaesst_den_namen(): void
    {
        $vn = $this->kunde('VN');
        $kind = $this->kunde('Name Bleibt');
        $vertrag = $this->vertrag($vn);
        $b = $this->service()->hinzufuegen($vertrag, 'versicherte_person', $kind, []);

        $kind->delete();

        $b = $b->fresh();
        $this->assertNull($b->customer_id);
        $this->assertSame('Name Bleibt', $b->anzeigeName());
    }

    public function test_zusammenfuehrung_haengt_die_beteiligung_um(): void
    {
        $vn = $this->kunde('VN');
        $haupt = $this->kunde('Ahmad Abboud', ['birth_date' => '2002-01-01']);
        $dublette = $this->kunde('Ahmad Abboud', ['birth_date' => '2002-01-01']);
        $vertrag = $this->vertrag($vn);
        $b = $this->service()->hinzufuegen($vertrag, 'versicherte_person', $dublette, []);

        app(CustomerMergeService::class)->merge($haupt, $dublette);

        $this->assertSame((string) $haupt->id, (string) $b->fresh()->customer_id);
    }
}
