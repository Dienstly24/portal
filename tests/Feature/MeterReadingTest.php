<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractEnergyDetail;
use App\Models\Customer;
use App\Models\Document;
use App\Models\MeterReading;
use App\Models\User;
use App\Services\Ai\HeuristicDocumentClassifier;
use App\Services\DocumentIntake\DocumentIntakeService;
use App\Services\Energy\MeterPhotoReader;
use App\Services\Energy\MeterReadingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Zaehlerstands-Historie (Betreiber-Vorgabe 29.07.2026): Foto des Zaehlers
 * hochladen -> Zaehlernummer erkennen -> Kunde/Vertrag finden -> Stand mit
 * dem Zeitpunkt des Uploads erfassen -> Verbrauch zwischen zwei Staenden.
 */
class MeterReadingTest extends TestCase
{
    use RefreshDatabase;

    /** Text, wie ihn OCR von einem echten Zaehlerdisplay liefert. */
    private const FOTO_TEXT = "180 004680 kWh\n"
        ."Identifikationsnummer\n"
        ."1 LOG00 9228 3078\n"
        ."Schltg. 4000k\n"
        ."Nr. 92283078   Baujahr 2024\n"
        ."Zweirichtungszähler\n"
        .'R,=10.000 Imp/kWh';

    private function customer(string $name = 'Zaehler Kunde'): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);
        return Customer::create(['user_id' => $user->id, 'customer_number' => 'C-'.strtoupper(Str::random(6))]);
    }

    private function energyContract(Customer $customer, string $meterNumber, array $overrides = []): Contract
    {
        $contract = Contract::create(array_merge([
            'customer_id' => $customer->id,
            'type' => 'strom',
            'insurer' => 'LichtBlick',
            'status' => 'active',
            'start_date' => now()->subYear()->toDateString(),
        ], $overrides));

        ContractEnergyDetail::create([
            'contract_id' => $contract->id,
            'meter_number' => $meterNumber,
            'consumption_kwh' => 3650,
            'working_price' => 30.0,
        ]);

        return $contract->fresh('energyDetail');
    }

    // ---- Erkennung (kostenlos, ohne KI) ---------------------------------

    public function test_zaehlerfoto_wird_ohne_ki_erkannt(): void
    {
        $result = (new HeuristicDocumentClassifier)->classify(self::FOTO_TEXT);

        $this->assertSame('zaehlerfoto', $result['type']);
        $this->assertSame('1LOG0092283078', $result['data']['energie']['meter_number']);
        $this->assertSame(4680.0, (float) $result['data']['energie']['meter_reading']);
        $this->assertSame('1.8.0', $result['data']['energie']['meter_register']);
    }

    public function test_zaehlerkonstante_gilt_nicht_als_zaehlerstand(): void
    {
        // "R=10.000 Imp/kWh" steht auf jedem Typenschild - das ist die
        // Impulskonstante, kein Zaehlerstand.
        $found = (new MeterPhotoReader)->read("R=10.000 Imp/kWh\nBaujahr 2024");

        $this->assertNull($found['meter_reading']);
        $this->assertFalse((new MeterPhotoReader)->looksLikeMeterPhoto("R=10.000 Imp/kWh\nBaujahr 2024"));
    }

    public function test_lange_rechnung_ist_kein_zaehlerfoto(): void
    {
        // Eine Energierechnung nennt ebenfalls Zaehlernummer und Stand -
        // sie darf aber nie als Zaehlerfoto durchgehen.
        $text = "RECHNUNG\nZählernummer 1LOG0092283078\nZählerstand 4680 kWh\n"
            .str_repeat("Position Betrag 12,50 EUR\n", 90);

        $this->assertSame('rechnung', (new HeuristicDocumentClassifier)->classify($text)['type']);
    }

    // ---- Zuordnung ueber die Zaehlernummer ------------------------------

    public function test_zaehlernummer_findet_kunde_und_vertrag(): void
    {
        $customer = $this->customer();
        $contract = $this->energyContract($customer, '1LOG0092283078');

        // Schreibweise mit Leerzeichen, wie sie auf dem Zaehler steht.
        $located = app(MeterReadingService::class)->locate('1 LOG00 9228 3078');

        $this->assertNotNull($located);
        $this->assertSame((string) $contract->id, (string) $located['contract']->id);
        $this->assertSame((string) $customer->id, (string) $located['customer']->id);
    }

    public function test_kurze_werksnummer_trifft_lange_identifikationsnummer(): void
    {
        // Im Vertrag steht oft nur die kurze Nummer, auf dem Zaehler die volle.
        $customer = $this->customer();
        $this->energyContract($customer, '92283078');

        $located = app(MeterReadingService::class)->locate('1LOG0092283078');

        $this->assertNotNull($located);
        $this->assertSame((string) $customer->id, (string) $located['customer']->id);
    }

    public function test_zaehlernummer_bei_zwei_kunden_ordnet_nicht_zu(): void
    {
        // Mehrdeutig -> es wird bewusst NICHT geraten.
        $this->energyContract($this->customer('Kunde A'), '1LOG0092283078');
        $this->energyContract($this->customer('Kunde B'), '1LOG0092283078');

        $this->assertNull(app(MeterReadingService::class)->locate('1LOG0092283078'));
    }

    public function test_anbieterwechsel_waehlt_den_laufenden_vertrag(): void
    {
        // Derselbe Zaehler, zwei Vertraege desselben Kunden (Wechsel):
        // der aktuell laufende gewinnt.
        $customer = $this->customer();
        $alt = $this->energyContract($customer, '1LOG0092283078', [
            'insurer' => 'Stadtwerke',
            'status' => 'cancelled',
            'start_date' => now()->subYears(3)->toDateString(),
            'end_date' => now()->subMonths(6)->toDateString(),
        ]);
        $neu = $this->energyContract($customer, '1LOG0092283078', [
            'start_date' => now()->subMonths(6)->toDateString(),
        ]);

        $located = app(MeterReadingService::class)->locate('1LOG0092283078', now()->toDateString());

        $this->assertNotNull($located);
        $this->assertSame((string) $neu->id, (string) $located['contract']->id);
        $this->assertNotSame((string) $alt->id, (string) $located['contract']->id);
    }

    // ---- Historie und Verbrauch -----------------------------------------

    public function test_zwei_staende_ergeben_den_verbrauch(): void
    {
        $customer = $this->customer();
        $detail = $this->energyContract($customer, '1LOG0092283078')->energyDetail;
        $service = app(MeterReadingService::class);

        $service->record($detail, 4000, ['reading_date' => now()->subDays(100)->toDateString()]);
        $service->record($detail, 5000, ['reading_date' => now()->toDateString()]);

        $status = $detail->fresh()->consumptionStatus();
        $this->assertNotNull($status);
        $this->assertSame(1000.0, (float) $status['consumption']);
        $this->assertSame(100, $status['days']);
        $this->assertSame(10.0, (float) $status['per_day']);
        // 1000 kWh in 100 Tagen -> 3650 kWh/Jahr = genau der vereinbarte Verbrauch.
        $this->assertSame(3650, $status['projected']);
        $this->assertFalse($status['exceeded']);

        // Der aktuelle Stand wird im Bestandsfeld mitgefuehrt.
        $this->assertSame(5000.0, (float) $detail->fresh()->meter_reading);
    }

    public function test_erste_ablesung_erzeugt_keinen_verbrauch(): void
    {
        $detail = $this->energyContract($this->customer(), '1LOG0092283078')->energyDetail;
        app(MeterReadingService::class)->record($detail, 4680);

        $history = $detail->fresh()->consumptionHistory();
        $this->assertCount(1, $history);
        $this->assertNull($history[0]['consumption']);
        $this->assertNull($detail->fresh()->consumptionStatus());
    }

    public function test_dieselbe_ablesung_wird_nicht_doppelt_gespeichert(): void
    {
        $detail = $this->energyContract($this->customer(), '1LOG0092283078')->energyDetail;
        $service = app(MeterReadingService::class);

        $service->record($detail, 4680, ['reading_date' => now()->toDateString()]);
        $service->record($detail, 4680, ['reading_date' => now()->toDateString()]);

        $this->assertSame(1, MeterReading::where('contract_energy_detail_id', $detail->id)->count());
    }

    public function test_rueckwaerts_laufender_stand_wird_markiert(): void
    {
        // Ein Zaehler laeuft nicht rueckwaerts: der Wert wird als Tatsache
        // gespeichert, aber vermerkt - und ueberschreibt nicht den Bestand.
        $detail = $this->energyContract($this->customer(), '1LOG0092283078')->energyDetail;
        $service = app(MeterReadingService::class);

        $service->record($detail, 5000, ['reading_date' => now()->subDays(30)->toDateString()]);
        $entry = $service->record($detail, 4000, ['reading_date' => now()->toDateString()]);

        $this->assertNotNull($entry);
        $this->assertStringContainsString('Niedriger als der vorherige Stand', (string) $entry->note);
        $this->assertSame(5000.0, (float) $detail->fresh()->meter_reading);
        $this->assertTrue($detail->fresh()->consumptionHistory()[0]['implausible']);
    }

    public function test_einspeisung_wird_getrennt_gefuehrt(): void
    {
        // Zweirichtungszaehler: die Einspeisung darf den Bezugsverbrauch nie
        // verfaelschen.
        $detail = $this->energyContract($this->customer(), '1LOG0092283078')->energyDetail;
        $service = app(MeterReadingService::class);

        $service->record($detail, 4000, ['reading_date' => now()->subDays(50)->toDateString()]);
        $service->record($detail, 4500, ['reading_date' => now()->toDateString()]);
        $service->record($detail, 900, ['register' => '2.8.0', 'reading_date' => now()->subDays(50)->toDateString()]);
        $service->record($detail, 1200, ['register' => '2.8.0', 'reading_date' => now()->toDateString()]);

        $fresh = $detail->fresh();
        $this->assertSame(500.0, (float) $fresh->consumptionStatus()['consumption']);
        $this->assertSame(300.0, (float) $fresh->consumptionStatus('2.8.0')['consumption']);
        // Das Bestandsfeld fuehrt nur den Bezug.
        $this->assertSame(4500.0, (float) $fresh->meter_reading);
    }

    // ---- Dokumenteneingang: Foto -> Historie -----------------------------

    public function test_zaehlerfoto_ordnet_kunden_zu_und_traegt_stand_ein(): void
    {
        $customer = $this->customer();
        $contract = $this->energyContract($customer, '1LOG0092283078');

        $document = Document::create([
            'customer_id' => null, 'category' => 'other', 'file_name' => 'zaehler.jpg',
            'file_path' => 'documents/eingang/z.jpg', 'disk' => 'local', 'ai_status' => 'done',
            'ai_type' => 'zaehlerfoto',
            'ai_extracted' => ['energie' => [
                'meter_number' => '1LOG0092283078', 'meter_reading' => 4680.0, 'meter_register' => '1.8.0',
            ]],
        ]);

        $intake = app(DocumentIntakeService::class);

        // 1. Der Kunde wird allein ueber die Zaehlernummer gefunden.
        $match = $intake->findMeterMatch($document->ai_extracted);
        $this->assertNotNull($match);
        $this->assertSame((string) $customer->id, $match['customer_id']);
        $this->assertSame('auto', $match['tier']);
        $this->assertSame('meter_number', $match['via']);

        // 2. Beim Zuordnen landet der Stand in der Verbrauchshistorie.
        $intake->linkMatchingContract($document, $customer);

        $reading = MeterReading::where('contract_energy_detail_id', $contract->energyDetail->id)->first();
        $this->assertNotNull($reading);
        $this->assertSame(4680.0, (float) $reading->reading);
        $this->assertSame('document', $reading->source);
        $this->assertSame((string) $document->id, (string) $reading->document_id);
        // Der Zeitpunkt des Uploads ist der Ablesezeitpunkt.
        $this->assertSame($document->created_at->toDateString(), $reading->reading_date->toDateString());
    }

    public function test_zaehlerfoto_ohne_lesbaren_stand_erzeugt_keine_ablesung(): void
    {
        // Nichts erfinden: ohne Stand entsteht keine Ablesung.
        $customer = $this->customer();
        $contract = $this->energyContract($customer, '1LOG0092283078');

        $document = Document::create([
            'customer_id' => $customer->id, 'category' => 'other', 'file_name' => 'unscharf.jpg',
            'file_path' => 'customers/x/documents/u.jpg', 'disk' => 'local', 'ai_status' => 'done',
            'ai_type' => 'zaehlerfoto',
            'ai_extracted' => ['energie' => ['meter_number' => '1LOG0092283078']],
        ]);

        app(DocumentIntakeService::class)->linkMatchingContract($document, $customer);

        $this->assertSame(0, MeterReading::where('contract_energy_detail_id', $contract->energyDetail->id)->count());
    }

    // ---- Kundenportal ----------------------------------------------------

    public function test_kunde_meldet_zaehlerstand_im_portal(): void
    {
        $customer = $this->customer();
        $contract = $this->energyContract($customer, '1LOG0092283078');

        $this->actingAs($customer->user)
            ->post(route('portal.contracts.meter', $contract->id), ['reading' => 4680])
            ->assertRedirect();

        $reading = MeterReading::where('contract_energy_detail_id', $contract->energyDetail->id)->first();
        $this->assertNotNull($reading);
        $this->assertSame(4680.0, (float) $reading->reading);
        $this->assertSame('customer', $reading->source);
    }

    public function test_kunde_kann_keinen_kleineren_stand_melden(): void
    {
        $customer = $this->customer();
        $contract = $this->energyContract($customer, '1LOG0092283078');
        app(MeterReadingService::class)->record($contract->energyDetail, 5000);

        $this->actingAs($customer->user)
            ->post(route('portal.contracts.meter', $contract->id), ['reading' => 4000])
            ->assertSessionHasErrors('reading');

        $this->assertSame(1, MeterReading::where('contract_energy_detail_id', $contract->energyDetail->id)->count());
    }

    public function test_kunde_laedt_nur_ein_zaehlerfoto_hoch(): void
    {
        Storage::fake('local');
        $customer = $this->customer();
        $contract = $this->energyContract($customer, '1LOG0092283078');

        $this->actingAs($customer->user)
            ->post(route('portal.contracts.meter', $contract->id), [
                'photo' => UploadedFile::fake()->image('zaehler.jpg'),
            ])->assertRedirect();

        $document = Document::where('customer_id', $customer->id)->first();
        $this->assertNotNull($document);
        $this->assertSame((string) $contract->id, (string) $document->contract_id);
    }

    public function test_portal_zeigt_offenes_zaehlerfoto_an(): void
    {
        // Ohne laufenden Queue-Worker bleibt die Auswertung liegen. Der Kunde
        // darf dann nicht im Unklaren bleiben - das Foto wird als offen
        // ausgewiesen, statt spurlos zu verschwinden.
        Storage::fake('local');
        $customer = $this->customer();
        $contract = $this->energyContract($customer, '1LOG0092283078');

        $this->actingAs($customer->user)->post(route('portal.contracts.meter', $contract->id), [
            'photo' => UploadedFile::fake()->image('zaehler.jpg'),
        ])->assertRedirect();

        $this->actingAs($customer->user)
            ->get(route('portal.contracts.show', $contract->id))
            ->assertOk()
            ->assertSee('Zählerfoto', false);
    }

    public function test_kunde_sieht_fremden_vertrag_nicht(): void
    {
        $fremd = $this->energyContract($this->customer('Fremd'), '1LOG0092283078');

        $this->actingAs($this->customer('Ich')->user)
            ->post(route('portal.contracts.meter', $fremd->id), ['reading' => 4680])
            ->assertNotFound();
    }

    public function test_portal_zeigt_verbrauch_und_historie(): void
    {
        $customer = $this->customer();
        $contract = $this->energyContract($customer, '1LOG0092283078');
        $service = app(MeterReadingService::class);
        $service->record($contract->energyDetail, 4000, ['reading_date' => now()->subDays(100)->toDateString()]);
        $service->record($contract->energyDetail, 5000, ['reading_date' => now()->toDateString()]);

        $this->actingAs($customer->user)
            ->get(route('portal.contracts.show', $contract->id))
            ->assertOk()
            ->assertSee('Zählerstand', false)
            ->assertSee('5.000 kWh')          // aktueller Stand
            ->assertSee('1.000 kWh')          // Verbrauch seit der letzten Ablesung
            ->assertSee('3.650 kWh');         // Hochrechnung aufs Jahr
    }

    // ---- Beraterwelt -----------------------------------------------------

    public function test_energie_cockpit_zeigt_historie(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $contract = $this->energyContract($this->customer(), '1LOG0092283078');
        $service = app(MeterReadingService::class);
        $service->record($contract->energyDetail, 4000, ['reading_date' => now()->subDays(100)->toDateString()]);
        $service->record($contract->energyDetail, 5000, ['reading_date' => now()->toDateString()]);

        $this->actingAs($admin)
            ->get(route('admin.contract.edit', $contract->id))
            ->assertOk()
            ->assertSee('Verbrauchshistorie')
            ->assertSee('5.000 kWh')
            ->assertSee('Ablesung erfassen');
    }

    public function test_mitarbeiter_erfasst_ablesung(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $contract = $this->energyContract($this->customer(), '1LOG0092283078');

        $this->actingAs($admin)
            ->post(route('admin.contract.meter_reading.store', $contract->id), [
                'reading' => 4680,
                'reading_date' => now()->toDateString(),
            ])->assertRedirect();

        $reading = MeterReading::where('contract_energy_detail_id', $contract->energyDetail->id)->first();
        $this->assertNotNull($reading);
        $this->assertSame('staff', $reading->source);
        $this->assertSame($admin->name, $reading->created_by);
    }

    public function test_mitarbeiter_darf_ablesung_nicht_loeschen(): void
    {
        // Loeschen der Historie bleibt admin/manager vorbehalten.
        $employee = User::factory()->create(['role' => 'employee']);
        $contract = $this->energyContract($this->customer(), '1LOG0092283078');
        $reading = app(MeterReadingService::class)->record($contract->energyDetail, 4680);

        // Die Rollen-Middleware leitet in den erlaubten Bereich um.
        $this->actingAs($employee)
            ->delete(route('admin.contract.meter_reading.destroy', [$contract->id, $reading->id]))
            ->assertRedirect(route('admin.dashboard'));

        $this->assertSame(1, MeterReading::where('contract_energy_detail_id', $contract->energyDetail->id)->count());
    }

    public function test_admin_loescht_fehlerhafte_ablesung(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $contract = $this->energyContract($this->customer(), '1LOG0092283078');
        $service = app(MeterReadingService::class);
        $service->record($contract->energyDetail, 4000, ['reading_date' => now()->subDays(10)->toDateString()]);
        $falsch = $service->record($contract->energyDetail, 999999, ['reading_date' => now()->toDateString()]);

        $this->actingAs($admin)
            ->delete(route('admin.contract.meter_reading.destroy', [$contract->id, $falsch->id]))
            ->assertRedirect();

        // Der Bestandswert faellt auf die dann juengste Ablesung zurueck.
        $this->assertSame(4000.0, (float) $contract->energyDetail->fresh()->meter_reading);
    }
}
