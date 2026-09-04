<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use App\Models\VermittlerMatchEvent;
use App\Models\VermittlerSettlement;
use App\Services\Vermittler\VermittlerAbrechnungImporter;
use App\Services\Vermittler\VermittlerLinkService;
use App\Services\Vermittler\VermittlerReference;
use App\Services\Vermittler\VermittlerReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Ring zwischen Vertrag und Vermittler-Abrechnung (Betreiber-Auftrag
 * 20.08.2026): Referenz-Nr. -> Vertrag -> Vermittler-ID -> Abrechnung.
 *
 * Die Tests halten vor allem die VIER GRUNDREGELN fest, an denen die
 * Zuordnung ausgerichtet ist: nie raten, nie Vertragsdaten aendern, nie
 * loeschen, nie doppelt anlegen.
 */
class VermittlerAbrechnungTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Vite-Assets werden in der Testumgebung nicht gebaut.
        $this->withoutVite();
    }

    private function customer(string $name = 'Max Mustermann'): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(substr(md5($name.$user->id), 0, 8)),
        ]);
    }

    private function contract(Customer $customer, array $overrides = []): Contract
    {
        $createdAt = $overrides['created_at'] ?? now()->subYear();
        unset($overrides['created_at']);

        $contract = Contract::create(array_merge([
            'customer_id' => $customer->id,
            'type' => 'kfz',
            'insurer' => 'AdmiralDirekt',
            'status' => 'active',
        ], $overrides));

        // Der Gegen-Abgleich betrachtet nur Vertraege, die es zum Zeitpunkt
        // der Abrechnung schon gab - die Tests brauchen deshalb ein echtes
        // Anlagedatum in der Vergangenheit (created_at ist nicht fillable).
        $contract->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $contract->refresh();
    }

    /** CSV im Format des Vermittlers erzeugen. */
    private function csv(array $rows, bool $withReference = true): string
    {
        $header = '"Datum";"Produkt";"Id";"Status";"Provision";"Tracking-Id";"Stornogrund"'
            .($withReference ? ';"Referenz-Nr."' : '');
        $lines = [$header];
        foreach ($rows as $row) {
            $cells = [
                $row['datum'] ?? '2026-08-18 00:00:00',
                $row['produkt'] ?? 'Kfz-Versicherung Abschluss',
                $row['id'] ?? '9781530',
                $row['status'] ?? '1',
                $row['provision'] ?? '75',
                $row['tracking'] ?? '',
                $row['storno'] ?? '',
            ];
            if ($withReference) {
                $cells[] = $row['referenz'] ?? '';
            }
            $lines[] = '"'.implode('";"', $cells).'"';
        }

        $path = tempnam(sys_get_temp_dir(), 'vm').'.csv';
        file_put_contents($path, implode("\n", $lines)."\n");
        return $path;
    }

    private function import(string $path, bool $reconcile = true, ?int $userId = null)
    {
        return app(VermittlerAbrechnungImporter::class)
            ->import($path, basename($path), $userId, $reconcile);
    }

    // ---------------------------------------------------------------
    // Der Hauptfall: Referenz-Nr. -> Vermittler-ID -> Abrechnung
    // ---------------------------------------------------------------

    public function test_reference_number_links_contract_to_settlement_id(): void
    {
        $contract = $this->contract($this->customer(), ['reference_number' => '1477-6741-9200-53']);

        $import = $this->import($this->csv([
            ['id' => '9753224', 'referenz' => '1477-6741-9200-53', 'provision' => '75'],
        ]));

        $contract->refresh();
        $this->assertSame('9753224', $contract->vermittler_id, 'Die Id des Vermittlers wird dauerhaft am Vertrag hinterlegt.');
        $this->assertSame(Contract::VERMITTLER_IN_ABRECHNUNG, $contract->vermittlerStatus());
        $this->assertNotNull($contract->vermittler_matched_at);
        $this->assertSame(1, $import->rows_new_link);

        $settlement = VermittlerSettlement::where('vermittler_id', '9753224')->firstOrFail();
        $this->assertSame($contract->id, $settlement->contract_id);
        $this->assertSame($contract->customer_id, $settlement->customer_id);
        $this->assertSame(75.0, (float) $settlement->provision);
    }

    /**
     * Kernversprechen der Bruecke: Ist die Verknuepfung einmal hergestellt,
     * genuegt in spaeteren Dateien die Id - die Referenz-Nr. darf fehlen.
     */
    public function test_later_file_without_reference_column_still_finds_the_contract(): void
    {
        $contract = $this->contract($this->customer(), ['reference_number' => '1477-6741-9200-53']);
        $this->import($this->csv([['id' => '9753224', 'referenz' => '1477-6741-9200-53']]));

        // Zweite Datei: nur noch die Id, keine Referenz-Spalte.
        $import = $this->import($this->csv([['id' => '9753224', 'status' => '4']], withReference: false));

        $contract->refresh();
        $this->assertSame(Contract::VERMITTLER_ABGERECHNET, $contract->vermittlerStatus());
        $this->assertSame(1, $import->rows_matched);
        $this->assertSame(0, $import->rows_unmatched);
    }

    /** Schreibweisen duerfen die Zuordnung nicht kosten - der Wert bleibt trotzdem original. */
    public function test_reference_is_matched_normalized_but_stored_unchanged(): void
    {
        $contract = $this->contract($this->customer(), ['reference_number' => '1477 6741 9200 53']);

        $this->import($this->csv([['id' => '9753224', 'referenz' => '1477-6741-9200-53']]));

        $contract->refresh();
        $this->assertSame('9753224', $contract->vermittler_id);
        $this->assertSame('1477 6741 9200 53', $contract->reference_number, 'Die erfasste Schreibweise wird nie überschrieben.');
        $this->assertTrue(VermittlerReference::same('1477-6741-9200-53', '1477 6741 9200 53'));
    }

    // ---------------------------------------------------------------
    // Regel 1: nie raten
    // ---------------------------------------------------------------

    /** Gleiche Id, andere Referenz-Nr.: gefaehrlichster Fall - keine Korrektur. */
    public function test_conflicting_reference_number_requires_review(): void
    {
        $contract = $this->contract($this->customer(), [
            'reference_number' => '1477-6741-9200-53',
            'vermittler_id' => '9753224',
        ]);

        $import = $this->import($this->csv([['id' => '9753224', 'referenz' => '1497-1111-2222-33']]));

        $contract->refresh();
        $this->assertSame(Contract::VERMITTLER_PRUEFUNG, $contract->vermittlerStatus());
        $this->assertSame('1477-6741-9200-53', $contract->reference_number, 'Die erfasste Referenz-Nr. wird nie automatisch "korrigiert".');
        $this->assertSame(1, $import->rows_review);
        $this->assertDatabaseHas('vermittler_match_events', ['contract_id' => $contract->id, 'action' => 'conflict']);
    }

    /** Dieselbe Referenz-Nr. an zwei Vertraegen: keine automatische Zuordnung. */
    public function test_duplicate_reference_number_blocks_automatic_link(): void
    {
        $customer = $this->customer();
        $a = $this->contract($customer, ['reference_number' => '1477-6741-9200-53']);
        $b = $this->contract($customer, ['reference_number' => '1477-6741-9200-53', 'type' => 'rechtsschutz']);

        $import = $this->import($this->csv([['id' => '9753224', 'referenz' => '1477-6741-9200-53']]));

        $this->assertNull($a->refresh()->vermittler_id);
        $this->assertNull($b->refresh()->vermittler_id);
        $this->assertSame(1, $import->rows_review);
        $this->assertStringContainsString('Doppelte Referenz-Nr.', (string) VermittlerSettlement::first()->match_note);
    }

    /** Unbekannter Status-Code: der Vertrag bekommt keinen erfundenen Zustand. */
    public function test_unknown_status_code_never_produces_a_guessed_state(): void
    {
        $contract = $this->contract($this->customer(), ['reference_number' => '1477-6741-9200-53']);

        $this->import($this->csv([['id' => '9753224', 'referenz' => '1477-6741-9200-53', 'status' => '9']]));

        $this->assertSame(Contract::VERMITTLER_PRUEFUNG, $contract->refresh()->vermittlerStatus());
    }

    /** Ein Vertrag wird nur storniert, wenn die Abrechnung ihn als storniert ausweist. */
    public function test_storno_only_with_storno_status(): void
    {
        $customer = $this->customer();
        $storniert = $this->contract($customer, ['reference_number' => '1477-0000-0000-01']);
        $laufend = $this->contract($customer, ['reference_number' => '1477-0000-0000-02', 'type' => 'hausrat']);

        $import = $this->import($this->csv([
            ['id' => '9001', 'referenz' => '1477-0000-0000-01', 'status' => '2', 'storno' => 'Kein Vertrag zustande gekommen'],
            ['id' => '9002', 'referenz' => '1477-0000-0000-02', 'status' => '1'],
        ]));

        $this->assertSame(Contract::VERMITTLER_STORNIERT, $storniert->refresh()->vermittlerStatus());
        $this->assertSame(Contract::VERMITTLER_IN_ABRECHNUNG, $laufend->refresh()->vermittlerStatus());
        $this->assertSame(1, $import->rows_storno);
        // Der Vertrag selbst bleibt unangetastet - storniert ist die Abrechnung.
        $this->assertSame('active', $storniert->refresh()->status);
    }

    // ---------------------------------------------------------------
    // Regel 2/3: keine Vertragsdaten aendern, nichts loeschen
    // ---------------------------------------------------------------

    public function test_import_never_touches_contract_data(): void
    {
        $contract = $this->contract($this->customer(), [
            'reference_number' => '1477-6741-9200-53',
            'contract_number' => 'VS-4711',
            'insurer' => 'AdmiralDirekt',
            'status' => 'active',
            'premium_amount' => 42.50,
        ]);
        $before = $contract->only(['contract_number', 'insurer', 'status', 'type', 'premium_amount', 'reference_number']);

        $this->import($this->csv([['id' => '9753224', 'referenz' => '1477-6741-9200-53', 'produkt' => 'Kfz-Versicherung Abschluss', 'status' => '2', 'storno' => 'Widerruf']]));

        $this->assertSame($before, $contract->refresh()->only(array_keys($before)));
    }

    /** Fehlt ein Vertrag in der Datei, heisst das "nicht gefunden" - nie geloescht. */
    public function test_missing_contract_is_flagged_not_deleted(): void
    {
        $customer = $this->customer();
        $inFile = $this->contract($customer, ['reference_number' => '1477-0000-0000-01']);
        $notInFile = $this->contract($customer, ['reference_number' => '1477-0000-0000-02', 'type' => 'hausrat']);

        $import = $this->import($this->csv([['id' => '9001', 'referenz' => '1477-0000-0000-01']]));

        $this->assertDatabaseHas('contracts', ['id' => $notInFile->id]);
        $this->assertSame(Contract::VERMITTLER_NICHT_GEFUNDEN, $notInFile->refresh()->vermittlerStatus());
        $this->assertSame(Contract::VERMITTLER_IN_ABRECHNUNG, $inFile->refresh()->vermittlerStatus());
        $this->assertSame(1, $import->contracts_not_found);
    }

    /**
     * Der Gegen-Abgleich darf keine fremden Vorgaenge treffen: eine
     * Referenz-Nr. aus einer ganz anderen Quelle hat ein anderes Format und
     * bleibt unberuehrt.
     */
    public function test_reconciliation_ignores_references_of_other_formats(): void
    {
        $customer = $this->customer();
        $this->contract($customer, ['reference_number' => '1477-0000-0000-01']);
        $fremd = $this->contract($customer, ['reference_number' => 'AUFTRAG-2026-XYZ', 'type' => 'strom']);

        $this->import($this->csv([['id' => '9001', 'referenz' => '1477-0000-0000-01']]));

        $this->assertSame(Contract::VERMITTLER_REFERENZ, $fremd->refresh()->vermittlerStatus());
    }

    /** Ein bereits abgerechneter Vertrag wird von einem spaeteren Lauf nie zurueckgestuft. */
    public function test_settled_contract_is_never_downgraded_by_a_later_run(): void
    {
        $contract = $this->contract($this->customer(), ['reference_number' => '1477-0000-0000-01']);
        $this->import($this->csv([['id' => '9001', 'referenz' => '1477-0000-0000-01', 'status' => '4']]));
        $this->assertSame(Contract::VERMITTLER_ABGERECHNET, $contract->refresh()->vermittlerStatus());

        // Naechste Datei enthaelt diesen Datensatz nicht mehr.
        $this->import($this->csv([['id' => '9999', 'referenz' => '1477-0000-0000-99']]));

        $this->assertSame(Contract::VERMITTLER_ABGERECHNET, $contract->refresh()->vermittlerStatus());
    }

    // ---------------------------------------------------------------
    // Regel 4: nie doppelt
    // ---------------------------------------------------------------

    public function test_same_file_twice_creates_no_duplicates(): void
    {
        $this->contract($this->customer(), ['reference_number' => '1477-6741-9200-53']);
        $path = $this->csv([['id' => '9753224', 'referenz' => '1477-6741-9200-53']]);

        $this->import($path);
        $second = $this->import($path);

        $this->assertSame(1, VermittlerSettlement::count());
        $this->assertSame(1, $second->rows_unchanged, 'Die unveraenderte Zeile meldet "Bereits importiert".');
        $this->assertSame(0, $second->rows_new_link);
    }

    /** Aendert sich der Status in einer spaeteren Datei, wird die Zeile aktualisiert. */
    public function test_status_change_updates_the_existing_row(): void
    {
        $contract = $this->contract($this->customer(), ['reference_number' => '1477-6741-9200-53']);

        $this->import($this->csv([['id' => '9753224', 'referenz' => '1477-6741-9200-53', 'status' => '1']]));
        $this->import($this->csv([['id' => '9753224', 'referenz' => '1477-6741-9200-53', 'status' => '2', 'storno' => 'Erstprämie nicht gezahlt']]));

        $this->assertSame(1, VermittlerSettlement::count());
        $this->assertSame(Contract::VERMITTLER_STORNIERT, $contract->refresh()->vermittlerStatus());
        $this->assertSame('Erstprämie nicht gezahlt', VermittlerSettlement::first()->storno_reason);
    }

    // ---------------------------------------------------------------
    // Historie: sie ueberlebt das Loeschen des Vertrags
    // ---------------------------------------------------------------

    public function test_history_survives_contract_deletion(): void
    {
        $contract = $this->contract($this->customer(), ['reference_number' => '1477-6741-9200-53']);
        $this->import($this->csv([['id' => '9753224', 'referenz' => '1477-6741-9200-53', 'provision' => '75']]));

        $contract->delete();

        $settlement = VermittlerSettlement::where('vermittler_id', '9753224')->firstOrFail();
        $this->assertNull($settlement->contract_id);
        $this->assertSame('1477-6741-9200-53', $settlement->reference_number);
        $this->assertNotNull($settlement->contract_label, 'Der Vertrag bleibt als Klartext-Kopie belegbar.');
        $this->assertSame(75.0, (float) $settlement->provision);

        $event = VermittlerMatchEvent::where('vermittler_id', '9753224')->firstOrFail();
        $this->assertNull($event->contract_id);
        $this->assertSame('1477-6741-9200-53', $event->reference_number);
    }

    // ---------------------------------------------------------------
    // Unbekannte Ids und manuelle Zuordnung
    // ---------------------------------------------------------------

    public function test_unknown_id_lands_in_the_review_list(): void
    {
        $import = $this->import($this->csv([['id' => '9782530', 'referenz' => '']]));

        $this->assertSame(1, $import->rows_unmatched);
        $settlement = VermittlerSettlement::firstOrFail();
        $this->assertSame('unmatched', $settlement->match_result);
        $this->assertTrue($settlement->requiresDecision(), 'Unbekannte Ids werden nie stillschweigend verworfen.');
    }

    public function test_manual_link_connects_settlement_and_contract(): void
    {
        $contract = $this->contract($this->customer());
        $this->import($this->csv([['id' => '9782530']]));
        $settlement = VermittlerSettlement::firstOrFail();

        app(VermittlerLinkService::class)->linkManually($settlement, $contract);

        $this->assertSame($contract->id, $settlement->refresh()->contract_id);
        $this->assertSame('9782530', $contract->refresh()->vermittler_id);
        $this->assertDatabaseHas('vermittler_match_events', ['contract_id' => $contract->id, 'action' => 'manual_link']);
    }

    /** Eine bestehende, andere Zuordnung wird nie stillschweigend ersetzt. */
    public function test_manual_link_refuses_to_overwrite_a_different_id(): void
    {
        $contract = $this->contract($this->customer(), ['vermittler_id' => '1111111']);
        $this->import($this->csv([['id' => '9782530']]));

        $this->expectException(\RuntimeException::class);
        app(VermittlerLinkService::class)->linkManually(VermittlerSettlement::firstOrFail(), $contract);
    }

    /** Zuerst die Abrechnung, spaeter der Vertrag: die Zeile findet ihn nachtraeglich. */
    public function test_entering_the_id_later_connects_the_waiting_settlement(): void
    {
        $this->import($this->csv([['id' => '9782530', 'provision' => '50']]));
        $contract = $this->contract($this->customer());

        $contract->update(['vermittler_id' => '9782530']);
        app(VermittlerLinkService::class)->recordContractEdit(
            $contract,
            ['reference_number' => null, 'vermittler_id' => null],
        );

        $this->assertSame($contract->id, VermittlerSettlement::firstOrFail()->contract_id);
    }

    // ---------------------------------------------------------------
    // Format-Toleranz und Auswertung
    // ---------------------------------------------------------------

    public function test_file_without_id_column_is_rejected_with_a_clear_message(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'vm').'.csv';
        file_put_contents($path, "\"Datum\";\"Produkt\";\"Provision\"\n\"2026-08-18\";\"Kfz\";\"75\"\n");

        $this->expectExceptionMessageMatches('/Id/');
        $this->import($path);
    }

    public function test_german_amounts_and_dates_are_read_correctly(): void
    {
        $this->contract($this->customer(), ['reference_number' => '1477-0000-0000-01']);

        $this->import($this->csv([[
            'id' => '9001', 'referenz' => '1477-0000-0000-01',
            'provision' => '16,5', 'datum' => '18.08.2026',
        ]]));

        $settlement = VermittlerSettlement::firstOrFail();
        $this->assertSame(16.5, (float) $settlement->provision);
        $this->assertSame('2026-08-18', $settlement->statement_date->toDateString());
    }

    /**
     * Eine echte Exportdatei des Vermittlers in Originalform: 1688 Zeilen,
     * Latin-1 statt UTF-8, gemischte Status-Codes, deutsche Betraege
     * ("16,5"), Umlaute in den Stornogruenden und 118 Zeilen OHNE
     * Referenz-Nr. Die Kennungen (Id und Referenz-Nr.) sind fuer die
     * Ablage im Repository durch Zufallswerte gleicher Form ersetzt -
     * geprueft wird das FORMAT, und dem passen wir uns an, nicht umgekehrt.
     */
    public function test_real_export_file_is_read_completely(): void
    {
        $path = base_path('tests/Fixtures/vermittler_export.csv');
        if (! is_file($path)) {
            $this->markTestSkipped('Beispieldatei nicht vorhanden.');
        }

        $import = $this->import($path, reconcile: false);

        $this->assertSame(1688, $import->rows_total);
        $this->assertSame(0, $import->rows_invalid);
        $this->assertSame(1688, VermittlerSettlement::count());
        $this->assertSame(250, $import->rows_storno, 'Alle Storno-Datensaetze werden als solche erkannt.');
        // Umlaute ueberleben das Einlesen (Latin-1 wird umgewandelt).
        $this->assertTrue(VermittlerSettlement::where('storno_reason', 'Erstprämie nicht gezahlt')->exists());
        // Deutsche Dezimalzahl "16,5" wird nicht zu 165.
        $this->assertTrue(VermittlerSettlement::where('provision', 16.50)->exists());

        // Ein zweiter Lauf legt nichts doppelt an.
        $second = $this->import($path, reconcile: false);
        $this->assertSame(1688, VermittlerSettlement::count());
        $this->assertSame(1688, $second->rows_unchanged);
    }

    public function test_report_counts_storno_separately_from_provision(): void
    {
        $customer = $this->customer();
        $this->contract($customer, ['reference_number' => '1477-0000-0000-01']);
        $this->contract($customer, ['reference_number' => '1477-0000-0000-02', 'type' => 'hausrat']);

        $this->import($this->csv([
            ['id' => '9001', 'referenz' => '1477-0000-0000-01', 'status' => '1', 'provision' => '75'],
            ['id' => '9002', 'referenz' => '1477-0000-0000-02', 'status' => '2', 'provision' => '75', 'storno' => 'Widerruf'],
        ]));

        $report = app(VermittlerReportService::class);
        $products = collect($report->byProduct())->keyBy('produkt');
        $this->assertSame(75.0, $products['Kfz-Versicherung Abschluss']['provision']);
        $this->assertSame(1, $products['Kfz-Versicherung Abschluss']['storniert']);
        $this->assertSame(75.0, $products['Kfz-Versicherung Abschluss']['provision_storno']);

        $performance = $report->performance();
        $this->assertSame(2, $performance['eingereicht']);
        $this->assertSame(1, $performance['abgerechnet']);
        $this->assertSame(1, $performance['storniert']);
        $this->assertSame(50.0, $performance['quote']);
    }

    // ---------------------------------------------------------------
    // Oberflaeche: Zugriff, Import, Suche
    // ---------------------------------------------------------------

    public function test_only_admin_and_manager_reach_the_settlement_pages(): void
    {
        // Mitarbeiter werden wie ueberall im Provisions-Management auf das
        // Dashboard zurueckgeleitet - sie sehen keine Provisionsbetraege.
        $employee = User::factory()->create(['role' => 'employee']);
        $this->actingAs($employee)->get('/admin/vermittler-abrechnung')
            ->assertRedirect(route('admin.dashboard'));

        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager)->get('/admin/vermittler-abrechnung')->assertOk();
    }

    public function test_upload_shows_the_import_result(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->contract($this->customer(), ['reference_number' => '1477-6741-9200-53']);
        $path = $this->csv([['id' => '9753224', 'referenz' => '1477-6741-9200-53']]);

        $response = $this->actingAs($admin)->post('/admin/vermittler-abrechnung/import', [
            'csv_file' => new UploadedFile($path, 'abrechnung.csv', 'text/csv', null, true),
        ]);

        $response->assertRedirect();
        $this->actingAs($admin)->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('Import abgeschlossen')
            ->assertSee('9753224');
    }

    /** Pruefliste und Auswertung muessen auch mit Daten fehlerfrei rendern. */
    public function test_review_and_report_pages_render(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $contract = $this->contract($this->customer('Renderbare Person'), ['reference_number' => '1477-0000-0000-01']);
        $this->import($this->csv([
            ['id' => '9001', 'referenz' => '1477-0000-0000-01', 'status' => '1'],
            ['id' => '9002', 'referenz' => '', 'status' => '1'],
        ]));

        $this->actingAs($admin)->get('/admin/vermittler-abrechnung/pruefung')
            ->assertOk()->assertSee('9002');
        $this->actingAs($admin)->get('/admin/vermittler-abrechnung/bericht')
            ->assertOk()->assertSee('Bestätigungsquote');
        // Die Box in der Vertragsakte zeigt beide Kennungen.
        $this->actingAs($admin)->get('/admin/contracts/'.$contract->id.'/edit')
            ->assertOk()->assertSee('Vermittler / Abrechnung')->assertSee('9001');
    }

    public function test_search_finds_a_contract_by_reference_and_by_settlement_id(): void
    {
        $contract = $this->contract($this->customer('Suchbare Person'), [
            'reference_number' => '1477-6741-9200-53',
            'vermittler_id' => '9753224',
        ]);

        $this->assertTrue(Contract::search('1477-6741-9200-53')->pluck('id')->contains($contract->id));
        $this->assertTrue(Contract::search('9753224')->pluck('id')->contains($contract->id));
    }

    public function test_contract_form_stores_both_identifiers_and_writes_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $contract = $this->contract($this->customer());

        $this->actingAs($admin)->put('/admin/contracts/'.$contract->id, [
            'type' => 'kfz',
            'insurer' => 'AdmiralDirekt',
            'status' => 'active',
            'reference_number' => '1477-6741-9200-53',
            'vermittler_id' => '9753224',
        ])->assertRedirect();

        $contract->refresh();
        $this->assertSame('1477-6741-9200-53', $contract->reference_number);
        $this->assertSame('9753224', $contract->vermittler_id);
        $this->assertDatabaseHas('vermittler_match_events', ['contract_id' => $contract->id, 'action' => 'reference_stored']);
        $this->assertDatabaseHas('vermittler_match_events', ['contract_id' => $contract->id, 'action' => 'id_linked']);
    }

    /** Zwei Vertraege mit derselben Vermittler-ID waeren ein Zuordnungsfehler. */
    public function test_vermittler_id_must_be_unique_across_contracts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->customer();
        $this->contract($customer, ['vermittler_id' => '9753224']);
        $second = $this->contract($customer, ['type' => 'hausrat']);

        $this->actingAs($admin)->put('/admin/contracts/'.$second->id, [
            'type' => 'hausrat',
            'insurer' => 'AdmiralDirekt',
            'status' => 'active',
            'vermittler_id' => '9753224',
        ])->assertSessionHasErrors('vermittler_id');
    }
}
