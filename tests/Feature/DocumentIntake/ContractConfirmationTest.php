<?php

namespace Tests\Feature\DocumentIntake;

use App\Models\Contract;
use App\Models\ContractEnergyDetail;
use App\Models\ContractRevision;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentIntake\DocumentIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Betreiber-Vorgabe 29.07.2026 - AUFTRAG zuerst, VERTRAG spaeter:
 *
 * Im Alltag wird zuerst der AUFTRAG/ANTRAG hochgeladen (viele Daten, aber noch
 * keine Bestaetigung). Wochen spaeter kommt die VERTRAGSBESTAETIGUNG/POLICE mit
 * Vertragsnummer, Kundennummer, MaLo-ID, endgueltigem Beginn und Abschlag.
 * Beide Dokumente teilen oft KEIN hartes Identitaetsmerkmal (der EWE-Auftrag
 * nennt nur die Zaehlernummer, die Bestaetigung nur die MaLo-ID).
 *
 * Erwartung: das System erkennt dieselbe Sache, ergaenzt den vorhandenen
 * Vertrag (statt einen zweiten anzulegen) und protokolliert jede Angabe.
 * Bleibt es mehrdeutig oder widerspricht ein hartes Merkmal, wird NICHT
 * geraten - dann entsteht ein eigener Vertrag.
 */
class ContractConfirmationTest extends TestCase
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

    private function doc(array $extracted, string $type = 'energieauftrag'): Document
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

    /** Der EWE-Auftrag: Zaehlernummer, Tarif, Vorversorger - keine Vertragsnummer. */
    private function eweAuftrag(): array
    {
        return [
            'versicherung' => [
                'insurer' => 'EWE VERTRIEB GmbH', 'sparte' => 'strom',
                'premium_amount' => 20.02, 'premium_interval' => 'monthly',
                'document_stage' => Contract::STAGE_ANTRAG,
            ],
            'energie' => [
                'meter_number' => '1LOG0092283078', 'consumption_kwh' => 1200,
                'tariff' => 'EWE Zuhause+ Grünstrom 24', 'working_price' => 29.96, 'base_price' => 20.02,
                'previous_provider' => 'E.ON Energie Deutschland GmbH', 'previous_customer_number' => '1310124744',
            ],
        ];
    }

    /** Die EWE-Vertragsbestaetigung: Vertragsnummer, Kundennummer, MaLo - keine Zaehlernummer. */
    private function eweBestaetigung(): array
    {
        return [
            'versicherung' => [
                'insurer' => 'EWE VERTRIEB GmbH', 'sparte' => 'strom',
                'contract_number' => '1004418075', 'start_date' => '2026-07-28', 'end_date' => '2028-07-27',
                'premium_amount' => 50.00, 'premium_interval' => 'monthly',
                'document_stage' => Contract::STAGE_VERTRAG,
            ],
            'energie' => [
                'malo_id' => '50307481544', 'customer_number' => '22434078',
                'tariff' => 'EWE Zuhause+ Grünstrom 24', 'grid_operator' => 'Bayernwerk Netz',
                'working_price' => 29.96, 'base_price' => 20.02,
            ],
        ];
    }

    public function test_confirmation_completes_application_contract_instead_of_duplicating(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);
        $editor = User::factory()->create(['role' => 'admin']);

        $auftrag = $intake->createContractFromExtraction($this->doc($this->eweAuftrag()), $customer, null);
        $this->assertNotNull($auftrag);
        // Der Auftrag ist noch kein bestaetigter Vertrag.
        $this->assertTrue($auftrag->isApplication());
        $this->assertNull($auftrag->contract_number);

        $bestaetigung = $this->doc($this->eweBestaetigung());
        $result = $intake->createContractFromExtraction($bestaetigung, $customer, $editor->id);

        // Kein zweiter Vertrag - derselbe Vertrag, jetzt bestaetigt.
        $this->assertSame($auftrag->id, $result->id);
        $this->assertSame(1, Contract::where('customer_id', $customer->id)->count());

        $result->refresh();
        $this->assertSame(Contract::STAGE_VERTRAG, $result->stage);
        $this->assertFalse($result->isApplication());
        $this->assertSame('1004418075', $result->contract_number);
        $this->assertSame('2026-07-28', (string) $result->start_date);
        $this->assertSame('2028-07-27', (string) $result->end_date);
        $this->assertSame('50.00', (string) $result->premium_amount);

        // Energie-Details: Zaehlernummer aus dem Auftrag BLEIBT, MaLo-ID und
        // Kundennummer kommen aus der Bestaetigung dazu.
        $en = ContractEnergyDetail::where('contract_id', $result->id)->first();
        $this->assertSame('1LOG0092283078', $en->meter_number);
        $this->assertSame('50307481544', $en->malo_id);
        $this->assertSame('22434078', $en->customer_number);
        $this->assertSame('Bayernwerk Netz', $en->grid_operator);
        $this->assertSame('E.ON Energie Deutschland GmbH', $en->previous_provider);
        $this->assertSame(1200, (int) $en->consumption_kwh);
        $this->assertSame(50.0, (float) $en->payment_amount);
        $this->assertSame(29.96, (float) $en->working_price);

        // Das Bestaetigungs-Dokument haengt am selben Vertrag.
        $this->assertSame($result->id, (string) $bestaetigung->fresh()->contract_id);

        // Version History: Vertragsnummer + Beitrag + Stufe nachvollziehbar.
        $felder = ContractRevision::where('contract_id', $result->id)->pluck('field')->all();
        $this->assertContains('contract_number', $felder);
        $this->assertContains('premium_amount', $felder);
        $this->assertContains('stage', $felder);

        $stufe = ContractRevision::where('contract_id', $result->id)->where('field', 'stage')->first();
        $this->assertStringContainsString('Antrag', (string) $stufe->old_value);
        $this->assertSame('Vertrag bestätigt', $stufe->new_value);
        $this->assertSame($editor->id, $stufe->changed_by);
    }

    public function test_confirmation_of_a_different_provider_creates_its_own_contract(): void
    {
        // Auftrag bei EWE, Bestaetigung von einem ANDEREN Versorger: das ist
        // ein anderes Geschaeft (Wechsel), kein Nachtrag zum Auftrag.
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $auftrag = $intake->createContractFromExtraction($this->doc($this->eweAuftrag()), $customer, null);

        $fremd = $this->eweBestaetigung();
        $fremd['versicherung']['insurer'] = 'Vattenfall Europe Sales GmbH';
        $neu = $intake->createContractFromExtraction($this->doc($fremd), $customer, null);

        $this->assertNotSame($auftrag->id, $neu->id);
        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());
        $this->assertTrue($auftrag->fresh()->isApplication());
    }

    public function test_gas_confirmation_never_completes_an_electricity_application(): void
    {
        // Strom und Gas sind getrennte Sparten - eine Gas-Bestaetigung darf
        // den Strom-Auftrag desselben Versorgers nie vereinnahmen.
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $strom = $intake->createContractFromExtraction($this->doc($this->eweAuftrag()), $customer, null);

        $gas = $this->eweBestaetigung();
        $gas['versicherung']['sparte'] = 'gas';
        $neu = $intake->createContractFromExtraction($this->doc($gas), $customer, null);

        $this->assertNotSame($strom->id, $neu->id);
        $this->assertSame('gas', $neu->type);
        $this->assertTrue($strom->fresh()->isApplication());
    }

    public function test_contradicting_meter_identity_creates_its_own_contract(): void
    {
        // Zwei Lieferstellen desselben Kunden beim selben Versorger: die
        // Bestaetigung nennt eine ANDERE Zaehlernummer -> anderer Vertrag.
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $auftrag = $intake->createContractFromExtraction($this->doc($this->eweAuftrag()), $customer, null);

        $andere = $this->eweBestaetigung();
        $andere['energie']['meter_number'] = '1EMH0099887766';
        $neu = $intake->createContractFromExtraction($this->doc($andere), $customer, null);

        $this->assertNotSame($auftrag->id, $neu->id);
        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());
    }

    public function test_ambiguous_applications_are_not_merged(): void
    {
        // Zwei offene Auftraege beim selben Versorger, kein unterscheidendes
        // Merkmal in der Bestaetigung -> es wird NICHT geraten.
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $a = $this->eweAuftrag();
        unset($a['energie']['tariff']);
        $a['energie']['meter_number'] = '1LOG0011111111';
        $intake->createContractFromExtraction($this->doc($a), $customer, null);

        $b = $this->eweAuftrag();
        unset($b['energie']['tariff']);
        $b['energie']['meter_number'] = '1LOG0022222222';
        $intake->createContractFromExtraction($this->doc($b), $customer, null);

        $ohneMerkmal = $this->eweBestaetigung();
        unset($ohneMerkmal['energie']['tariff']);
        $intake->createContractFromExtraction($this->doc($ohneMerkmal), $customer, null);

        $this->assertSame(3, Contract::where('customer_id', $customer->id)->count());
    }

    public function test_ambiguous_applications_are_resolved_by_matching_tariff(): void
    {
        // Zwei offene Auftraege, aber nur EINER hat den Tarif der Bestaetigung.
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $a = $this->eweAuftrag();
        $a['energie']['meter_number'] = '1LOG0011111111';
        $a['energie']['tariff'] = 'EWE Grünstrom basis';
        $intake->createContractFromExtraction($this->doc($a), $customer, null);

        $b = $this->eweAuftrag();
        $b['energie']['meter_number'] = '1LOG0022222222';
        $passend = $intake->createContractFromExtraction($this->doc($b), $customer, null);

        $result = $intake->createContractFromExtraction($this->doc($this->eweBestaetigung()), $customer, null);

        $this->assertSame($passend->id, $result->id);
        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());
    }

    public function test_manually_created_contract_is_never_completed_automatically(): void
    {
        // Ein Vertrag ohne Stufe (Altbestand/manuell angelegt) wird von der
        // Automatik nie angefasst - nur ausdrueckliche Antraege.
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $manuell = Contract::create([
            'customer_id' => $customer->id,
            'type' => 'strom',
            'insurer' => 'EWE VERTRIEB GmbH',
            'status' => 'active',
        ]);

        $neu = $intake->createContractFromExtraction($this->doc($this->eweBestaetigung()), $customer, null);

        $this->assertNotSame($manuell->id, $neu->id);
        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());
    }

    public function test_stale_application_is_not_completed(): void
    {
        // Ein uralter Auftrag (>12 Monate) gehoert nicht mehr zu einer heute
        // eintreffenden Bestaetigung.
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $alt = $intake->createContractFromExtraction($this->doc($this->eweAuftrag()), $customer, null);
        Contract::whereKey($alt->id)->update(['created_at' => now()->subMonths(20)]);

        $neu = $intake->createContractFromExtraction($this->doc($this->eweBestaetigung()), $customer, null);

        $this->assertNotSame($alt->id, $neu->id);
        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());
    }

    public function test_provisional_application_number_is_replaced_by_final_contract_number(): void
    {
        // DSL: der Auftrag traegt nur die AUFTRAGSNUMMER. Der spaetere
        // Provider-Vertrag bringt die endgueltige Vertragsnummer mit.
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $auftrag = $intake->createContractFromExtraction($this->doc([
            'versicherung' => ['insurer' => 'Telekom', 'sparte' => 'internet', 'contract_number' => 'A-778899',
                'document_stage' => Contract::STAGE_ANTRAG],
            'internet' => ['tariff' => 'MagentaZuhause L', 'speed' => '100 MBit/s'],
        ], 'internetvertrag'), $customer, null);
        $this->assertSame('A-778899', $auftrag->contract_number);

        $result = $intake->createContractFromExtraction($this->doc([
            'versicherung' => ['insurer' => 'Telekom', 'sparte' => 'internet', 'contract_number' => 'VTR-4711',
                'start_date' => '2026-08-15', 'document_stage' => Contract::STAGE_VERTRAG],
            'internet' => ['tariff' => 'MagentaZuhause L'],
        ], 'internetvertrag'), $customer, null);

        $this->assertSame($auftrag->id, $result->id);
        $this->assertSame('VTR-4711', $result->fresh()->contract_number);

        $rev = ContractRevision::where('contract_id', $result->id)->where('field', 'contract_number')->first();
        $this->assertSame('A-778899', $rev->old_value);
        $this->assertSame('VTR-4711', $rev->new_value);
    }

    public function test_confirmed_contract_never_falls_back_to_application(): void
    {
        // Ein nachtraeglich hochgeladener Auftrag macht aus einem bestaetigten
        // Vertrag nie wieder einen Antrag.
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $vertrag = $intake->createContractFromExtraction($this->doc($this->eweBestaetigung()), $customer, null);
        $this->assertSame(Contract::STAGE_VERTRAG, $vertrag->stage);

        $auftrag = $this->eweAuftrag();
        $auftrag['energie']['malo_id'] = '50307481544'; // dieselbe Lieferstelle
        $result = $intake->createContractFromExtraction($this->doc($auftrag), $customer, null);

        $this->assertSame($vertrag->id, $result->id);
        $this->assertSame(Contract::STAGE_VERTRAG, $result->fresh()->stage);
    }

    public function test_later_document_is_linked_by_provider_customer_number(): void
    {
        // Jede spaetere Post zum Vertrag (z.B. die Jahresrechnung) nennt
        // Kundennummer/Vertragsnummer -> Dokument landet am richtigen Vertrag.
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $vertrag = $intake->createContractFromExtraction($this->doc($this->eweBestaetigung()), $customer, null);

        $rechnung = $this->doc([
            'versicherung' => ['insurer' => 'EWE VERTRIEB GmbH', 'sparte' => 'strom'],
            'energie' => ['customer_number' => '22434078'],
        ], 'rechnung');
        $rechnung->customer_id = $customer->id;
        $rechnung->save();

        $linked = $intake->linkMatchingContract($rechnung, $customer);

        $this->assertNotNull($linked);
        $this->assertSame($vertrag->id, $linked->id);
        $this->assertSame($vertrag->id, (string) $rechnung->fresh()->contract_id);
    }

    public function test_meter_photo_is_linked_by_normalized_meter_number_and_adds_reading(): void
    {
        // Auf dem Zaehler steht "1 LOG00 9228 3078", im Auftrag
        // "1LOG0092283078" - dieselbe Nummer. Das Foto muss trotzdem am
        // richtigen Vertrag landen und den Zaehlerstand nachtragen.
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $vertrag = $intake->createContractFromExtraction($this->doc($this->eweAuftrag()), $customer, null);

        $foto = $this->doc([
            'energie' => ['meter_number' => '1 LOG00 9228 3078', 'meter_reading' => 4680.0],
        ], 'zaehlerfoto');
        $foto->customer_id = $customer->id;
        $foto->save();

        $linked = $intake->linkMatchingContract($foto, $customer);

        $this->assertNotNull($linked);
        $this->assertSame($vertrag->id, $linked->id);
        $en = ContractEnergyDetail::where('contract_id', $vertrag->id)->first();
        $this->assertSame(4680.0, (float) $en->meter_reading);
        // Die bestehende Schreibweise der Zaehlernummer bleibt unangetastet.
        $this->assertSame('1LOG0092283078', $en->meter_number);
    }

    public function test_confirmation_completes_application_via_automatic_linking(): void
    {
        // Auch ohne den Klick "Vertrag anlegen" (Dokument liegt bereits in der
        // Kundenakte) erkennt die Automatik die Bestaetigung zum Auftrag.
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $auftrag = $intake->createContractFromExtraction($this->doc($this->eweAuftrag()), $customer, null);

        $bestaetigung = $this->doc($this->eweBestaetigung());
        $bestaetigung->customer_id = $customer->id;
        $bestaetigung->save();

        $linked = $intake->linkMatchingContract($bestaetigung, $customer);

        $this->assertNotNull($linked);
        $this->assertSame($auftrag->id, $linked->id);
        $this->assertSame('1004418075', $linked->fresh()->contract_number);
        $this->assertSame(Contract::STAGE_VERTRAG, $linked->fresh()->stage);
        $this->assertSame(1, Contract::where('customer_id', $customer->id)->count());
    }

    public function test_document_stage_is_derived_from_type_and_contract_number(): void
    {
        // Ohne ausdrueckliche Angabe: eine Police ist immer ein Vertrag, ein
        // Beratungsprotokoll immer ein Antrag, und ein Auftragsdokument MIT
        // Vertragsnummer gilt als Bestaetigung.
        $this->assertSame(Contract::STAGE_VERTRAG, Document::contractStageFor('versicherungspolice', []));
        $this->assertSame(Contract::STAGE_ANTRAG, Document::contractStageFor('beratungsprotokoll', []));
        $this->assertSame(Contract::STAGE_ANTRAG, Document::contractStageFor('energieauftrag', [
            'versicherung' => ['insurer' => 'EWE'],
        ]));
        $this->assertSame(Contract::STAGE_VERTRAG, Document::contractStageFor('energieauftrag', [
            'versicherung' => ['contract_number' => '1004418075'],
        ]));
        // Ausdrueckliche Angabe der Extraktion gewinnt immer.
        $this->assertSame(Contract::STAGE_ANTRAG, Document::contractStageFor('energieauftrag', [
            'versicherung' => ['contract_number' => 'A-778899', 'document_stage' => Contract::STAGE_ANTRAG],
        ]));
        // Unbekanntes Ablage-Dokument: keine Aussage.
        $this->assertNull(Document::contractStageFor('sonstiges', []));
    }
}
