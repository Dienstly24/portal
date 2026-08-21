<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use App\Models\VermittlerSettlement;
use App\Services\Ai\TemplateParsers\VermittlerVorgangslisteHinweisParser;
use App\Services\Vermittler\VermittlerAbrechnungImporter;
use App\Services\Vermittler\VermittlerVorgangslisteImporter;
use App\Services\Vermittler\VermittlerVorgangslisteParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Vorgangsliste des Vermittlers (gemeldeter Fall 21.08.2026).
 *
 * Ausgangslage: der Betreiber hat die Uebersicht der OFFENEN Vorgaenge als
 * Screenshot in den Dokumenten-Eingang geladen, damit das System jede `Id`
 * mit ihrer Referenz-Nr. verbindet. Der Eingang ordnet aber immer EIN
 * Dokument EINEM Kunden zu - eine Liste mit den Vorgaengen vieler Kunden
 * kann er nicht verarbeiten ("Sonstiges Dokument / Kein Kunde gefunden").
 *
 * Diese Tests halten den neuen Weg fest: die Liste wird unter
 * Vermittler-Abrechnung eingelesen und stellt fuer JEDEN Vorgang die
 * Bruecke her - ohne je etwas abzurechnen und ohne je zu raten.
 */
class VermittlerVorgangslisteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function customer(string $name = 'Max Mustermann'): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5($name . $user->id), 0, 8)),
        ]);
    }

    private function contract(Customer $customer, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'customer_id' => $customer->id,
            'type' => 'kfz',
            'insurer' => 'AdmiralDirekt',
            'status' => 'active',
        ], $overrides));
    }

    /**
     * Der Text so, wie ihn die Texterkennung aus dem Screenshot des Portals
     * liefert: Kopfzeile, je Vorgang eine Zeile, die Referenznummer als
     * eigene Zeile darunter - und der erste Vorgang ohne Referenznummer.
     */
    private function screenshotText(): string
    {
        return <<<TXT
        Datum        Produkt                       ID         Status
        20.08.2026   Rechtsschutzversicherung      9782530    offen
        20.08.2026   Kfz-Versicherung Abschluss    9783872    offen
        Referenznummer: 1477-6741-9200-53
        20.08.2026   Kfz-Versicherung Abschluss    9783674    offen
        Referenznummer: 1447-5771-4260-46
        20.08.2026   Kfz-Versicherung Abschluss    9783710    offen
        Referenznummer: 1447-9735-6260-53
        20.08.2026   Kfz-Versicherung Abschluss    9783748    offen
        Referenznummer: 1437-4753-8230-50
        TXT;
    }

    private function importText(string $text, ?int $userId = null)
    {
        return app(VermittlerVorgangslisteImporter::class)->importText($text, 'vorgangsliste.png', $userId);
    }

    // ---------------------------------------------------------------
    // Lesen: die Referenz-Nr. gehoert zum Vorgang UEBER ihr
    // ---------------------------------------------------------------

    public function test_parser_reads_every_vorgang_with_its_reference(): void
    {
        $parsed = app(VermittlerVorgangslisteParser::class)->parse($this->screenshotText());

        $this->assertFalse($parsed['ambiguous']);
        $this->assertCount(5, $parsed['rows']);

        $paare = collect($parsed['rows'])->pluck('reference_number', 'vermittler_id')->all();
        $this->assertSame([
            '9782530' => null, // erste Zeile hat keine Referenznummer
            '9783872' => '1477-6741-9200-53',
            '9783674' => '1447-5771-4260-46',
            '9783710' => '1447-9735-6260-53',
            '9783748' => '1437-4753-8230-50',
        ], $paare);

        $this->assertSame('2026-08-20', $parsed['rows'][0]['datum']);
        $this->assertSame('offen', $parsed['rows'][0]['status']);
        $this->assertStringContainsString('Rechtsschutz', (string) $parsed['rows'][0]['produkt']);
    }

    /** Weder das Datum noch die Referenz-Nr. darf als Vorgangs-Id durchgehen. */
    public function test_parser_never_mistakes_a_date_or_reference_for_an_id(): void
    {
        $parsed = app(VermittlerVorgangslisteParser::class)->parse($this->screenshotText());

        foreach ($parsed['rows'] as $row) {
            $this->assertMatchesRegularExpression('/^\d{6,10}$/', $row['vermittler_id']);
            $this->assertNotSame('2026', $row['vermittler_id']);
        }
    }

    /**
     * OCR-Ernstfall: Tesseract liest die Tabelle SPALTENWEISE - erst alle
     * Vorgaenge, dann alle Referenznummern. Dann ist keine Paarung mehr
     * belegbar. Genau hier darf nicht geraten werden.
     */
    public function test_column_wise_ocr_output_is_reported_as_ambiguous(): void
    {
        $text = "9783872 offen\n9783674 offen\n9783710 offen\n"
            . "Referenznummer: 1477-6741-9200-53\n"
            . "Referenznummer: 1447-5771-4260-46\n"
            . "Referenznummer: 1447-9735-6260-53\n";

        $parsed = app(VermittlerVorgangslisteParser::class)->parse($text);

        $this->assertTrue($parsed['ambiguous'], 'Zwei Referenz-Nummern auf einem Vorgang = nicht vertrauenswuerdig.');
        $this->assertNotEmpty($parsed['notes']);
    }

    public function test_ambiguous_list_links_nothing_and_goes_to_review(): void
    {
        $contract = $this->contract($this->customer(), ['reference_number' => '1477-6741-9200-53']);

        $import = $this->importText("9783872 offen\n9783674 offen\n"
            . "Referenznummer: 1477-6741-9200-53\nReferenznummer: 1447-5771-4260-46\n");

        $this->assertNull($contract->refresh()->vermittler_id, 'Bei unsicherer Erkennung wird nichts verknuepft.');
        $this->assertSame(0, $import->rows_new_link);
        $this->assertSame($import->rows_total, $import->rows_review);
    }

    // ---------------------------------------------------------------
    // Die Bruecke: das eigentliche Ziel des Betreibers
    // ---------------------------------------------------------------

    public function test_list_connects_every_reference_to_its_vermittler_id(): void
    {
        $customer = $this->customer();
        $a = $this->contract($customer, ['reference_number' => '1477-6741-9200-53']);
        $b = $this->contract($customer, ['reference_number' => '1447-5771-4260-46', 'type' => 'hausrat']);

        $import = $this->importText($this->screenshotText());

        $this->assertSame('9783872', $a->refresh()->vermittler_id);
        $this->assertSame('9783674', $b->refresh()->vermittler_id);
        $this->assertSame(Contract::VERMITTLER_ID_ZUGEORDNET, $a->refresh()->vermittlerStatus());
        $this->assertSame(2, $import->rows_new_link);
        $this->assertDatabaseHas('vermittler_match_events', ['contract_id' => $a->id, 'action' => 'id_linked']);
    }

    /**
     * Das Kernversprechen: nach der Vorgangsliste findet eine Abrechnung
     * OHNE Referenz-Nr. ihren Vertrag ueber die Id allein - genau der Fall,
     * fuer den der Betreiber die Liste hochgeladen hat.
     */
    public function test_bridge_makes_a_later_settlement_without_reference_find_the_contract(): void
    {
        $contract = $this->contract($this->customer(), ['reference_number' => '1477-6741-9200-53']);
        $this->importText($this->screenshotText());

        // Spaetere Abrechnungsdatei: nur Id, Status 4 (abgerechnet), 75 EUR.
        $path = tempnam(sys_get_temp_dir(), 'vm') . '.csv';
        file_put_contents($path, "\"Datum\";\"Produkt\";\"Id\";\"Status\";\"Provision\"\n"
            . "\"2026-11-20 00:00:00\";\"Kfz-Versicherung Abschluss\";\"9783872\";\"4\";\"75\"\n");

        $import = app(VermittlerAbrechnungImporter::class)->import($path, 'abrechnung.csv', null, false);

        $this->assertSame(1, $import->rows_matched);
        $this->assertSame(Contract::VERMITTLER_ABGERECHNET, $contract->refresh()->vermittlerStatus());
        $settlement = VermittlerSettlement::where('vermittler_id', '9783872')->firstOrFail();
        $this->assertSame(75.0, (float) $settlement->provision);
        // Und es bleibt EINE Zeile - die Liste hat sie angelegt, die
        // Abrechnung hat sie ergaenzt.
        $this->assertSame(1, VermittlerSettlement::where('vermittler_id', '9783872')->count());
    }

    /** Eine Liste ist keine Abrechnung: kein Betrag, kein Storno, kein "nicht gefunden". */
    public function test_list_never_creates_an_accounting(): void
    {
        $customer = $this->customer();
        $this->contract($customer, ['reference_number' => '1477-6741-9200-53']);
        $unbeteiligt = $this->contract($customer, ['reference_number' => '9999-9999-9999-99', 'type' => 'hausrat']);

        $import = $this->importText($this->screenshotText());

        $this->assertSame(0, $import->rows_storno);
        $this->assertSame(0, $import->contracts_not_found);
        $this->assertNull(VermittlerSettlement::where('vermittler_id', '9783872')->firstOrFail()->provision);
        // Ein Vertrag, der nicht in der Liste steht, wird NICHT abgewertet.
        $this->assertSame(Contract::VERMITTLER_REFERENZ, $unbeteiligt->refresh()->vermittlerStatus());
    }

    /** Eine spaetere Liste stuft einen bereits abgerechneten Vertrag nie zurueck. */
    public function test_list_never_downgrades_a_settled_contract(): void
    {
        $contract = $this->contract($this->customer(), [
            'reference_number' => '1477-6741-9200-53',
            'vermittler_id' => '9783872',
            'vermittler_status' => Contract::VERMITTLER_ABGERECHNET,
        ]);

        $this->importText($this->screenshotText());

        $this->assertSame(Contract::VERMITTLER_ABGERECHNET, $contract->refresh()->vermittlerStatus());
    }

    /** Nie raten: dieselbe Referenz-Nr. an zwei Vertraegen bleibt unzugeordnet. */
    public function test_duplicate_reference_is_not_linked(): void
    {
        $customer = $this->customer();
        $a = $this->contract($customer, ['reference_number' => '1477-6741-9200-53']);
        $b = $this->contract($customer, ['reference_number' => '1477-6741-9200-53', 'type' => 'hausrat']);

        $import = $this->importText($this->screenshotText());

        $this->assertNull($a->refresh()->vermittler_id);
        $this->assertNull($b->refresh()->vermittler_id);
        $this->assertGreaterThan(0, $import->rows_review);
    }

    /** Nie raten: eine abweichende Referenz-Nr. bei bekannter Id wird gemeldet. */
    public function test_conflicting_reference_goes_to_review(): void
    {
        $contract = $this->contract($this->customer(), [
            'reference_number' => '1111-2222-3333-44',
            'vermittler_id' => '9783872',
        ]);

        $import = $this->importText($this->screenshotText());

        $this->assertSame(Contract::VERMITTLER_PRUEFUNG, $contract->refresh()->vermittlerStatus());
        $this->assertSame('1111-2222-3333-44', $contract->refresh()->reference_number);
        $this->assertGreaterThan(0, $import->rows_review);
    }

    /** Ein Vorgang, dessen Referenz-Nr. bei keinem Vertrag steht, bleibt sichtbar offen. */
    public function test_unknown_reference_lands_in_the_review_list(): void
    {
        $this->importText($this->screenshotText());

        $settlement = VermittlerSettlement::where('vermittler_id', '9783872')->firstOrFail();
        $this->assertTrue($settlement->requiresDecision());
        $this->assertStringContainsString('1477-6741-9200-53', (string) $settlement->match_note);
    }

    /** Dieselbe Liste zweimal einlesen legt nichts doppelt an. */
    public function test_reading_the_same_list_twice_creates_no_duplicates(): void
    {
        $this->contract($this->customer(), ['reference_number' => '1477-6741-9200-53']);

        $this->importText($this->screenshotText());
        $this->importText($this->screenshotText());

        $this->assertSame(5, VermittlerSettlement::count());
    }

    // ---------------------------------------------------------------
    // Dokumenten-Eingang: die Sackgasse von damals
    // ---------------------------------------------------------------

    public function test_document_inbox_recognises_the_list_and_points_to_the_right_place(): void
    {
        $result = app(VermittlerVorgangslisteHinweisParser::class)->parse($this->screenshotText());

        $this->assertNotNull($result, 'Die Liste wird im Eingang erkannt - gratis, ohne KI-Aufruf.');
        $this->assertSame('vermittler_vorgangsliste', $result['type']);
        $this->assertStringContainsString('Vermittler-Abrechnung', $result['summary']);
        $this->assertSame([], $result['data']['person'], 'Aus einer Liste vieler Kunden werden nie Personendaten gelesen.');
    }

    /** Ein normales Kundendokument darf davon nie betroffen sein. */
    public function test_a_normal_customer_document_is_not_treated_as_a_list(): void
    {
        $text = "Vielen Dank, Ihr Antrag ist bei uns eingegangen.\n"
            . "Referenznummer: 1477-6741-9200-53\nVersicherungsbeginn: Tag der Zulassung\n";

        $this->assertNull(app(VermittlerVorgangslisteHinweisParser::class)->parse($text));
    }

    // ---------------------------------------------------------------
    // Oberflaeche
    // ---------------------------------------------------------------

    public function test_csv_export_of_the_list_works_without_ocr(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $contract = $this->contract($this->customer(), ['reference_number' => '1477-6741-9200-53']);

        $csv = "Datum;Produkt;ID;Status;Referenznummer\n"
            . "20.08.2026;Kfz-Versicherung Abschluss;9783872;offen;1477-6741-9200-53\n";
        $path = tempnam(sys_get_temp_dir(), 'vl') . '.csv';
        file_put_contents($path, $csv);

        $response = $this->actingAs($admin)->post('/admin/vermittler-abrechnung/vorgangsliste', [
            'liste_datei' => new UploadedFile($path, 'vorgaenge.csv', 'text/csv', null, true),
        ]);

        $response->assertRedirect();
        $this->assertSame('9783872', $contract->refresh()->vermittler_id);
    }

    public function test_file_without_any_vorgang_is_refused_with_a_clear_message(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $path = tempnam(sys_get_temp_dir(), 'vl') . '.csv';
        file_put_contents($path, "nur irgendein Text ohne Vorgaenge\n");

        $this->actingAs($admin)->post('/admin/vermittler-abrechnung/vorgangsliste', [
            'liste_datei' => new UploadedFile($path, 'leer.csv', 'text/csv', null, true),
        ])->assertRedirect();

        // Ein Lauf ohne erkannten Vorgang wird nicht gespeichert.
        $this->assertDatabaseCount('vermittler_imports', 0);
    }

    public function test_only_admin_and_manager_may_import_a_list(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $path = tempnam(sys_get_temp_dir(), 'vl') . '.csv';
        file_put_contents($path, "Datum;Produkt;ID;Status\n20.08.2026;Kfz;9783872;offen\n");

        $this->actingAs($employee)->post('/admin/vermittler-abrechnung/vorgangsliste', [
            'liste_datei' => new UploadedFile($path, 'vorgaenge.csv', 'text/csv', null, true),
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertDatabaseCount('vermittler_imports', 0);
    }
}
