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
 * Automatische Anlage eines Internet-/DSL-Vertrags aus dem Dokumenten-Eingang:
 * die Internet-Detailtabelle wird mit Tarif, Geschwindigkeit, preisvariablem
 * Tarif (Aktionspreis + regulaerer Preis), Router und Bonus/Gutschein befuellt.
 */
class InternetContractExtractionTest extends TestCase
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
            'file_name' => 'dsl.pdf',
            'file_path' => 'documents/eingang/dsl.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'internetvertrag',
            'ai_extracted' => [
                'versicherung' => [
                    'sparte' => 'internet',
                    'insurer' => 'Telekom',
                    'contract_number' => '17485672',
                    'premium_amount' => 34.79,
                    'premium_interval' => 'monthly',
                    'tariff' => 'Magenta Zuhause L',
                ],
                'internet' => [
                    'tariff' => 'Magenta Zuhause L',
                    'speed' => '100 MBit/s',
                    'upload_speed' => '40,0 MBit/s',
                    'price_initial' => 9.95,
                    'price_initial_months' => 3,
                    'price_regular' => 48.95,
                    'has_router' => true,
                    'router_name' => 'Telekom Speedport Smart 4',
                    'router_price' => 6.95,
                    'bonus_amount' => 155.00,
                    'voucher_amount' => 100.00,
                    'setup_fee' => 49.99,
                    'shipping_fee' => 6.95,
                    'min_duration_months' => 24,
                ],
            ],
        ]);
    }

    public function test_creates_internet_contract_with_detail(): void
    {
        $customer = $this->customer();
        $contract = app(DocumentIntakeService::class)->createContractFromExtraction($this->document(), $customer, null);

        $this->assertNotNull($contract);
        $this->assertSame('internet', $contract->type);
        $this->assertSame('Telekom', $contract->insurer);
        $this->assertSame('17485672', $contract->contract_number);
        $this->assertEquals(34.79, (float) $contract->premium_amount);

        $net = $contract->internetDetail;
        $this->assertNotNull($net);
        $this->assertSame('Magenta Zuhause L', $net->tariff);
        $this->assertSame('100 MBit/s', $net->speed);
        $this->assertSame('40,0 MBit/s', $net->upload_speed);
        $this->assertEquals(9.95, (float) $net->price_initial);
        $this->assertSame(3, $net->price_initial_months);
        $this->assertEquals(48.95, (float) $net->price_regular);
        $this->assertTrue((bool) $net->has_router);
        $this->assertSame('Telekom Speedport Smart 4', $net->router_name);
        $this->assertEquals(6.95, (float) $net->router_price);
        $this->assertEquals(155.00, (float) $net->bonus_amount);
        $this->assertEquals(100.00, (float) $net->voucher_amount);
        // Einmalige Kosten + Mindestlaufzeit (Betreiber-Vorgabe 10.08.2026).
        $this->assertEquals(49.99, (float) $net->setup_fee);
        $this->assertEquals(6.95, (float) $net->shipping_fee);
        $this->assertSame(24, $net->min_duration_months);
    }

    public function test_reupload_updates_same_contract_without_duplicate(): void
    {
        $customer = $this->customer();
        $service = app(DocumentIntakeService::class);

        $service->createContractFromExtraction($this->document(), $customer, null);
        // Gleiche Vertragsnummer -> Bestandsvertrag wird aktualisiert, kein Duplikat.
        $service->createContractFromExtraction($this->document(), $customer, null);

        $this->assertSame(1, Contract::where('customer_id', $customer->id)->where('type', 'internet')->count());
    }
}
