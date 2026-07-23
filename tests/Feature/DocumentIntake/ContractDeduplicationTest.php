<?php

namespace Tests\Feature\DocumentIntake;

use App\Models\Contract;
use App\Models\ContractRevision;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentIntake\DocumentIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Duplikat-Schutz + Version History beim Import: ein neues Dokument fuer ein
 * bereits erfasstes Fahrzeug/eine bereits erfasste Police erzeugt KEIN
 * Duplikat, sondern aktualisiert den vorhandenen Vertrag und protokolliert
 * jede geaenderte Angabe (altem/neuem Wert).
 */
class ContractDeduplicationTest extends TestCase
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

    private function doc(array $extracted, string $type = 'kfz_vertrag'): Document
    {
        return Document::create([
            'customer_id' => null,
            'category' => 'contract',
            'file_name' => 'dok_' . Str::random(4) . '.pdf',
            'file_path' => 'documents/eingang/' . Str::random(8) . '.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => $type,
            'ai_extracted' => $extracted,
        ]);
    }

    public function test_second_document_for_same_plate_updates_instead_of_duplicating(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);
        $editor = User::factory()->create(['role' => 'admin']);

        $first = $this->doc([
            'versicherung' => ['insurer' => 'HUK24', 'sparte' => 'kfz', 'start_date' => '2026-07-01', 'premium_amount' => 350],
            'kfz' => ['license_plate' => 'S-AB 1234', 'has_teilkasko' => true],
        ]);
        $contract = $intake->createContractFromExtraction($first, $customer, null);
        $this->assertNotNull($contract);
        $this->assertSame(1, Contract::where('customer_id', $customer->id)->count());

        // Zweites Dokument (dasselbe Kennzeichen, hoeherer Beitrag + Schutzbrief).
        $second = $this->doc([
            'versicherung' => ['insurer' => 'HUK24', 'sparte' => 'kfz', 'premium_amount' => 380.99],
            'kfz' => ['license_plate' => 'S-AB1234', 'extras' => ['schutzbrief']],
        ]);
        $result = $intake->createContractFromExtraction($second, $customer, $editor->id);

        // Kein Duplikat, derselbe Vertrag.
        $this->assertSame($contract->id, $result->id);
        $this->assertSame(1, Contract::where('customer_id', $customer->id)->count());

        // Beitrag aktualisiert.
        $this->assertSame('380.99', (string) $result->fresh()->premium_amount);

        // Audit: Beitrag 350,00 -> 380,99 protokolliert, mit Bearbeiter.
        $beitrag = ContractRevision::where('contract_id', $contract->id)->where('field', 'premium_amount')->first();
        $this->assertNotNull($beitrag);
        $this->assertSame('350,00 €', $beitrag->old_value);
        $this->assertSame('380,99 €', $beitrag->new_value);
        $this->assertSame('document', $beitrag->source);
        $this->assertSame($editor->id, $beitrag->changed_by);

        // Schutzbrief ergaenzt und protokolliert.
        $extras = ContractRevision::where('contract_id', $contract->id)->where('field', 'extras')->first();
        $this->assertNotNull($extras);
        $this->assertStringContainsString('Schutzbrief', $extras->new_value);
        $this->assertContains('schutzbrief', $result->fresh()->vehicleDetail->extras);
    }

    public function test_match_by_vin_updates_existing_contract(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $first = $this->doc([
            'versicherung' => ['insurer' => 'Allianz', 'sparte' => 'kfz', 'start_date' => '2026-01-01'],
            'kfz' => ['vin' => 'WBA1234567890', 'license_plate' => 'M-XY 10'],
        ]);
        $contract = $intake->createContractFromExtraction($first, $customer, null);

        // Zweites Dokument mit gleicher FIN (anderes/kein Kennzeichen).
        $second = $this->doc([
            'versicherung' => ['insurer' => 'Allianz', 'sparte' => 'kfz', 'premium_amount' => 210],
            'kfz' => ['vin' => 'wba1234567890'],
        ]);
        $result = $intake->createContractFromExtraction($second, $customer, null);

        $this->assertSame($contract->id, $result->id);
        $this->assertSame(1, Contract::where('customer_id', $customer->id)->count());
    }

    public function test_match_by_contract_number_updates_and_logs(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $first = $this->doc([
            'versicherung' => ['insurer' => 'HUK', 'sparte' => 'kfz', 'contract_number' => 'VS-9001', 'start_date' => '2026-03-01'],
            'kfz' => ['license_plate' => 'K-AA 1'],
        ]);
        $contract = $intake->createContractFromExtraction($first, $customer, null);

        $second = $this->doc([
            'versicherung' => ['insurer' => 'HUK', 'sparte' => 'kfz', 'contract_number' => 'VS-9001', 'start_date' => '2026-04-15'],
        ]);
        $result = $intake->createContractFromExtraction($second, $customer, null);

        $this->assertSame($contract->id, $result->id);
        $this->assertSame(1, Contract::where('customer_id', $customer->id)->count());
        $this->assertSame('2026-04-15', (string) $result->fresh()->start_date);

        $rev = ContractRevision::where('contract_id', $contract->id)->where('field', 'start_date')->first();
        $this->assertNotNull($rev);
        $this->assertSame('01.03.2026', $rev->old_value);
        $this->assertSame('15.04.2026', $rev->new_value);
    }

    public function test_different_vehicle_creates_new_contract(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $first = $this->doc([
            'versicherung' => ['insurer' => 'HUK', 'sparte' => 'kfz'],
            'kfz' => ['license_plate' => 'B-AA 1', 'vin' => 'AAA111'],
        ]);
        $intake->createContractFromExtraction($first, $customer, null);

        // Anderes Fahrzeug -> neuer Vertrag.
        $second = $this->doc([
            'versicherung' => ['insurer' => 'HUK', 'sparte' => 'kfz'],
            'kfz' => ['license_plate' => 'B-BB 2', 'vin' => 'BBB222'],
        ]);
        $intake->createContractFromExtraction($second, $customer, null);

        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());
    }

    public function test_empty_new_value_never_overwrites_existing(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $first = $this->doc([
            'versicherung' => ['insurer' => 'HUK', 'sparte' => 'kfz', 'premium_amount' => 300, 'start_date' => '2026-05-01'],
            'kfz' => ['license_plate' => 'F-CC 3'],
        ]);
        $contract = $intake->createContractFromExtraction($first, $customer, null);

        // Zweites Dokument ohne Beitrag/Beginn -> darf die Bestandswerte nicht loeschen.
        $second = $this->doc([
            'versicherung' => ['insurer' => 'HUK', 'sparte' => 'kfz'],
            'kfz' => ['license_plate' => 'F-CC 3'],
        ]);
        $result = $intake->createContractFromExtraction($second, $customer, null);

        $this->assertSame('300.00', (string) $result->fresh()->premium_amount);
        $this->assertSame('2026-05-01', (string) $result->fresh()->start_date);
        $this->assertSame(0, ContractRevision::where('contract_id', $contract->id)->count());
    }
}
