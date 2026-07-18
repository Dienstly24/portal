<?php

namespace Tests\Feature\DocumentIntake;

use App\Models\ContractEnergyDetail;
use App\Models\ContractHistory;
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
 * Energie-Zweig (Strom/Gas): Auftrag-Extraktion (Zaehler, MaLo, Verbrauch,
 * Tarif, Abschlag) -> ContractEnergyDetail; Zaehlerfoto (Nummer + Stand);
 * harte Validierung unplausibler Werte.
 */
class EnergyContractExtractionTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);
        return Customer::create(['user_id' => $user->id, 'customer_number' => 'C-' . strtoupper(Str::random(6))]);
    }

    public function test_energy_contract_gets_detail_from_auftrag(): void
    {
        $customer = $this->customer();
        $doc = Document::create([
            'customer_id' => null, 'category' => 'contract', 'file_name' => 'auftrag.pdf',
            'file_path' => 'documents/eingang/a.pdf', 'disk' => 'local', 'ai_status' => 'done',
            'ai_type' => 'energieauftrag',
            'ai_extracted' => [
                'versicherung' => ['insurer' => 'LichtBlick', 'sparte' => 'strom', 'start_date' => '2026-08-01',
                    'premium_amount' => 31.44, 'premium_interval' => 'monthly'],
                'energie' => ['meter_number' => '1EMH0012345678', 'malo_id' => '51234567890',
                    'consumption_kwh' => 2300, 'tariff' => 'OekoStrom', 'customer_number' => 'K-889977'],
            ],
        ]);

        $contract = app(DocumentIntakeService::class)->createContractFromExtraction($doc, $customer, null);

        $this->assertNotNull($contract);
        $this->assertSame('strom', $contract->type);
        $this->assertSame('LichtBlick', $contract->insurer);

        $detail = ContractEnergyDetail::where('contract_id', $contract->id)->first();
        $this->assertNotNull($detail);
        $this->assertSame('1EMH0012345678', $detail->meter_number);
        $this->assertSame('51234567890', $detail->malo_id);
        $this->assertSame(2300, (int) $detail->consumption_kwh);
        $this->assertSame('OekoStrom', $detail->tariff);
        $this->assertSame('K-889977', $detail->customer_number);
        $this->assertSame(31.44, (float) $detail->payment_amount);
        $this->assertSame('monthly', $detail->payment_interval);

        // Verlauf beginnt mit dem Lieferbeginn.
        $history = ContractHistory::where('customer_id', $customer->id)->where('branch', 'strom')->first();
        $this->assertNotNull($history);
        $this->assertSame('LichtBlick', $history->provider);
        $this->assertSame('2026-08-01', $history->effective_from?->toDateString());
    }

    public function test_zaehlerfoto_data_flows_into_energy_detail(): void
    {
        $customer = $this->customer();
        // Zaehlerfoto liefert Nummer + Stand; Versorger kommt z.B. aus dem
        // zusammengefuehrten Auftrag desselben Vorgangs.
        $doc = Document::create([
            'customer_id' => null, 'category' => 'other', 'file_name' => 'zaehler.jpg',
            'file_path' => 'documents/eingang/z.jpg', 'disk' => 'local', 'ai_status' => 'done',
            'ai_type' => 'zaehlerfoto',
            'ai_extracted' => [
                'versicherung' => ['insurer' => 'EWE', 'sparte' => 'gas'],
                'energie' => ['meter_number' => 'GAS-778899', 'meter_reading' => 59435.8],
            ],
        ]);

        $contract = app(DocumentIntakeService::class)->createContractFromExtraction($doc, $customer, null);

        $detail = ContractEnergyDetail::where('contract_id', $contract->id)->first();
        $this->assertSame('GAS-778899', $detail->meter_number);
        $this->assertSame(59435.8, (float) $detail->meter_reading);
    }

    public function test_extraction_rejects_implausible_energy_values(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode([
                    'type' => 'energieauftrag', 'confidence' => 90, 'summary' => 'x', 'title' => null,
                    'data' => ['energie' => [
                        'meter_number' => '1EMH0012345678',
                        'malo_id' => 'ABC123',              // kein 11-Ziffern-Format -> verworfen
                        'consumption_kwh' => 5000000,       // unplausibel -> verworfen
                        'meter_reading' => 59435.8,         // ok
                    ]],
                ])]],
            ]),
        ]);

        $result = (new ClaudeDocumentAiProvider())->analyze('BYTES', 'application/pdf', '', false);

        $energie = $result['data']['energie'];
        $this->assertSame('1EMH0012345678', $energie['meter_number']);
        $this->assertSame(59435.8, $energie['meter_reading']);
        $this->assertArrayNotHasKey('malo_id', $energie);
        $this->assertArrayNotHasKey('consumption_kwh', $energie);
    }

    public function test_merge_combines_auftrag_and_zaehlerfoto(): void
    {
        // Auftrag (Tarif/Verbrauch) + Zaehlerfoto (Nummer/Stand) in EINEM
        // Vorgang -> zusammengefuehrtes energie-Ergebnis.
        $auftrag = Document::create([
            'customer_id' => null, 'category' => 'contract', 'file_name' => 'auftrag.pdf',
            'file_path' => 'documents/eingang/a2.pdf', 'disk' => 'local', 'ai_status' => 'done',
            'ai_type' => 'energieauftrag',
            'ai_extracted' => ['energie' => ['tariff' => 'OekoStrom', 'consumption_kwh' => 2300]],
        ]);
        $foto = Document::create([
            'customer_id' => null, 'category' => 'other', 'file_name' => 'zaehler.jpg',
            'file_path' => 'documents/eingang/z2.jpg', 'disk' => 'local', 'ai_status' => 'done',
            'ai_type' => 'zaehlerfoto',
            'ai_extracted' => ['energie' => ['meter_number' => '1EMH0012345678', 'meter_reading' => 12345.6]],
        ]);

        $merged = app(DocumentIntakeService::class)->mergeExtractions([$auftrag, $foto]);

        $this->assertSame('OekoStrom', $merged['energie']['tariff']);
        $this->assertSame('1EMH0012345678', $merged['energie']['meter_number']);
        $this->assertSame(12345.6, $merged['energie']['meter_reading']);
        $this->assertSame(2300, $merged['energie']['consumption_kwh']);
    }
}
