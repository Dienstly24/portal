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
 * Referenz-/Vorgangsnummer am Vertrag (Betreiber-Vorgabe 17.08.2026): Ein
 * ANTRAG traegt noch keine Vertragsnummer, aber jedes Portal vergibt eine
 * eigene Referenz. Genau ueber sie laesst sich spaeter nachvollziehen,
 * welcher Vertrag bestaetigt wurde - ein hochgeladenes Dokument (Police,
 * Abrechnung der Gesellschaft) findet damit Vertrag UND Kunde.
 */
class ContractReferenceNumberTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $name = 'Karim Muster'): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(Str::random(6)),
        ]);
    }

    private function doc(array $extracted, string $type = 'versicherungsvertrag'): Document
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

    /** Die Antrags-Bestaetigung des Portals: Referenznummer, keine Vertragsnummer. */
    private function antrag(): array
    {
        return [
            'versicherung' => [
                'insurer' => 'AdmiralDirekt',
                'sparte' => 'kfz',
                'reference_number' => '1477-6741-9200-53',
                'document_stage' => Contract::STAGE_ANTRAG,
            ],
        ];
    }

    public function test_reference_number_is_stored_with_the_contract(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $contract = $intake->createContractFromExtraction($this->doc($this->antrag()), $customer, null);

        $this->assertNotNull($contract);
        $this->assertSame('1477-6741-9200-53', $contract->reference_number);
        // Die Referenz ist NIE die Vertragsnummer.
        $this->assertNull($contract->contract_number);
        $this->assertTrue($contract->isApplication());
    }

    public function test_later_police_with_the_same_reference_completes_the_contract(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);
        $editor = User::factory()->create(['role' => 'admin']);

        $antrag = $intake->createContractFromExtraction($this->doc($this->antrag()), $customer, null);

        // Wochen spaeter: der Versicherungsschein nennt dieselbe Referenz und
        // bringt die echte Vertragsnummer.
        $police = $this->doc([
            'versicherung' => [
                'insurer' => 'AdmiralDirekt',
                'sparte' => 'kfz',
                'contract_number' => 'KV-99887766',
                'reference_number' => '1477-6741-9200-53',
                'start_date' => '2026-09-01',
                'document_stage' => Contract::STAGE_VERTRAG,
            ],
        ], 'kfz_vertrag');

        $result = $intake->createContractFromExtraction($police, $customer, $editor->id);

        // KEIN zweiter Vertrag - derselbe Vorgang, jetzt bestaetigt.
        $this->assertSame($antrag->id, $result->id);
        $this->assertSame(1, Contract::where('customer_id', $customer->id)->count());

        $result->refresh();
        $this->assertSame('KV-99887766', $result->contract_number);
        // Die Referenz des Vorgangs bleibt erhalten.
        $this->assertSame('1477-6741-9200-53', $result->reference_number);
        $this->assertSame(Contract::STAGE_VERTRAG, $result->stage);
    }

    public function test_document_with_only_the_reference_number_finds_the_customer(): void
    {
        // Genau der Betriebsfall: es kommt eine Abrechnung/Bestaetigung, die
        // NUR die Referenznummer nennt - Kunde und Vertrag sind damit
        // eindeutig gefunden.
        $customer = $this->customer('Maher Al Noman');
        $intake = app(DocumentIntakeService::class);
        $intake->createContractFromExtraction($this->doc($this->antrag()), $customer, null);

        $fremd = $this->customer('Andere Person');
        $this->assertNotSame($customer->id, $fremd->id);

        $vorschlaege = $intake->findSuggestions([
            'versicherung' => ['reference_number' => '1477-6741-9200-53'],
        ]);
        $ids = array_column($vorschlaege, 'customer_id');

        $this->assertContains((string) $customer->id, $ids, 'Der Kunde muss ueber die Referenznummer gefunden werden.');
        $treffer = collect($vorschlaege)->firstWhere('customer_id', (string) $customer->id);
        $this->assertSame(100, $treffer['score'], 'Eine Referenznummer ist ein HARTES Merkmal.');
        $this->assertStringContainsString('Referenznummer', implode(' ', $treffer['reasons']));
    }

    public function test_existing_reference_is_never_overwritten(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);
        $editor = User::factory()->create(['role' => 'admin']);

        $contract = $intake->createContractFromExtraction($this->doc($this->antrag()), $customer, null);

        // Ein spaeteres Dokument desselben Vorgangs nennt eine ANDERE Referenz
        // (z.B. die interne Nummer der Gesellschaft) - der urspruengliche
        // Vorgangsschluessel bleibt stehen.
        $nachtrag = $this->doc([
            'versicherung' => [
                'insurer' => 'AdmiralDirekt',
                'sparte' => 'kfz',
                'contract_number' => 'KV-99887766',
                'reference_number' => '9999-0000-1111-22',
                'document_stage' => Contract::STAGE_VERTRAG,
            ],
        ], 'kfz_vertrag');
        $intake->createContractFromExtraction($nachtrag, $customer, $editor->id);

        $contract->refresh();
        $this->assertSame('1477-6741-9200-53', $contract->reference_number);
    }
}
