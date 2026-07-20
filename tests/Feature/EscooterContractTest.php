<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractVehicleDetail;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E-Scooter-Vertrag ueber die Oberflaeche: Anlegen im Backend (Fachregeln
 * Saison-Ablauf + Einmalbeitrag), Bearbeiten ohne Datenverlust sowie die
 * Anzeige im Kundenportal und im Backend-Cockpit.
 */
class EscooterContractTest extends TestCase
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
            'customer_number' => 'C-' . strtoupper(substr(md5((string) $user->id), 0, 8)),
        ]);
    }

    private function escooterPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'escooter',
            'insurer' => 'die Bayerische',
            'status' => 'active',
            'start_date' => '2026-07-20',
            'end_date' => '2027-01-01', // absichtlich falsch - Fachregel muss ueberschreiben
            'premium_amount' => '41.60',
            'premium_interval' => 'einmalig',
            'escooter' => [
                'license_plate' => '611 MDS',
                'manufacturer' => 'ZHEJIANG KUANTU (RC)',
                'vin' => 'ZSF10Z23075358',
                'has_teilkasko' => '0',
            ],
        ], $overrides);
    }

    public function test_admin_creates_escooter_contract_with_season_end_and_vehicle(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())
            ->post(route('admin.contract.store', $customer->id), $this->escooterPayload())
            ->assertRedirect(route('admin.customer', $customer->id))
            ->assertSessionHas('success');

        $contract = Contract::where('customer_id', $customer->id)->where('type', 'escooter')->firstOrFail();
        // Fachregel: Ablauf immer Ende Februar der Saison, egal was gesendet wurde.
        $this->assertSame('2027-02-28', \Carbon\Carbon::parse($contract->end_date)->format('Y-m-d'));
        $this->assertSame('einmalig', $contract->premium_interval);

        $veh = $contract->vehicleDetail;
        $this->assertNotNull($veh);
        $this->assertSame('escooter', $veh->vehicle_type);
        $this->assertSame('611 MDS', $veh->license_plate);
        $this->assertSame('ZSF10Z23075358', $veh->vin);
        $this->assertFalse((bool) $veh->has_vollkasko);
    }

    public function test_editing_escooter_contract_keeps_vehicle_detail(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($this->admin())->post(route('admin.contract.store', $customer->id), $this->escooterPayload());
        $contract = Contract::where('customer_id', $customer->id)->where('type', 'escooter')->firstOrFail();

        // Bearbeiten (z.B. Teilkasko nachtragen) darf das Fahrzeugdetail nicht loeschen.
        $this->actingAs($this->admin())->put(route('admin.contract.update', $contract->id), $this->escooterPayload([
            'escooter' => [
                'license_plate' => '611 MDS',
                'manufacturer' => 'ZHEJIANG KUANTU (RC)',
                'vin' => 'ZSF10Z23075358',
                'has_teilkasko' => '1',
            ],
        ]))->assertRedirect();

        $veh = $contract->fresh()->vehicleDetail;
        $this->assertNotNull($veh);
        $this->assertTrue((bool) $veh->has_teilkasko);
        $this->assertSame('ZSF10Z23075358', $veh->vin);
    }

    public function test_portal_shows_escooter_details(): void
    {
        $customer = $this->makeCustomer();
        $contract = Contract::create([
            'customer_id' => $customer->id, 'type' => 'escooter', 'insurer' => 'die Bayerische',
            'status' => 'active', 'start_date' => '2026-07-20', 'premium_amount' => 41.60, 'premium_interval' => 'einmalig',
        ]);
        ContractVehicleDetail::create([
            'contract_id' => $contract->id, 'vehicle_type' => 'escooter',
            'license_plate' => '611 MDS', 'manufacturer' => 'ZHEJIANG KUANTU (RC)', 'vin' => 'ZSF10Z23075358',
            'has_teilkasko' => false,
        ]);

        $html = $this->actingAs($customer->user)->get(route('portal.contracts.show', $contract->id))->assertOk()->getContent();

        $this->assertStringContainsString('E-Scooter', $html);
        $this->assertStringContainsString('611 MDS', $html);
        $this->assertStringContainsString('ZSF10Z23075358', $html);
        $this->assertStringContainsString('41,60', $html);
        // Einmalbeitrag: kein irrefuehrender "pro Monat"-Wert.
        $this->assertStringNotContainsString('Beitrag pro Monat', $html);
    }

    public function test_admin_edit_page_renders_escooter_cockpit(): void
    {
        $customer = $this->makeCustomer();
        $contract = Contract::create([
            'customer_id' => $customer->id, 'type' => 'escooter', 'insurer' => 'die Bayerische',
            'status' => 'active', 'start_date' => '2026-07-20', 'premium_amount' => 41.60, 'premium_interval' => 'einmalig',
        ]);
        ContractVehicleDetail::create([
            'contract_id' => $contract->id, 'vehicle_type' => 'escooter',
            'license_plate' => '611 MDS', 'vin' => 'ZSF10Z23075358', 'has_teilkasko' => false,
        ]);

        $html = $this->actingAs($this->admin())->get(route('admin.contract.edit', $contract->id))->assertOk()->getContent();

        $this->assertStringContainsString('611 MDS', $html);
        $this->assertStringContainsString('Versicherungskennzeichen', $html);
    }
}
