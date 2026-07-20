<?php

namespace Tests\Feature\DocumentIntake;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentIntake\DocumentIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Automatische Anlage eines E-Scooter-Vertrags aus dem Dokumenten-Eingang:
 * die Fahrzeugtabelle wird mit Fahrzeugtyp "escooter" befuellt (Kennzeichen,
 * FIN, Hersteller, Deckung), der Beitrag ist einmalig und der Ablauf wird auf
 * das Saison-Ende (Ende Februar) gesetzt.
 */
class EscooterContractExtractionTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(Str::random(6)),
        ]);
    }

    private function document(): Document
    {
        return Document::create([
            'customer_id' => null,
            'category' => 'contract',
            'file_name' => 'escooter.pdf',
            'file_path' => 'documents/eingang/escooter.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'escooter_vertrag',
            'ai_extracted' => [
                'versicherung' => [
                    'sparte' => 'escooter',
                    'insurer' => 'die Bayerische',
                    'start_date' => '2026-07-20',
                    'end_date' => '2027-02-28',
                    'premium_amount' => 41.60,
                    'premium_interval' => 'einmalig',
                    'tariff' => 'Haftpflicht',
                ],
                'kfz' => [
                    'vin' => 'ZSF10Z23075358',
                    'license_plate' => '611MDS',
                    'manufacturer' => 'ZHEJIANG KUANTU (RC)',
                    'has_teilkasko' => false,
                ],
            ],
        ]);
    }

    public function test_creates_escooter_contract_with_vehicle_detail(): void
    {
        $customer = $this->customer();
        $contract = app(DocumentIntakeService::class)->createContractFromExtraction($this->document(), $customer, null);

        $this->assertNotNull($contract);
        $this->assertSame('escooter', $contract->type);
        $this->assertSame('die Bayerische', $contract->insurer);
        $this->assertSame('einmalig', $contract->premium_interval);
        $this->assertTrue($contract->isOneTime());
        $this->assertEquals(41.60, (float) $contract->premium_amount);

        $veh = $contract->vehicleDetail;
        $this->assertNotNull($veh);
        $this->assertSame('escooter', $veh->vehicle_type);
        $this->assertSame('611MDS', $veh->license_plate);
        $this->assertSame('ZSF10Z23075358', $veh->vin);
        $this->assertSame('ZHEJIANG KUANTU (RC)', $veh->manufacturer);
        $this->assertFalse((bool) $veh->has_teilkasko);
        $this->assertFalse((bool) $veh->has_vollkasko);
    }

    public function test_end_date_is_forced_to_end_of_february(): void
    {
        $customer = $this->customer();
        $contract = app(DocumentIntakeService::class)->createContractFromExtraction($this->document(), $customer, null);

        $this->assertSame('2027-02-28', \Carbon\Carbon::parse($contract->end_date)->format('Y-m-d'));
    }

    public function test_end_date_follows_a_changed_start_date(): void
    {
        // Auch bei manueller Aenderung des Beginns zieht der Saison-Ablauf nach
        // (Contract::saving). Beginn Maerz 2027 -> Ende Februar 2028 (Schaltjahr).
        $customer = $this->customer();
        $contract = app(DocumentIntakeService::class)->createContractFromExtraction($this->document(), $customer, null);

        $contract->update(['start_date' => '2027-03-10']);
        $this->assertSame('2028-02-29', \Carbon\Carbon::parse($contract->fresh()->end_date)->format('Y-m-d'));
    }
}
