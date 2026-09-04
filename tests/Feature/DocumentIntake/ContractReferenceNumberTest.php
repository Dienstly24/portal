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
            'customer_number' => 'C-'.strtoupper(Str::random(6)),
        ]);
    }

    private function doc(array $extracted, string $type = 'versicherungsvertrag'): Document
    {
        return Document::create([
            'customer_id' => null,
            'category' => 'contract',
            'file_name' => 'dok_'.Str::random(4).'.pdf',
            'file_path' => 'documents/eingang/'.Str::random(8).'.pdf',
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

    /**
     * Das Beratungsprotokoll des Vergleichsportals: viele Sachdaten
     * (Gesellschaft, Beitrag, Beginn), aber KEINE Kennung - weder
     * Vertrags- noch Referenznummer.
     */
    private function protokoll(array $ueberschreiben = []): array
    {
        return [
            'versicherung' => array_merge([
                'insurer' => 'WGV',
                'sparte' => 'kfz',
                'premium_amount' => 80.49,
                'premium_interval' => 'monthly',
                'start_date' => '2026-08-21',
                'document_stage' => Contract::STAGE_ANTRAG,
            ], $ueberschreiben),
        ];
    }

    /** Die Abschluss-Seite der Antragsstrecke: nur die Referenznummer. */
    private function bestaetigung(array $ueberschreiben = []): array
    {
        return [
            'versicherung' => array_merge([
                'insurer' => 'WGV',
                'sparte' => 'kfz',
                'reference_number' => '1417-6729-0230-64',
                'document_stage' => Contract::STAGE_ANTRAG,
            ], $ueberschreiben),
        ];
    }

    /**
     * Gemeldeter Fehler 21.08.2026: Protokoll UND Bestaetigungs-Screenshot
     * gehoeren zu EINEM Antrag - es entstanden aber zwei Vertraege. Die
     * Bestaetigung darf nur die Referenznummer nachtragen.
     */
    public function test_confirmation_page_only_adds_the_reference_to_the_application(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $vertrag = $intake->createContractFromExtraction($this->doc($this->protokoll()), $customer, null);
        $this->assertNotNull($vertrag);
        $this->assertNull($vertrag->reference_number);

        $ergebnis = $intake->createContractFromExtraction(
            $this->doc($this->bestaetigung()), $customer, null
        );

        $this->assertSame($vertrag->id, $ergebnis->id);
        $this->assertSame(1, Contract::where('customer_id', $customer->id)->count());

        $ergebnis->refresh();
        $this->assertSame('1417-6729-0230-64', $ergebnis->reference_number);
        // Die Sachdaten des Protokolls bleiben unangetastet.
        $this->assertSame('80.49', (string) $ergebnis->premium_amount);
        $this->assertSame('2026-08-21', substr((string) $ergebnis->start_date, 0, 10));
        $this->assertTrue($ergebnis->isApplication());
    }

    /** Andere Reihenfolge, gleiches Ergebnis: erst der Screenshot, dann das Protokoll. */
    public function test_protocol_after_the_confirmation_stays_one_contract(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $huelle = $intake->createContractFromExtraction($this->doc($this->bestaetigung()), $customer, null);
        $ergebnis = $intake->createContractFromExtraction($this->doc($this->protokoll()), $customer, null);

        $this->assertSame($huelle->id, $ergebnis->id);
        $this->assertSame(1, Contract::where('customer_id', $customer->id)->count());

        $ergebnis->refresh();
        $this->assertSame('1417-6729-0230-64', $ergebnis->reference_number);
        $this->assertSame('80.49', (string) $ergebnis->premium_amount);
        $this->assertSame('2026-08-21', substr((string) $ergebnis->start_date, 0, 10));
    }

    /** Eine ANDERE Gesellschaft ist ein eigener Vorgang - nie zusammenfuehren. */
    public function test_confirmation_of_another_insurer_stays_separate(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $intake->createContractFromExtraction($this->doc($this->protokoll()), $customer, null);
        $intake->createContractFromExtraction(
            $this->doc($this->bestaetigung(['insurer' => 'HUK24'])), $customer, null
        );

        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());
    }

    /**
     * Zwei offene Antraege mit EIGENEN Sachdaten (z.B. zwei Fahrzeuge bei
     * derselben Gesellschaft): welcher gemeint ist, steht nicht fest - dann
     * wird nicht geraten, die Bestaetigung wird ein eigener Vertrag.
     */
    public function test_ambiguous_applications_are_never_guessed(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $intake->createContractFromExtraction($this->doc($this->protokoll()), $customer, null);
        $intake->createContractFromExtraction(
            $this->doc($this->protokoll(['premium_amount' => 61.20, 'start_date' => '2026-09-01'])),
            $customer,
            null
        );
        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());

        $intake->createContractFromExtraction($this->doc($this->bestaetigung()), $customer, null);

        $this->assertSame(3, Contract::where('customer_id', $customer->id)->count());
    }

    /**
     * Ein Vertrag, der bereits eine ANDERE Referenz traegt, gehoert zu einem
     * anderen Vorgang - die neue Bestaetigung haengt sich nicht an ihn.
     */
    public function test_application_with_another_reference_is_not_taken_over(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $intake->createContractFromExtraction(
            $this->doc($this->protokoll(['reference_number' => '5555-1111-2222-33'])), $customer, null
        );

        $intake->createContractFromExtraction($this->doc($this->bestaetigung()), $customer, null);

        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());
    }
}
