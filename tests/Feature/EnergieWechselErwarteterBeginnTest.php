<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentIntake\DocumentIntakeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Versorgerwechsel ohne genanntes Beginndatum (Betreiber-Regel 29.07.2026):
 * Der Parser meldet den SPAETESTEN erwarteten Beginn in Tagen nach dem Upload
 * (Stadtwerke: 14 Tage Kuendigungsfrist + Bearbeitung = 20). Die Vertragsanlage
 * setzt den voraussichtlichen Beginn relativ zum Upload-Tag; die spaetere
 * Vertragsbestaetigung ersetzt ihn durch das endgueltige Datum.
 */
class EnergieWechselErwarteterBeginnTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(substr(md5((string) $user->id), 0, 8)),
        ]);
    }

    public function test_contract_starts_at_most_20_days_after_upload(): void
    {
        Carbon::setTestNow('2026-07-29 14:00:00');
        $customer = $this->makeCustomer();
        $doc = Document::create([
            'customer_id' => $customer->id, 'category' => 'contract', 'file_name' => 'lichtblick.pdf',
            'file_path' => 'lichtblick.pdf', 'disk' => 'local', 'ai_type' => 'energieauftrag',
            'ai_extracted' => [
                'versicherung' => [
                    'insurer' => 'LichtBlick', 'sparte' => 'strom', 'contract_number' => '1657453',
                    'document_stage' => 'antrag', 'expected_start_within_days' => 20,
                    'premium_amount' => 64.24, 'premium_interval' => 'monthly',
                ],
                'energie' => [
                    'meter_number' => '42811442', 'malo_id' => '51214022992',
                    'consumption_kwh' => 1800, 'previous_provider' => 'Stadtwerke Rendsburg GmbH',
                ],
            ],
        ]);

        $contract = app(DocumentIntakeService::class)->createContractFromExtraction($doc, $customer, null);

        $this->assertNotNull($contract);
        $this->assertSame('strom', $contract->type);
        // Upload 29.07.2026 + 20 Tage = 18.08.2026 (spaetester erwarteter Beginn).
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'start_date' => '2026-08-18',
        ]);
        // Zaehler-/Wechseldaten sind am Energie-Detail angekommen.
        $this->assertDatabaseHas('contract_energy_details', [
            'contract_id' => $contract->id,
            'meter_number' => '42811442',
            'malo_id' => '51214022992',
            'previous_provider' => 'Stadtwerke Rendsburg GmbH',
        ]);
    }

    public function test_explicit_start_date_is_never_overridden(): void
    {
        Carbon::setTestNow('2026-07-29 14:00:00');
        $customer = $this->makeCustomer();
        $doc = Document::create([
            'customer_id' => $customer->id, 'category' => 'contract', 'file_name' => 'lichtblick.pdf',
            'file_path' => 'lichtblick.pdf', 'disk' => 'local', 'ai_type' => 'energieauftrag',
            'ai_extracted' => [
                'versicherung' => [
                    'insurer' => 'LichtBlick', 'sparte' => 'strom',
                    'start_date' => '2026-09-01',
                    // Widerspruechliche Angabe - das ECHTE Datum gewinnt immer.
                    'expected_start_within_days' => 20,
                ],
            ],
        ]);

        $contract = app(DocumentIntakeService::class)->createContractFromExtraction($doc, $customer, null);

        $this->assertNotNull($contract);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'start_date' => '2026-09-01']);
    }
}
