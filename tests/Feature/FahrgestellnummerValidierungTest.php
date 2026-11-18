<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Betreiber-Meldung 18.11.2026: bei der Erfassung wird in der FIN "O"
 * statt der Ziffer "0" getippt. Eine FIN (ISO 3779) enthaelt NIE die
 * Buchstaben I, O und Q - solche Eingaben werden abgelehnt.
 */
class FahrgestellnummerValidierungTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(substr(md5((string) $user->id), 0, 8)),
        ]);
    }

    private function kfz(string $vin): array
    {
        return [
            'type' => 'kfz',
            'origin' => 'brokered',
            'insurer' => 'HUK-Coburg',
            'status' => 'active',
            'vehicle' => ['vehicle_type' => 'pkw', 'license_plate' => 'HH-AB 1234', 'vin' => $vin],
        ];
    }

    public function test_fin_mit_buchstabe_o_statt_null_wird_abgelehnt(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())
            ->post(route('admin.contract.store', $customer->id), $this->kfz('WFOPXXWPDP8C19527'))
            ->assertSessionHasErrors('vehicle.vin');

        $this->assertSame(0, Contract::where('customer_id', $customer->id)->count());
    }

    public function test_fin_mit_i_oder_q_wird_abgelehnt(): void
    {
        $customer = $this->makeCustomer();
        $admin = $this->admin();

        foreach (['WVWZZZAUZLWI00001', 'WVWZZZAUZLWQ00001', 'wfopxxwpdp8c19527'] as $vin) {
            $this->actingAs($admin)
                ->post(route('admin.contract.store', $customer->id), $this->kfz($vin))
                ->assertSessionHasErrors('vehicle.vin');
        }
    }

    public function test_korrekte_fin_wird_gespeichert_und_grossgeschrieben(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())
            ->post(route('admin.contract.store', $customer->id), $this->kfz('wf0pxxwpdp8c19527'))
            ->assertSessionHasNoErrors();

        $veh = Contract::where('customer_id', $customer->id)->firstOrFail()->vehicleDetail;
        $this->assertSame('WF0PXXWPDP8C19527', $veh->vin);
    }

    public function test_escooter_fin_mit_o_wird_abgelehnt(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())
            ->post(route('admin.contract.store', $customer->id), [
                'type' => 'escooter',
                'origin' => 'brokered',
                'insurer' => 'die Bayerische',
                'status' => 'active',
                'start_date' => '2026-07-20',
                'premium_amount' => '41.60',
                'premium_interval' => 'einmalig',
                'escooter' => ['license_plate' => '611 MDS', 'vin' => 'ZSF1OZ23075358'],
            ])
            ->assertSessionHasErrors('escooter.vin');
    }

    public function test_formular_weist_schon_im_browser_auf_die_regel_hin(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())
            ->get(route('admin.contract.create', $customer->id))
            ->assertOk()
            ->assertSee('name="vehicle[vin]" maxlength="30" pattern="[^IiOoQq]*"', false);
    }
}
