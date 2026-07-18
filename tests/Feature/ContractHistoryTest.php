<?php

namespace Tests\Feature;

use App\Models\ContractHistory;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\ContractHistoryService;
use App\Services\DocumentIntake\DocumentIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Vertragsverlauf (alle Sparten): neuer Zeitraum beendet den vorherigen,
 * Anlage aus einem Dokument startet den Verlauf.
 */
class ContractHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);
        return Customer::create(['user_id' => $user->id, 'customer_number' => 'C-' . strtoupper(Str::random(6))]);
    }

    public function test_recording_a_switch_closes_the_previous_period(): void
    {
        $customer = $this->customer();
        $service = app(ContractHistoryService::class);

        $service->record([
            'customer_id' => $customer->id, 'branch' => 'kranken',
            'provider' => 'AOK', 'effective_from' => '2024-01-01', 'reason' => 'initial',
        ]);
        $service->record([
            'customer_id' => $customer->id, 'branch' => 'kranken',
            'provider' => 'TK', 'effective_from' => '2026-10-01', 'reason' => 'wechsel',
        ]);

        $entries = ContractHistory::where('customer_id', $customer->id)->orderBy('effective_from')->get();
        $this->assertCount(2, $entries);
        // Der AOK-Zeitraum endet am Tag vor dem TK-Beginn.
        $this->assertSame('2026-09-30', $entries[0]->effective_until?->toDateString());
        $this->assertSame('AOK', $entries[0]->provider);
        // Der TK-Zeitraum laeuft (offen).
        $this->assertNull($entries[1]->effective_until);
        $this->assertSame('TK', $entries[1]->provider);
    }

    public function test_switch_in_other_branch_does_not_close_unrelated_period(): void
    {
        $customer = $this->customer();
        $service = app(ContractHistoryService::class);

        $service->record(['customer_id' => $customer->id, 'branch' => 'kfz', 'provider' => 'HUK', 'effective_from' => '2025-01-01']);
        $service->record(['customer_id' => $customer->id, 'branch' => 'strom', 'provider' => 'EWE', 'effective_from' => '2026-01-01']);

        // Der Kfz-Eintrag bleibt offen (andere Sparte).
        $kfz = ContractHistory::where('customer_id', $customer->id)->where('branch', 'kfz')->first();
        $this->assertNull($kfz->effective_until);
    }

    public function test_contract_creation_from_document_starts_history(): void
    {
        $customer = $this->customer();
        $doc = Document::create([
            'customer_id' => null, 'category' => 'contract', 'file_name' => 'p.pdf',
            'file_path' => 'documents/eingang/p.pdf', 'disk' => 'local', 'ai_status' => 'done',
            'ai_type' => 'kfz_vertrag',
            'ai_extracted' => [
                'versicherung' => ['insurer' => 'HUK24', 'sparte' => 'kfz', 'start_date' => '2026-07-16'],
                'kfz' => ['license_plate' => 'S-AB 1234'],
            ],
        ]);

        $contract = app(DocumentIntakeService::class)->createContractFromExtraction($doc, $customer, null);

        $history = ContractHistory::where('customer_id', $customer->id)->first();
        $this->assertNotNull($history);
        $this->assertSame('kfz', $history->branch);
        $this->assertSame('HUK24', $history->provider);
        $this->assertSame('2026-07-16', $history->effective_from?->toDateString());
        $this->assertSame((string) $contract->id, (string) $history->contract_id);
        $this->assertSame('initial', $history->reason);
    }
}
