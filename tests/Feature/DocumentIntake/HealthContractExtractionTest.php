<?php

namespace Tests\Feature\DocumentIntake;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\ContractSwitchReminderService;
use App\Services\DocumentIntake\DocumentIntakeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Aus einem Kranken-Dokument (z.B. Ersatzbescheinigung / Beitrittserklaerung)
 * entsteht ein Krankenversicherungs-Vertrag mit Subtyp 'gkv' - erst damit
 * greift die 12-Monats-Wechsel-Erinnerung (§175 SGB V) ab Mitgliedsbeginn.
 */
class HealthContractExtractionTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => 'kunde@example.com']);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(Str::random(6)),
            // erreichbar, damit die Erinnerung ausgeloest werden koennte
            'email_consent_at' => now(),
        ]);
    }

    private function healthDoc(Customer $customer): Document
    {
        return Document::create([
            'customer_id' => $customer->id,
            'category' => 'identity',
            'file_name' => 'ersatzbescheinigung.pdf',
            'file_path' => 'documents/eingang/ersatz.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'gesundheitskarte',
            'ai_extracted' => [
                'versicherung' => ['sparte' => 'krankenversicherung', 'insurer' => 'novitas bkk', 'start_date' => '2026-04-01'],
                'gesundheit' => [
                    'health_insurance_company' => 'novitas bkk',
                    'health_insurance_number' => 'S455872364',
                    'health_insurance_type' => 'gesetzlich',
                ],
            ],
        ]);
    }

    public function test_creates_gkv_contract_with_subtype(): void
    {
        $customer = $this->customer();
        $contract = app(DocumentIntakeService::class)
            ->createContractFromExtraction($this->healthDoc($customer), $customer, null);

        $this->assertNotNull($contract);
        $this->assertSame('krankenversicherung', $contract->type);
        $this->assertSame('gkv', $contract->subtype);
        $this->assertSame('novitas bkk', $contract->insurer);
        $this->assertSame('2026-04-01', Carbon::parse($contract->start_date)->toDateString());
    }

    public function test_switch_reminder_becomes_due_after_12_months(): void
    {
        $customer = $this->customer();
        $contract = app(DocumentIntakeService::class)
            ->createContractFromExtraction($this->healthDoc($customer), $customer, null);
        $service = app(ContractSwitchReminderService::class);

        // Vor Ablauf der 12-Monats-Bindungsfrist: NICHT faellig.
        Carbon::setTestNow(Carbon::parse('2026-12-01'));
        $this->assertCount(0, array_filter($service->due(), fn ($t) => $t[0]->id === $contract->id));

        // Ab Mitgliedsbeginn + 12 Monate (01.04.2027): faellig.
        Carbon::setTestNow(Carbon::parse('2027-04-02'));
        $due = array_filter($service->due(), fn ($t) => $t[0]->id === $contract->id);
        $this->assertCount(1, $due);

        Carbon::setTestNow();
    }
}
