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

    // Betreiber-Vorgabe 26.07.2026: ein Dokument des NEUEN Versicherers fuer
    // dasselbe Fahrzeug ist ein WECHSEL -> eigener Vertrag (alter gekuendigt
    // zum X, neuer aktiv ab X), KEIN Update des Altvertrags.
    public function test_insurer_switch_creates_separate_contract_for_same_plate(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $first = $this->doc([
            'versicherung' => ['insurer' => 'ADAC Autoversicherung AG', 'sparte' => 'kfz', 'start_date' => '2025-09-03'],
            'kfz' => ['license_plate' => 'LÜN-G 1110'],
        ]);
        $alt = $intake->createContractFromExtraction($first, $customer, null);

        $second = $this->doc([
            'versicherung' => ['insurer' => 'Neodigital', 'sparte' => 'kfz', 'start_date' => '2026-09-03'],
            'kfz' => ['license_plate' => 'LUN-G 1110'],
        ]);
        $neu = $intake->createContractFromExtraction($second, $customer, null);

        $this->assertNotSame($alt->id, $neu->id);
        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());

        // Wechsel-Automatik: der Altvertrag bekommt die Kuendigung erfasst -
        // Ablauf = Beginn des neuen Vertrags, Einreichung dokumentiert,
        // alles nachvollziehbar in der Version History (Quelle: Dokument).
        $alt->refresh();
        $this->assertSame('2026-09-03', (string) $alt->end_date);
        $this->assertNotNull($alt->cancellation_date);
        $this->assertSame('Gekündigt zum 03.09.2026', $alt->displayStatus()['label']);
        $this->assertTrue(
            ContractRevision::where('contract_id', $alt->id)
                ->where('field', 'end_date')->where('source', 'document')->exists()
        );
    }

    // Audit INTAKE-1: "R+V" darf nicht mit einem fremden Versicherer verwechselt
    // werden (fruehere Normalisierung schrumpfte "R+V" auf "r", Teilstring von
    // fast jedem Namen). Eine Generali-Police fuer dasselbe Kennzeichen ist ein
    // WECHSEL -> eigener Vertrag, nicht Ueberschreiben des R+V-Bestands.
    public function test_rv_insurer_not_confused_with_other_insurer_on_same_plate(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $rv = $intake->createContractFromExtraction($this->doc([
            'versicherung' => ['insurer' => 'R+V Allgemeine Versicherung AG', 'sparte' => 'kfz', 'start_date' => '2025-09-03'],
            'kfz' => ['license_plate' => 'RD-AS 1212'],
        ]), $customer, null);

        $generali = $intake->createContractFromExtraction($this->doc([
            'versicherung' => ['insurer' => 'Generali', 'sparte' => 'kfz', 'start_date' => '2026-09-03'],
            'kfz' => ['license_plate' => 'RD-AS 1212'],
        ]), $customer, null);

        $this->assertNotSame($rv->id, $generali->id, 'Generali darf den R+V-Vertrag nicht ueberschreiben');
        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());
        $rv->refresh();
        $this->assertSame('R+V Allgemeine Versicherung AG', $rv->insurer, 'R+V-Bestand bleibt erhalten');

        // Gegenprobe: derselbe Versicherer (R+V-Variante) bleibt EIN Vertrag.
        $rvAgain = $intake->createContractFromExtraction($this->doc([
            'versicherung' => ['insurer' => 'R+V Versicherung', 'sparte' => 'kfz', 'start_date' => '2025-09-03', 'premium_amount' => 411],
            'kfz' => ['license_plate' => 'RD-AS 1212'],
        ]), $customer, null);
        $this->assertSame($rv->id, $rvAgain->id, 'R+V-Variante aktualisiert den bestehenden R+V-Vertrag');
    }

    // Audit INTAKE-2: MaLo-ID/Zaehlernummer bezeichnen den physischen Zaehler,
    // nicht den Versorger - beim Anbieterwechsel bleiben sie gleich. Die
    // Bestaetigung eines ANDEREN Versorgers (gleiche MaLo) ist ein Wechsel und
    // darf den Bestandsvertrag nicht ueberschreiben.
    public function test_energy_supplier_switch_creates_separate_contract_for_same_malo(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $lichtblick = $intake->createContractFromExtraction($this->doc([
            'versicherung' => ['insurer' => 'LichtBlick', 'sparte' => 'strom', 'start_date' => '2025-08-01'],
            'energie' => ['meter_number' => '1LOG0092283078', 'malo_id' => '51234567890'],
        ], 'energievertrag'), $customer, null);

        $eon = $intake->createContractFromExtraction($this->doc([
            'versicherung' => ['insurer' => 'E.ON Energie Deutschland', 'sparte' => 'strom', 'start_date' => '2026-08-01'],
            'energie' => ['meter_number' => '1LOG0092283078', 'malo_id' => '51234567890'],
        ], 'energievertrag'), $customer, null);

        $this->assertNotSame($lichtblick->id, $eon->id, 'E.ON darf den LichtBlick-Vertrag nicht ueberschreiben');
        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());
        $lichtblick->refresh();
        $this->assertSame('LichtBlick', $lichtblick->insurer, 'LichtBlick-Bestand bleibt erhalten');
    }

    // Wechsel-Dokument OHNE Beginn: keine Verkettung moeglich -> der
    // Altvertrag bleibt unangetastet (keine erfundenen Daten).
    public function test_switch_document_without_start_leaves_old_contract_untouched(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $first = $this->doc([
            'versicherung' => ['insurer' => 'ADAC Autoversicherung AG', 'sparte' => 'kfz', 'start_date' => '2025-09-03'],
            'kfz' => ['license_plate' => 'K-WW 12'],
        ]);
        $alt = $intake->createContractFromExtraction($first, $customer, null);

        $second = $this->doc([
            'versicherung' => ['insurer' => 'Neodigital', 'sparte' => 'kfz'],
            'kfz' => ['license_plate' => 'K-WW 12'],
        ]);
        $intake->createContractFromExtraction($second, $customer, null);

        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());
        $this->assertNull($alt->fresh()->cancellation_date);
    }

    // Gleicher Versicherer (Kurzform "ADAC"), Kennzeichen einmal mit und
    // einmal ohne Umlaut geschrieben -> dasselbe Fahrzeug, KEIN Duplikat.
    public function test_umlaut_plate_variant_with_same_insurer_updates_existing(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);

        $first = $this->doc([
            'versicherung' => ['insurer' => 'ADAC Autoversicherung AG', 'sparte' => 'kfz', 'premium_amount' => 116.68],
            'kfz' => ['license_plate' => 'LÜN-G 1110'],
        ]);
        $contract = $intake->createContractFromExtraction($first, $customer, null);

        $second = $this->doc([
            'versicherung' => ['insurer' => 'ADAC', 'sparte' => 'kfz', 'premium_amount' => 121.50],
            'kfz' => ['license_plate' => 'LUN-G1110'],
        ]);
        $result = $intake->createContractFromExtraction($second, $customer, null);

        $this->assertSame($contract->id, $result->id);
        $this->assertSame(1, Contract::where('customer_id', $customer->id)->count());
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
