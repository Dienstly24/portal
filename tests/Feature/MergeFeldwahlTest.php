<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMerge;
use App\Models\User;
use App\Services\Matching\CustomerMergeService;
use App\Services\Matching\CustomerMergeUndoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feldwahl in der Merge-Vorschau (PR-3c des Dubletten-Plans): bei
 * ABWEICHENDEN Stammdaten waehlt der Admin je Gruppe, welcher Wert gilt.
 * Gruppen (Anschrift ...) gelten nur als Ganzes.
 */
class MergeFeldwahlTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(string $email, string $nummer, array $felder = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Samir Haddad', 'email' => $email]);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => $nummer,
            'birth_date' => '1985-03-03',
        ], $felder));
    }

    private function merge(Customer $haupt, Customer $dup, array $feldwahl = []): void
    {
        app(CustomerMergeService::class)->merge($haupt->fresh('user'), $dup->fresh('user'), null, null, $feldwahl);
    }

    private function anschrift(string $strasse, string $nr, ?string $zusatz, string $plz, string $ort): array
    {
        return ['address_street' => $strasse, 'address_house_number' => $nr, 'address_house_suffix' => $zusatz,
            'address_zip' => $plz, 'address_city' => $ort];
    }

    public function test_ohne_wahl_bleibt_der_hauptkunde_und_die_abweichung_wird_gemeldet(): void
    {
        $haupt = $this->kunde('s1@example.com', 'C-SAM0001', ['phone' => '040111111']);
        $dup = $this->kunde('s2@example.com', 'C-SAM0002', ['phone' => '040222222']);

        $abweichend = app(CustomerMergeService::class)->abweichendeFelder($haupt, $dup);
        $this->assertArrayHasKey('phone', $abweichend);

        $this->merge($haupt, $dup);
        $this->assertSame('040111111', $haupt->fresh()->phone);
    }

    public function test_gewaehlter_wert_des_duplikats_wird_uebernommen(): void
    {
        $haupt = $this->kunde('s1@example.com', 'C-SAM0001', ['phone' => '040111111', 'iban' => 'DE89370400440532013000']);
        $dup = $this->kunde('s2@example.com', 'C-SAM0002', ['phone' => '040222222', 'iban' => 'DE02120300000000202051']);

        $this->merge($haupt, $dup, ['phone' => 'duplikat']);

        $this->assertSame('040222222', $haupt->fresh()->phone);
        $this->assertSame('DE89370400440532013000', $haupt->fresh()->iban, 'Nicht gewaehlt = Hauptkunde bleibt.');
        $protokoll = CustomerMerge::sole()->protokoll;
        $this->assertSame(['phone' => '040111111'], $protokoll['uebernommen']);
    }

    public function test_anschrift_wird_nur_als_ganzes_uebernommen(): void
    {
        $haupt = $this->kunde('s1@example.com', 'C-SAM0001', $this->anschrift('Hauptstr.', '5', 'a', '24103', 'Kiel'));
        $dup = $this->kunde('s2@example.com', 'C-SAM0002', $this->anschrift('Ringweg', '12', null, '24768', 'Rendsburg'));

        $this->merge($haupt, $dup, ['anschrift' => 'duplikat']);

        $h = $haupt->fresh();
        $this->assertSame(['Ringweg', '12', null, '24768', 'Rendsburg'],
            [$h->address_street, $h->address_house_number, $h->address_house_suffix, $h->address_zip, $h->address_city],
            'Der Zusatz "a" der alten Anschrift darf nicht an der neuen haengen bleiben.');
    }

    public function test_behaltene_anschrift_wird_nicht_aus_der_anderen_ergaenzt(): void
    {
        // Vorher: Hauptkunde ohne Hausnummer-Zusatz bekam den Zusatz der ANDEREN
        // Anschrift - eine Adresse, die es nicht gibt.
        $haupt = $this->kunde('s1@example.com', 'C-SAM0001', $this->anschrift('Hauptstr.', '5', null, '24103', 'Kiel'));
        $dup = $this->kunde('s2@example.com', 'C-SAM0002', $this->anschrift('Ringweg', '12', 'b', '24768', 'Rendsburg'));

        $this->merge($haupt, $dup);

        $h = $haupt->fresh();
        $this->assertSame('Hauptstr.', $h->address_street);
        $this->assertNull($h->address_house_suffix);
    }

    public function test_gleiche_anschrift_in_anderer_schreibweise_ist_keine_abweichung(): void
    {
        $haupt = $this->kunde('s1@example.com', 'C-SAM0001', $this->anschrift('Hauptstraße', '5', null, '24103', 'Kiel'));
        $dup = $this->kunde('s2@example.com', 'C-SAM0002', $this->anschrift('Hauptstrasse', '5', null, '24103', 'Kiel'));

        $this->assertArrayNotHasKey('anschrift', app(CustomerMergeService::class)->abweichendeFelder($haupt, $dup));
    }

    public function test_dem_formular_wird_nichts_geglaubt(): void
    {
        $haupt = $this->kunde('s1@example.com', 'C-SAM0001', ['phone' => '040111111', 'mobile' => '0170111']);
        $dup = $this->kunde('s2@example.com', 'C-SAM0002', ['phone' => '040111111', 'mobile' => '0170222']);

        // phone ist gleich (keine Abweichung), "customer_number" keine Gruppe.
        $this->merge($haupt, $dup, ['phone' => 'duplikat', 'customer_number' => 'duplikat', 'mobile' => 'haupt']);

        $h = $haupt->fresh();
        $this->assertSame('C-SAM0001', $h->customer_number);
        $this->assertSame('0170111', $h->mobile);
        $this->assertSame([], CustomerMerge::sole()->protokoll['uebernommen']);
    }

    public function test_rueckgaengig_stellt_den_alten_wert_wieder_her(): void
    {
        $haupt = $this->kunde('s1@example.com', 'C-SAM0001', ['phone' => '040111111']);
        $dup = $this->kunde('s2@example.com', 'C-SAM0002', ['phone' => '040222222']);
        $this->merge($haupt, $dup, ['phone' => 'duplikat']);

        app(CustomerMergeUndoService::class)->rueckgaengig(CustomerMerge::sole(), null);

        $this->assertSame('040111111', $haupt->fresh()->phone);
        $this->assertSame('040222222', Customer::find($dup->id)->phone);
    }

    public function test_rueckgaengig_laesst_einen_seither_gepflegten_wert_stehen(): void
    {
        $haupt = $this->kunde('s1@example.com', 'C-SAM0001', ['phone' => '040111111']);
        $dup = $this->kunde('s2@example.com', 'C-SAM0002', ['phone' => '040222222']);
        $this->merge($haupt, $dup, ['phone' => 'duplikat']);
        $haupt->fresh()->update(['phone' => '040999999']);

        $bilanz = app(CustomerMergeUndoService::class)->rueckgaengig(CustomerMerge::sole(), null);

        $this->assertSame('040999999', $haupt->fresh()->phone);
        $this->assertGreaterThanOrEqual(1, $bilanz['nicht_zurueck']);
    }

    public function test_oberflaeche_zeigt_die_wahl_maskiert_und_uebernimmt_sie(): void
    {
        $haupt = $this->kunde('s1@example.com', 'C-SAM0001', ['phone' => '040111111', 'iban' => 'DE89370400440532013000']);
        $dup = $this->kunde('s2@example.com', 'C-SAM0002', ['phone' => '040222222', 'iban' => 'DE02120300000000202051']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.customer.merge', $haupt->id).'?duplicate='.$dup->id)
            ->assertOk()
            ->assertSee('Abweichende Angaben')
            ->assertSee('name="feldwahl[phone]"', false)
            ->assertSee('3000')
            ->assertDontSee('DE89370400440532013000');

        $this->actingAs($admin)->post(route('admin.customer.merge.do', $haupt->id), [
            'duplicate_id' => $dup->id,
            'feldwahl' => ['phone' => 'duplikat', 'iban' => 'haupt'],
        ])->assertRedirect(route('admin.customer', $haupt->id));

        $this->assertSame('040222222', $haupt->fresh()->phone);
        $this->assertSame('DE89370400440532013000', $haupt->fresh()->iban);
    }

    public function test_ungueltige_wahl_wird_abgelehnt(): void
    {
        $haupt = $this->kunde('s1@example.com', 'C-SAM0001');
        $dup = $this->kunde('s2@example.com', 'C-SAM0002');

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.customer.merge.do', $haupt->id), [
                'duplicate_id' => $dup->id,
                'feldwahl' => ['phone' => 'beides'],
            ])->assertSessionHasErrors('feldwahl.phone');

        $this->assertNotNull(Customer::find($dup->id));
    }
}
