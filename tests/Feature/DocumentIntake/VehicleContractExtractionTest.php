<?php

namespace Tests\Feature\DocumentIntake;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\Ai\ClaudeDocumentAiProvider;
use App\Services\DocumentIntake\DocumentIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Anreicherung des Kfz-Vertrags aus dem Dokument: neben den Fahrzeug-
 * Basisdaten werden auch die klar abgrenzbaren Tarif-/Fahrzeugfakten
 * (Teilkasko + SB, Halterart, jaehrliche Fahrleistung) uebernommen -
 * ungenaue/geschaetzte Angaben werden hart validiert und verworfen.
 */
class VehicleContractExtractionTest extends TestCase
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

    public function test_contract_creation_captures_reliable_tariff_fields(): void
    {
        $customer = $this->customer();
        $doc = Document::create([
            'customer_id' => null,
            'category' => 'contract',
            'file_name' => 'protokoll.pdf',
            'file_path' => 'documents/eingang/protokoll.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'kfz_vertrag',
            'ai_extracted' => [
                'versicherung' => ['insurer' => 'HUK24', 'sparte' => 'kfz', 'start_date' => '2026-07-16'],
                'kfz' => [
                    'license_plate' => 'S-AB 1234', 'hsn' => '0603', 'tsn' => 'AMK',
                    'has_teilkasko' => true, 'teilkasko_deductible' => 150,
                    'has_vollkasko' => false, 'holder_type' => 'versicherungsnehmer',
                    'annual_mileage' => 5000,
                ],
            ],
        ]);

        $contract = app(DocumentIntakeService::class)->createContractFromExtraction($doc, $customer, null);

        $this->assertNotNull($contract);
        $this->assertSame('kfz', $contract->type);
        $veh = $contract->vehicleDetail;
        $this->assertNotNull($veh);
        $this->assertTrue((bool) $veh->has_teilkasko);
        $this->assertSame(150, (int) $veh->teilkasko_deductible);
        $this->assertFalse((bool) $veh->has_vollkasko);
        $this->assertSame('versicherungsnehmer', $veh->holder_type);
        $this->assertSame(5000, (int) $veh->annual_mileage);
    }

    public function test_extraction_rejects_implausible_vehicle_values(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode([
                    'type' => 'kfz_vertrag',
                    'confidence' => 90,
                    'summary' => 'Kfz',
                    'title' => 'Kfz',
                    'data' => ['kfz' => [
                        'has_teilkasko' => 'ja',              // -> true
                        'teilkasko_deductible' => 150,        // -> uebernommen
                        'annual_mileage' => 9999999,          // unplausibel -> verworfen
                        'holder_type' => 'irgendwas',         // nicht in Whitelist -> verworfen
                    ]],
                ])]],
            ]),
        ]);

        $result = (new ClaudeDocumentAiProvider())->analyze('BYTES', 'application/pdf', '', false);

        $kfz = $result['data']['kfz'];
        $this->assertTrue($kfz['has_teilkasko']);
        $this->assertSame(150, $kfz['teilkasko_deductible']);
        $this->assertArrayNotHasKey('annual_mileage', $kfz);
        $this->assertArrayNotHasKey('holder_type', $kfz);
    }
}
