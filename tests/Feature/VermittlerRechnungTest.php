<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use App\Models\VermittlerInvoice;
use App\Models\VermittlerMatchEvent;
use App\Models\VermittlerSettlement;
use App\Services\Ocr\PdfTextLayerExtractor;
use App\Services\Ocr\TextExtractorInterface;
use App\Services\Vermittler\VermittlerAbrechnungImporter;
use App\Services\Vermittler\VermittlerReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Betreiber-Auftrag 23.09.2026 (TARIFCHECK24): Vertrag mit Referenz-Nr. ->
 * monatliche CSV mit Id und Status -> Rechnung als PDF/Bild. Erst die
 * Rechnung BELEGT die Zahlung.
 *
 * Gemeldet: (1) Rechnungen liessen sich weder als PDF noch als Bild
 * hochladen - es gab gar keinen Upload, nur ein Suchfeld. (2) Die
 * Vertragsakte zeigte "✓ In Abrechnung gefunden" + "Provision 75,00 €",
 * obwohl die Position OFFEN war: Code 1 wurde als "bestaetigt" gedeutet,
 * Code 3 war unbekannt. Richtig ist 1 offen, 2 storniert, 3 verifiziert,
 * 4 bezahlt.
 */
class VermittlerRechnungTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function contract(string $reference, string $name = 'Kunde Rechnung'): Contract
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);
        $customer = Customer::create(['user_id' => $user->id, 'customer_number' => 'C-'.substr(md5($name.$reference), 0, 8)]);
        $contract = Contract::create([
            'customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'ADAC',
            'status' => 'active', 'reference_number' => $reference,
        ]);
        $contract->forceFill(['created_at' => now()->subYear()])->saveQuietly();

        return $contract->refresh();
    }

    /** CSV im Format des Vermittlers einlesen. */
    private function csv(array $rows): void
    {
        $lines = ['"Datum";"Produkt";"Id";"Status";"Provision";"Tracking-Id";"Stornogrund";"Referenz-Nr."'];
        foreach ($rows as $r) {
            $lines[] = implode(';', array_map(fn ($v) => '"'.$v.'"', [
                '2026-08-18 00:00:00', 'Kfz-Versicherung Abschluss', $r['id'], $r['status'],
                $r['provision'] ?? '75', '', $r['storno'] ?? '', $r['ref'],
            ]));
        }
        $path = tempnam(sys_get_temp_dir(), 'tc').'.csv';
        file_put_contents($path, implode("\n", $lines));
        app(VermittlerAbrechnungImporter::class)->import($path, 'abrechnung.csv', null, false);
    }

    /** Text einer Gutschrift, wie ihn die Textebene eines PDF liefert. */
    private function invoiceText(array $positions, ?string $total = null): string
    {
        $text = "TARIFCHECK24 GmbH\nGutschrift\nGutschriftsnummer: GS-2026-0815\nDatum: 15.09.2026\n\n"
            ."Datum       Produkt                       Id        Referenz-Nr.          Betrag\n";
        foreach ($positions as [$id, $ref, $betrag]) {
            $text .= "18.08.2026  Kfz-Versicherung Abschluss   {$id}   {$ref}   {$betrag} €\n";
        }
        if ($total !== null) {
            $text .= "\nGesamtbetrag: {$total} €\n";
        }

        return $text;
    }

    private function fakePdfText(string $text): void
    {
        $this->mock(PdfTextLayerExtractor::class, function ($m) use ($text) {
            $m->shouldReceive('isAvailable')->andReturn(true);
            $m->shouldReceive('extract')->andReturn($text);
        });
    }

    private function upload(User $user, UploadedFile $file)
    {
        return $this->actingAs($user)->post(route('admin.vermittler.invoice_upload'), ['rechnung_datei' => $file]);
    }

    // ------------------------------------------------ Status-Codes

    public function test_code_1_ist_offen_und_nicht_gruen_bestaetigt(): void
    {
        $contract = $this->contract('1417-2871-8270-55');
        $this->csv([['id' => '9786068', 'status' => '1', 'ref' => '1417-2871-8270-55']]);

        $contract->refresh();
        $this->assertSame(Contract::VERMITTLER_IN_ABRECHNUNG, $contract->vermittlerStatus());
        $this->assertSame('Offen – noch nicht bestätigt', $contract->vermittlerStatusLabel());
        $this->assertNotSame('active', $contract->vermittlerStatusBadge(), 'Offen darf nicht gruen erscheinen.');

        $this->actingAs($this->admin())->get(route('admin.contract.edit', $contract->id))
            ->assertOk()
            ->assertDontSee('In Abrechnung gefunden')
            ->assertSee('Provision (erwartet laut CSV)')
            ->assertSee('Noch nicht bezahlt');
    }

    public function test_code_3_ist_verifiziert_statt_pruefung(): void
    {
        $contract = $this->contract('1417-2871-8270-56');
        $this->csv([['id' => '9786069', 'status' => '3', 'ref' => '1417-2871-8270-56']]);

        $this->assertSame(Contract::VERMITTLER_VERIFIZIERT, $contract->refresh()->vermittlerStatus());
        $this->assertSame(0, VermittlerSettlement::needsReview()->count());
    }

    /** Betreiber-Entscheidung 23.09.2026: Code 4 der CSV GENUEGT - keine Rechnung noetig. */
    public function test_code_4_ist_bezahlt_ohne_rechnung(): void
    {
        $contract = $this->contract('1417-2871-8270-57');
        $this->csv([['id' => '9786070', 'status' => '4', 'ref' => '1417-2871-8270-57']]);

        $contract->refresh();
        $this->assertSame(Contract::VERMITTLER_ABGERECHNET, $contract->vermittlerStatus());
        $this->assertSame('Bezahlt', $contract->vermittlerStatusLabel());
        $this->assertSame('active', $contract->vermittlerStatusBadge());

        $this->actingAs($this->admin())->get(route('admin.contract.edit', $contract->id))
            ->assertOk()->assertSee('✅ Bezahlt')->assertSee('laut TARIFCHECK24-CSV')
            ->assertDontSee('Noch nicht bezahlt');
    }

    /** Der Monatsrhythmus: dieselbe Gesamt-Datei mit geaenderten Status. */
    public function test_monatliche_gesamtdatei_zieht_status_nach(): void
    {
        $offen = $this->contract('1417-2871-8270-60', 'Kunde Offen');
        $storno = $this->contract('1417-2871-8270-61', 'Kunde Storno');
        $this->csv([
            ['id' => '9790001', 'status' => '1', 'ref' => '1417-2871-8270-60'],
            ['id' => '9790002', 'status' => '1', 'ref' => '1417-2871-8270-61'],
        ]);

        // Folgemonat: alte Zeilen mit neuem Status + ein neuer Vorgang.
        $neu = $this->contract('1417-2871-8270-62', 'Kunde Neu');
        $this->csv([
            ['id' => '9790001', 'status' => '4', 'ref' => '1417-2871-8270-60'],
            ['id' => '9790002', 'status' => '2', 'ref' => '1417-2871-8270-61', 'storno' => 'Kein Vertrag zustande gekommen'],
            ['id' => '9790003', 'status' => '1', 'ref' => '1417-2871-8270-62'],
        ]);

        $this->assertSame(Contract::VERMITTLER_ABGERECHNET, $offen->refresh()->vermittlerStatus());
        $this->assertSame(Contract::VERMITTLER_STORNIERT, $storno->refresh()->vermittlerStatus());
        $this->assertSame(Contract::VERMITTLER_IN_ABRECHNUNG, $neu->refresh()->vermittlerStatus());
        $this->assertSame(3, VermittlerSettlement::count(), 'Keine Zeile doppelt.');
    }

    // ------------------------------------------------ Upload in allen Formen

    public function test_rechnung_als_pdf_hochladen_und_bestaetigen(): void
    {
        $contract = $this->contract('1417-2871-8270-55');
        $this->csv([['id' => '9786068', 'status' => '4', 'ref' => '1417-2871-8270-55']]);
        $this->fakePdfText($this->invoiceText([['9786068', '1417-2871-8270-55', '75,00']], '75,00'));

        $admin = $this->admin();
        $response = $this->upload($admin, UploadedFile::fake()->createWithContent('gutschrift.pdf', '%PDF-1.4 fake'));
        $invoice = VermittlerInvoice::firstOrFail();
        $response->assertRedirect(route('admin.vermittler.invoice', $invoice->id));

        // Entwurf: gelesen, aber noch NICHTS geschrieben.
        $this->assertSame('GS-2026-0815', $invoice->invoice_number);
        $this->assertSame(1, $invoice->rows_confirmed);
        $this->assertSame(Contract::VERMITTLER_ABGERECHNET, $contract->refresh()->vermittlerStatus());
        Storage::disk('local')->assertExists($invoice->file_path);

        $this->actingAs($admin)->get(route('admin.vermittler.invoice', $invoice->id))
            ->assertOk()->assertSee('9786068')->assertSee('Betrag stimmt');

        $this->actingAs($admin)->post(route('admin.vermittler.invoice_confirm', $invoice->id))->assertRedirect();

        $this->assertSame(Contract::VERMITTLER_BEZAHLT_BELEGT, $contract->refresh()->vermittlerStatus());
        $settlement = VermittlerSettlement::where('vermittler_id', '9786068')->first();
        $this->assertNotNull($settlement->payment_confirmed_at);
        $this->assertSame($invoice->id, $settlement->invoice_id);
        $this->assertDatabaseHas('vermittler_match_events', ['contract_id' => $contract->id, 'action' => 'invoice_confirmed']);

        $this->actingAs($admin)->get(route('admin.contract.edit', $contract->id))
            ->assertOk()->assertSee('75,00 € belegt');
    }

    public function test_rechnung_als_foto_wird_ueber_die_texterkennung_gelesen(): void
    {
        $this->contract('1417-2871-8270-55');
        $this->csv([['id' => '9786068', 'status' => '4', 'ref' => '1417-2871-8270-55']]);
        $text = $this->invoiceText([['9786068', '1417-2871-8270-55', '75,00']]);
        $this->app->instance(TextExtractorInterface::class, new class($text) implements TextExtractorInterface {
            public function __construct(private string $text) {}
            public function isAvailable(): bool { return true; }
            public function extract(string $binary, string $mime): string { return $this->text; }
        });

        $this->upload($this->admin(), UploadedFile::fake()->image('gutschrift.jpg'))->assertRedirect();

        $this->assertSame(1, VermittlerInvoice::firstOrFail()->rows_confirmed);
    }

    // ------------------------------------------------ Abgleich: nie raten

    public function test_abweichender_betrag_geht_in_die_pruefung_und_gilt_nicht_als_bezahlt(): void
    {
        $contract = $this->contract('1417-2871-8270-55');
        $this->csv([['id' => '9786068', 'status' => '4', 'ref' => '1417-2871-8270-55']]);
        $this->fakePdfText($this->invoiceText([['9786068', '1417-2871-8270-55', '50,00']]));

        $admin = $this->admin();
        $this->upload($admin, UploadedFile::fake()->createWithContent('gutschrift.pdf', '%PDF'));
        $invoice = VermittlerInvoice::firstOrFail();
        $this->assertSame(1, $invoice->rows_deviation);
        $this->actingAs($admin)->post(route('admin.vermittler.invoice_confirm', $invoice->id));

        $this->assertSame(Contract::VERMITTLER_PRUEFUNG, $contract->refresh()->vermittlerStatus());
        $settlement = VermittlerSettlement::first();
        $this->assertNull($settlement->payment_confirmed_at);
        $this->assertSame('review', $settlement->match_result);
        $this->assertEquals(50.0, (float) $settlement->invoice_amount);
    }

    public function test_nur_bekannte_kennungen_zaehlen_der_rest_wird_ausgewiesen(): void
    {
        $this->contract('1417-2871-8270-55');
        $this->csv([['id' => '9786068', 'status' => '4', 'ref' => '1417-2871-8270-55']]);
        // 1234567 kennt niemand - er darf weder zugeordnet noch geraten werden.
        $this->fakePdfText($this->invoiceText([
            ['9786068', '1417-2871-8270-55', '75,00'],
            ['1234567', '9999-9999-9999-99', '40,00'],
        ], '115,00'));

        $this->upload($this->admin(), UploadedFile::fake()->createWithContent('gutschrift.pdf', '%PDF'));
        $invoice = VermittlerInvoice::firstOrFail();

        $this->assertSame(1, $invoice->rows_found);
        $this->assertEquals(40.0, $invoice->result['rest']);
        $this->assertSame(1, VermittlerSettlement::count(), 'Aus einer Rechnung entsteht kein neuer Datensatz.');
    }

    public function test_storno_in_der_rechnung_ist_ein_widerspruch(): void
    {
        $contract = $this->contract('1417-2871-8270-55');
        $this->csv([['id' => '9786068', 'status' => '2', 'ref' => '1417-2871-8270-55', 'storno' => 'Widerruf']]);
        $this->fakePdfText($this->invoiceText([['9786068', '1417-2871-8270-55', '75,00']]));

        $this->upload($this->admin(), UploadedFile::fake()->createWithContent('gutschrift.pdf', '%PDF'));

        $position = VermittlerInvoice::firstOrFail()->result['positions'][0];
        $this->assertSame('storniert', $position['outcome']);
        $this->assertSame(Contract::VERMITTLER_STORNIERT, $contract->refresh()->vermittlerStatus());
    }

    public function test_dieselbe_rechnung_zweimal_bucht_nichts_doppelt(): void
    {
        $this->contract('1417-2871-8270-55');
        $this->csv([['id' => '9786068', 'status' => '4', 'ref' => '1417-2871-8270-55']]);
        $this->fakePdfText($this->invoiceText([['9786068', '1417-2871-8270-55', '75,00']]));
        $admin = $this->admin();

        $this->upload($admin, UploadedFile::fake()->createWithContent('gutschrift.pdf', '%PDF same'));
        $first = VermittlerInvoice::firstOrFail();
        $this->actingAs($admin)->post(route('admin.vermittler.invoice_confirm', $first->id));
        $this->actingAs($admin)->post(route('admin.vermittler.invoice_confirm', $first->id));

        $this->upload($admin, UploadedFile::fake()->createWithContent('gutschrift.pdf', '%PDF same'))
            ->assertSessionHas('error');

        $this->assertSame(1, VermittlerInvoice::count());
        $this->assertSame(1, VermittlerMatchEvent::where('action', 'invoice_confirmed')->count());
    }

    public function test_spaetere_csv_nimmt_die_belegte_zahlung_nicht_zurueck_ein_storno_schon(): void
    {
        $contract = $this->contract('1417-2871-8270-55');
        $this->csv([['id' => '9786068', 'status' => '4', 'ref' => '1417-2871-8270-55']]);
        $this->fakePdfText($this->invoiceText([['9786068', '1417-2871-8270-55', '75,00']]));
        $admin = $this->admin();
        $this->upload($admin, UploadedFile::fake()->createWithContent('gutschrift.pdf', '%PDF'));
        $this->actingAs($admin)->post(route('admin.vermittler.invoice_confirm', VermittlerInvoice::firstOrFail()->id));

        // Aeltere Monatsdatei meldet noch "offen".
        $this->csv([['id' => '9786068', 'status' => '1', 'ref' => '1417-2871-8270-55']]);
        $this->assertSame(Contract::VERMITTLER_BEZAHLT_BELEGT, $contract->refresh()->vermittlerStatus());

        // Ein Storno ist neue Information und gewinnt.
        $this->csv([['id' => '9786068', 'status' => '2', 'ref' => '1417-2871-8270-55', 'storno' => 'Widerruf']]);
        $this->assertSame(Contract::VERMITTLER_STORNIERT, $contract->refresh()->vermittlerStatus());
    }

    // ------------------------------------------------ Auswertung

    public function test_auswertung_zaehlt_offen_nicht_als_bestaetigt(): void
    {
        $this->contract('1417-2871-8270-55', 'Kunde A');
        $this->contract('1417-2871-8270-56', 'Kunde B');
        $this->csv([
            ['id' => '9786068', 'status' => '1', 'ref' => '1417-2871-8270-55'],
            ['id' => '9786069', 'status' => '3', 'ref' => '1417-2871-8270-56'],
        ]);

        $report = app(VermittlerReportService::class);
        $performance = $report->performance();
        $this->assertSame(1, $performance['abgerechnet'], 'Nur Code 3/4 zaehlen als bestaetigt.');
        $this->assertSame(1, $performance['offen']);

        $product = $report->byProduct()[0];
        $this->assertSame(1, $product['offen']);
        $this->assertSame(1, $product['bestaetigt']);
        $this->assertEquals(0.0, $product['provision_belegt']);
    }

    // ------------------------------------------------ Zugriff

    public function test_ohne_provisionsrecht_kein_upload_und_keine_rechnung(): void
    {
        $this->contract('1417-2871-8270-55');
        $this->csv([['id' => '9786068', 'status' => '4', 'ref' => '1417-2871-8270-55']]);
        $this->fakePdfText($this->invoiceText([['9786068', '1417-2871-8270-55', '75,00']]));
        $this->upload($this->admin(), UploadedFile::fake()->createWithContent('gutschrift.pdf', '%PDF'));
        $invoice = VermittlerInvoice::firstOrFail();

        foreach (['employee', 'support', 'manager'] as $rolle) {
            $user = User::factory()->create(['role' => $rolle, 'can_manage_commissions' => false]);
            $this->upload($user, UploadedFile::fake()->createWithContent('x.pdf', '%PDF other'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.vermittler.invoice', $invoice->id))->assertForbidden();
            $this->actingAs($user)->get(route('admin.vermittler.invoice_file', $invoice->id))->assertForbidden();
            $this->actingAs($user)->post(route('admin.vermittler.invoice_confirm', $invoice->id))->assertForbidden();
        }
        $this->assertTrue(VermittlerInvoice::firstOrFail()->isDraft());
    }
}
