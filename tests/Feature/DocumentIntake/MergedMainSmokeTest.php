<?php

namespace Tests\Feature\DocumentIntake;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\Ocr\TextExtractorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Rauchtest der GESAMTEN Kette auf dem gemergten main (Betreiber-Auftrag
 * "Verifikation nach dem Merge"): Upload -> Analyse -> Vorschlag -> Zuordnung
 * + Vertragsanlage, ueber die echten HTTP-Endpunkte. Deckt die Interaktion
 * der zuletzt geaenderten Bausteine ab (Sicherheitsnetz, versicherungspolice,
 * Adress-Matching, Portal-Upload-Analyse).
 */
class MergedMainSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function fakeOcr(string $text): void
    {
        config(['services.ocr.enabled' => true]);
        $this->app->bind(TextExtractorInterface::class, fn () => new class($text) implements TextExtractorInterface {
            public function __construct(private string $text) {}
            public function isAvailable(): bool { return true; }
            public function extract(string $binary, string $mime): string { return $this->text; }
        });
    }

    /** Ganze Kette: Kfz-Vertrag per KI erkannt -> zuordnen + Vertrag anlegen. */
    public function test_full_flow_upload_analyze_assign_create_contract(): void
    {
        Storage::fake('local');
        config(['services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'type' => 'kfz_vertrag', 'confidence' => 95, 'summary' => 'Kfz-Vertrag Allianz', 'title' => 'Kfz Allianz',
                'data' => [
                    'person' => ['first_name' => 'Erik', 'last_name' => 'Muster', 'birth_date' => '1990-01-02'],
                    'versicherung' => ['insurer' => 'Allianz', 'contract_number' => 'AZ-9', 'sparte' => 'kfz', 'premium_amount' => 90, 'premium_interval' => 'monthly'],
                    'kfz' => ['license_plate' => 'B-AB 12', 'vin' => 'WAUZZZ8V5KA000111', 'manufacturer' => 'Audi'],
                ],
            ])]],
        ])]);
        $admin = $this->admin();

        // 1) Upload in den Eingang (kein Kunde).
        $upload = $this->actingAs($admin)->postJson(route('admin.documents.smart_upload'), [
            'files' => [UploadedFile::fake()->createWithContent('vertrag.pdf', '%PDF-1.4 '.str_repeat('x',200))],
        ])->assertOk();
        $doc = Document::findOrFail($upload->json('ids.0'));

        // 2) Analyse lief (Sync-Queue) -> KI-Ergebnis mit Vertragskern.
        $this->assertSame('done', $doc->ai_status);
        $this->assertSame('kfz_vertrag', $doc->ai_type);
        $this->assertSame('Allianz', $doc->ai_extracted['versicherung']['insurer']);

        // 3) Kunde anlegen + Vertrag aus dem Dokument.
        $customer = Customer::create([
            'user_id' => User::factory()->create(['role' => 'customer', 'name' => 'Erik Muster'])->id,
            'customer_number' => 'C-ERIK', 'birth_date' => '1990-01-02',
        ]);
        $this->actingAs($admin)->postJson(route('admin.documents.assign', $doc->id), [
            'customer_id' => (string) $customer->id,
            'create_contract' => 1,
        ])->assertOk();

        // 4) Vertrag entstand samt Fahrzeug, Dokument ist zugeordnet.
        $contract = Contract::where('customer_id', $customer->id)->first();
        $this->assertNotNull($contract);
        $this->assertSame('kfz', $contract->type);
        $this->assertSame('Allianz', $contract->insurer);
        $this->assertSame((string) $customer->id, (string) $doc->fresh()->customer_id);
        $this->assertSame('WAUZZZ8V5KA000111', $contract->vehicleDetail->vin);
    }

    /** Police-Foto (nur E-Mail per OCR) muss zur KI eskalieren, nicht schwach akzeptiert werden. */
    public function test_policy_photo_without_insurer_escalates_via_http(): void
    {
        Storage::fake('local');
        config(['services.anthropic.key' => 'test-key']);
        $this->fakeOcr("VERSICHERUNGSSCHEIN Kfz\nService: hilfe@versicherer.de");
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'type' => 'versicherungspolice', 'confidence' => 92, 'summary' => 'Police', 'title' => 'Police',
                'data' => ['versicherung' => ['insurer' => 'HUK', 'contract_number' => 'H-1', 'sparte' => 'kfz']],
            ])]],
        ])]);

        $upload = $this->actingAs($this->admin())->postJson(route('admin.documents.smart_upload'), [
            'files' => [UploadedFile::fake()->image('police.jpg', 800, 600)],
        ])->assertOk();
        $doc = Document::findOrFail($upload->json('ids.0'));

        $this->assertSame('ai', $doc->ai_source, 'Police ohne Vertragskern muss zur KI eskalieren');
        $this->assertSame('HUK', $doc->ai_extracted['versicherung']['insurer']);
    }
}
