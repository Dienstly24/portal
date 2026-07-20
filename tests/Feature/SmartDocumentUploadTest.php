<?php

namespace Tests\Feature;

use App\Models\AiDecision;
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
 * Smart Document Upload: Mehrseiten-Scan (Portal), Dokumenten-Eingang
 * (CRM), KI-Analyse (Claude via Http::fake), Matching und Freigabe.
 */
class SmartDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(array $customerAttributes = [], array $userAttributes = []): Customer
    {
        $user = User::factory()->create(array_merge(['role' => 'customer'], $userAttributes));
        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5((string) $user->id), 0, 8)),
        ], $customerAttributes));
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function enableAi(): void
    {
        config(['services.anthropic.key' => 'test-key']);
    }

    /** Claude-Antwort (Vision) als Http-Fake hinterlegen. */
    private function fakeAnalysis(array $payload): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode($payload)]],
            ]),
        ]);
    }

    /**
     * OCR-Basisebene fuer die Analyse-Orchestrierung faken (statt eines
     * echten Tesseract-Aufrufs) - deterministisch und unabhaengig davon,
     * ob der Test-Runner das Systempaket installiert hat.
     */
    private function fakeOcr(string $text, bool $available = true): void
    {
        config(['services.ocr.enabled' => $available]);
        $this->app->bind(TextExtractorInterface::class, fn () => new class($text, $available) implements TextExtractorInterface {
            public function __construct(private string $text, private bool $available) {}
            public function isAvailable(): bool { return $this->available; }
            public function extract(string $binary, string $mime): string { return $this->available ? $this->text : ''; }
        });
    }

    private function gesundheitskartePayload(array $person = [], array $extra = []): array
    {
        return array_merge([
            'type' => 'gesundheitskarte',
            'confidence' => 95,
            'summary' => 'Gesundheitskarte der AOK fuer Max Mustermann.',
            'title' => 'Gesundheitskarte AOK',
            'data' => array_merge([
                'person' => array_merge(['first_name' => 'Max', 'last_name' => 'Mustermann'], $person),
                'gesundheit' => ['health_insurance_company' => 'AOK Bayern', 'health_insurance_number' => 'A123456789'],
            ], $extra),
        ], []);
    }

    /* ---------------------------------------------------------------
     | Kundenportal: Mehrseiten-Scanner
     * -------------------------------------------------------------- */

    public function test_portal_scan_bundles_pages_into_one_pdf_and_analyzes_it(): void
    {
        Storage::fake('local');
        $this->enableAi();
        $this->fakeAnalysis($this->gesundheitskartePayload());

        $customer = $this->makeCustomer();

        $response = $this->actingAs($customer->user)->postJson(route('portal.documents.scan'), [
            'pages' => [
                UploadedFile::fake()->image('seite-1.jpg', 800, 1100),
                UploadedFile::fake()->image('seite-2.jpg', 800, 1100),
            ],
        ]);

        $response->assertOk()->assertJson(['ai_enabled' => true]);

        $doc = Document::findOrFail($response->json('id'));
        $this->assertSame((string) $customer->id, (string) $doc->customer_id);
        $this->assertSame(2, $doc->page_count);
        $this->assertSame('local', $doc->disk);
        $this->assertSame('customer', $doc->visibility);
        Storage::disk('local')->assertExists($doc->file_path);

        // Die Seiten wurden zu EINEM gueltigen PDF gebuendelt.
        $pdf = Storage::disk('local')->get($doc->file_path);
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('/Count 2', $pdf);

        // Analyse (sync-Queue) hat Typ, Kategorie und Titel gesetzt.
        $doc->refresh();
        $this->assertSame('done', $doc->ai_status);
        $this->assertSame('gesundheitskarte', $doc->ai_type);
        $this->assertSame('identity', $doc->category);
        $this->assertSame(95, $doc->ai_confidence);
        $this->assertSame('Gesundheitskarte AOK.pdf', $doc->file_name);
        $this->assertSame('AOK Bayern', $doc->ai_extracted['gesundheit']['health_insurance_company']);

        // Freigabe-Protokoll (ai_decisions) wurde geschrieben.
        $this->assertDatabaseHas('ai_decisions', [
            'document_id' => $doc->id,
            'skill' => 'analyze_document',
            'status' => 'suggested',
        ]);
    }

    public function test_portal_pdf_upload_keeps_original_name_and_links_contract_by_number(): void
    {
        Storage::fake('local');
        $this->enableAi();

        $customer = $this->makeCustomer();
        $contract = Contract::create([
            'customer_id' => $customer->id,
            'contract_number' => 'HUK-998877',
            'type' => 'kfz',
            'insurer' => 'HUK24',
            'status' => 'active',
        ]);

        $this->fakeAnalysis([
            'type' => 'kfz_vertrag',
            'confidence' => 90,
            'summary' => 'KFZ-Versicherungsvertrag der HUK24.',
            'title' => 'KFZ-Vertrag HUK24',
            'data' => [
                'versicherung' => ['insurer' => 'HUK24', 'contract_number' => 'HUK-998877', 'sparte' => 'kfz'],
                'kfz' => ['license_plate' => 'B-AB 1234'],
            ],
        ]);

        $response = $this->actingAs($customer->user)->postJson(route('portal.documents.scan'), [
            'pdf' => UploadedFile::fake()->create('mein-vertrag.pdf', 200, 'application/pdf'),
        ]);

        $response->assertOk();
        $doc = Document::findOrFail($response->json('id'));
        $this->assertSame('mein-vertrag.pdf', $doc->file_name); // kein Umbenennen von Original-PDFs
        $this->assertSame('kfz_vertrag', $doc->ai_type);
        $this->assertSame((string) $contract->id, (string) $doc->contract_id); // automatisch verknuepft
    }

    public function test_portal_scan_without_api_key_stores_document_without_analysis(): void
    {
        Storage::fake('local');
        Http::fake();
        config(['services.anthropic.key' => '']);

        $customer = $this->makeCustomer();

        $response = $this->actingAs($customer->user)->postJson(route('portal.documents.scan'), [
            'pages' => [UploadedFile::fake()->image('seite-1.jpg', 600, 800)],
        ]);

        $response->assertOk()->assertJson(['ai_enabled' => false]);
        $doc = Document::findOrFail($response->json('id'));
        $this->assertSame('none', $doc->ai_status);
        Http::assertNothingSent();
    }

    public function test_failed_analysis_marks_document_failed_but_keeps_file(): void
    {
        Storage::fake('local');
        $this->enableAi();
        Http::fake(['api.anthropic.com/*' => Http::response('Server Error', 500)]);

        $customer = $this->makeCustomer();

        $response = $this->actingAs($customer->user)->postJson(route('portal.documents.scan'), [
            'pages' => [UploadedFile::fake()->image('seite-1.jpg', 600, 800)],
        ]);

        $response->assertOk();
        $doc = Document::findOrFail($response->json('id'));
        $this->assertSame('failed', $doc->ai_status);
        $this->assertNotNull($doc->ai_error);
        Storage::disk('local')->assertExists($doc->file_path);
    }

    public function test_portal_status_endpoint_is_scoped_to_own_documents(): void
    {
        Storage::fake('local');
        $owner = $this->makeCustomer();
        Storage::disk('local')->put('customers/' . $owner->id . '/documents/scan.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => $owner->id,
            'category' => 'other',
            'file_name' => 'scan.pdf',
            'file_path' => 'customers/' . $owner->id . '/documents/scan.pdf',
            'disk' => 'local',
            'ai_status' => 'pending',
        ]);

        $this->actingAs($owner->user)
            ->getJson(route('portal.documents.analyse_status', $doc->id))
            ->assertOk()->assertJson(['status' => 'pending']);

        $attacker = $this->makeCustomer();
        $this->actingAs($attacker->user)
            ->getJson(route('portal.documents.analyse_status', $doc->id))
            ->assertNotFound();
    }

    /* ---------------------------------------------------------------
     | CRM: Dokumenten-Eingang & Matching
     * -------------------------------------------------------------- */

    public function test_admin_smart_upload_without_customer_creates_inbox_document_with_match_suggestion(): void
    {
        Storage::fake('local');
        $this->enableAi();

        // Vorhandener Kunde: Name + Geburtsdatum ergeben Score 70-90 (confirm).
        $customer = $this->makeCustomer(
            ['birth_date' => '1990-05-04'],
            ['name' => 'Max Mustermann'],
        );

        $this->fakeAnalysis($this->gesundheitskartePayload(['birth_date' => '1990-05-04']));

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->postJson(route('admin.documents.smart_upload'), [
            'files' => [UploadedFile::fake()->image('karte.jpg', 800, 500)],
        ]);

        $response->assertOk();
        $doc = Document::findOrFail($response->json('ids.0'));

        $this->assertNull($doc->customer_id); // kein Auto-Assign bei Score <= 90
        $this->assertSame('done', $doc->ai_status);
        $match = $doc->ai_extracted['match'];
        $this->assertSame((string) $customer->id, (string) $match['customer_id']);
        $this->assertSame('confirm', $match['tier']);
        $this->assertStringStartsWith('documents/eingang/', $doc->file_path);
    }

    public function test_single_image_upload_works_without_gd_extension(): void
    {
        // Fehlt die GD-Erweiterung auf dem Server, muss ein einzelnes Bild
        // trotzdem hochladbar sein (direkt gespeichert, nicht als PDF gebuendelt).
        Storage::fake('local');
        $this->app->instance(\App\Services\Pdf\ImagesToPdfService::class, new class extends \App\Services\Pdf\ImagesToPdfService {
            public function canBuild(): bool { return false; }
            public function build(array $imageBinaries): string { throw new \RuntimeException('GD fehlt - darf hier nicht aufgerufen werden.'); }
        });

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->postJson(route('admin.documents.smart_upload'), [
            'files' => [UploadedFile::fake()->image('screenshot.png', 800, 500)],
        ]);

        $response->assertOk();
        $doc = Document::findOrFail($response->json('ids.0'));
        $this->assertSame(1, $doc->page_count);
        $this->assertStringEndsWith('.png', $doc->file_path); // direkt als Bild, kein .pdf
        Storage::disk('local')->assertExists($doc->file_path);
    }

    public function test_admin_smart_upload_auto_assigns_on_unambiguous_match(): void
    {
        Storage::fake('local');
        $this->enableAi();

        // Geburtsdatum (40) + Name (30) + E-Mail (20) + Telefon-Bonus (5) => Score > 90.
        $customer = $this->makeCustomer(
            ['birth_date' => '1990-05-04', 'phone' => '0171 1234567'],
            ['name' => 'Max Mustermann', 'email' => 'max@example.com'],
        );

        $this->fakeAnalysis($this->gesundheitskartePayload([
            'birth_date' => '1990-05-04',
            'email' => 'max@example.com',
            'phone' => '0171-1234567',
        ]));

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->postJson(route('admin.documents.smart_upload'), [
            'files' => [UploadedFile::fake()->image('karte.jpg', 800, 500)],
        ]);

        $response->assertOk();
        $doc = Document::findOrFail($response->json('ids.0'));

        $this->assertSame((string) $customer->id, (string) $doc->customer_id);
        $this->assertStringStartsWith('customers/' . $customer->id . '/documents/', $doc->file_path);
        Storage::disk('local')->assertExists($doc->file_path);
        $this->assertDatabaseHas('internal_notifications', [
            'title' => 'Dokument automatisch zugeordnet: Gesundheitskarte',
        ]);
    }

    public function test_admin_smart_upload_bundles_images_and_keeps_pdfs_separate(): void
    {
        Storage::fake('local');
        config(['services.anthropic.key' => '']);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->postJson(route('admin.documents.smart_upload'), [
            'files' => [
                UploadedFile::fake()->image('s1.jpg', 600, 800),
                UploadedFile::fake()->image('s2.jpg', 600, 800),
                UploadedFile::fake()->create('rechnung.pdf', 50, 'application/pdf'),
            ],
        ]);

        $response->assertOk();
        $ids = $response->json('ids');
        $this->assertCount(2, $ids); // 1 gebuendelter Scan + 1 PDF

        $scan = Document::findOrFail($ids[0]);
        $this->assertSame(2, $scan->page_count);
        $pdf = Document::findOrFail($ids[1]);
        $this->assertSame('rechnung.pdf', $pdf->file_name);
    }

    public function test_assign_endpoint_moves_document_applies_fields_and_creates_contract(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/eingang/scan.pdf', '%PDF-1.4');

        $doc = Document::create([
            'customer_id' => null,
            'category' => 'contract',
            'file_name' => 'KFZ-Vertrag.pdf',
            'file_path' => 'documents/eingang/scan.pdf',
            'disk' => 'local',
            'visibility' => 'internal',
            'ai_status' => 'done',
            'ai_type' => 'kfz_vertrag',
            'ai_confidence' => 92,
            'ai_extracted' => [
                'person' => ['birth_date' => '1985-01-15', 'phone' => '030 123456'],
                'versicherung' => [
                    'insurer' => 'Allianz', 'contract_number' => 'AZ-111222', 'sparte' => 'kfz',
                    'start_date' => '2026-01-01', 'premium_amount' => 89.9, 'premium_interval' => 'monthly',
                ],
                'kfz' => ['license_plate' => 'B-XY 987', 'vin' => 'WAUZZZ8V5KA123456', 'manufacturer' => 'Audi', 'model' => 'A3'],
                'bank' => ['iban' => 'DE89370400440532013000', 'account_holder' => 'Erika Beispiel'],
            ],
        ]);
        AiDecision::create([
            'document_id' => $doc->id,
            'skill' => 'analyze_document',
            'input_hash' => str_repeat('a', 64),
            'output' => ['type' => 'kfz_vertrag'],
            'status' => 'suggested',
        ]);

        $customer = $this->makeCustomer();
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson(route('admin.documents.assign', $doc->id), [
            'customer_id' => (string) $customer->id,
            'apply_fields' => ['birth_date', 'phone', 'iban'],
            'create_contract' => 1,
            'visibility' => 'customer',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $doc->refresh();
        $this->assertSame((string) $customer->id, (string) $doc->customer_id);
        $this->assertSame('customer', $doc->visibility);
        $this->assertStringStartsWith('customers/' . $customer->id . '/documents/', $doc->file_path);
        Storage::disk('local')->assertExists($doc->file_path);
        Storage::disk('local')->assertMissing('documents/eingang/scan.pdf');

        // Nur leere Kundenfelder wurden befuellt (inkl. verschluesselter IBAN).
        $customer->refresh();
        $this->assertSame('1985-01-15', (string) $customer->birth_date);
        $this->assertSame('030 123456', $customer->phone);
        $this->assertSame('DE89370400440532013000', $customer->iban);

        // Vertrag inkl. Fahrzeugdaten wurde angelegt und verknuepft.
        $contract = Contract::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('AZ-111222', $contract->contract_number);
        $this->assertSame('kfz', $contract->type);
        $this->assertSame('B-XY 987', $contract->vehicleDetail->license_plate);
        $this->assertSame((string) $contract->id, (string) $doc->contract_id);

        // Der KI-Vorschlag wurde als angenommen protokolliert.
        $this->assertDatabaseHas('ai_decisions', [
            'document_id' => $doc->id,
            'status' => 'accepted',
            'decided_by' => $admin->id,
        ]);
    }

    public function test_apply_fields_never_overwrites_existing_customer_data(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/eingang/scan.pdf', '%PDF-1.4');

        $doc = Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'Ausweis.pdf',
            'file_path' => 'documents/eingang/scan.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'personalausweis',
            'ai_extracted' => ['person' => ['birth_date' => '1999-09-09', 'phone' => '0000']],
        ]);

        $customer = $this->makeCustomer(['birth_date' => '1985-01-15', 'phone' => '030 999']);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson(route('admin.documents.assign', $doc->id), [
            'customer_id' => (string) $customer->id,
            'apply_fields' => ['birth_date', 'phone'],
        ])->assertOk();

        $customer->refresh();
        $this->assertSame('1985-01-15', (string) $customer->birth_date); // unveraendert
        $this->assertSame('030 999', $customer->phone);                   // unveraendert
    }

    public function test_create_customer_endpoint_builds_customer_from_extraction(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/eingang/scan.pdf', '%PDF-1.4');

        $doc = Document::create([
            'customer_id' => null,
            'category' => 'identity',
            'file_name' => 'Personalausweis.pdf',
            'file_path' => 'documents/eingang/scan.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'personalausweis',
            'ai_extracted' => ['person' => [
                'first_name' => 'Sabine', 'last_name' => 'Neukundin',
                'birth_date' => '1992-03-11', 'street' => 'Lindenweg', 'house_number' => '5',
                'zip' => '10115', 'city' => 'Berlin',
            ]],
        ]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->postJson(route('admin.documents.create_customer', $doc->id), [
            'apply_fields' => ['birth_date', 'address'],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $doc->refresh();
        $customer = Customer::findOrFail($doc->customer_id);
        $this->assertSame('Sabine Neukundin', $customer->user->name);
        $this->assertSame('customer', $customer->user->role);
        // Neuanlage nutzt den zentralen Nummernkreis (JJ + laufende Nummer).
        $this->assertMatchesRegularExpression('/^\d{7}$/', $customer->customer_number);
        $this->assertSame('1992-03-11', (string) $customer->birth_date);
        $this->assertSame('10115', $customer->address_zip);
    }

    public function test_mobile_number_is_applied_to_mobile_field_not_phone(): void
    {
        // Eine eindeutige Handynummer (aus dem CHECK24-Protokoll) gehoert ins
        // Feld "Handy" (mobile), nicht ins Festnetz-Feld "Telefon".
        Storage::fake('local');
        Storage::disk('local')->put('documents/eingang/kfz.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'Protokoll.pdf',
            'file_path' => 'documents/eingang/kfz.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'beratungsprotokoll',
            'ai_extracted' => ['person' => [
                'first_name' => 'Max', 'last_name' => 'Mobil', 'phone' => '015112345678',
            ]],
        ]);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson(route('admin.documents.create_customer', $doc->id), [
            'apply_fields' => ['phone'],
        ])->assertOk();

        $customer = Customer::findOrFail($doc->fresh()->customer_id);
        $this->assertSame('015112345678', $customer->mobile);
        $this->assertNull($customer->phone);
    }

    public function test_extracted_email_becomes_main_login_email_on_assign(): void
    {
        // Kernproblem: die aus dem Dokument gelesene E-Mail muss die HAUPT-
        // Login-Adresse (users.email) werden - erst damit laesst sich der
        // Portal-Zugang aktivieren und die Willkommens-Mail versenden. Bisher
        // landete sie faelschlich nur in der Zweitadresse (email2).
        Storage::fake('local');
        Storage::disk('local')->put('documents/eingang/kfz.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'Antrag.pdf',
            'file_path' => 'documents/eingang/kfz.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'kfz_vertrag',
            'ai_extracted' => ['person' => ['email' => 'Mustafa.jabir1990@gmail.com']],
        ]);
        // Bestandskunde OHNE echte E-Mail (Import-Platzhalter).
        $customer = $this->makeCustomer([], ['email' => 'alt-2600001@dienstly24.internal']);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson(route('admin.documents.assign', $doc->id), [
            'customer_id' => (string) $customer->id,
            'apply_fields' => ['email2'],
        ])->assertOk();

        $customer->refresh();
        $this->assertSame('Mustafa.jabir1990@gmail.com', $customer->user->email);
        $this->assertTrue($customer->user->hasRealEmail());
        // Keine Dopplung in die Zweitadresse.
        $this->assertNull($customer->email2);
    }

    public function test_extracted_email_falls_back_to_email2_when_main_exists(): void
    {
        // Hat der Kunde bereits eine echte Haupt-Adresse, wird sie NICHT
        // ueberschrieben - die gelesene Adresse wandert in die Zweitadresse.
        Storage::fake('local');
        Storage::disk('local')->put('documents/eingang/kfz2.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'Antrag2.pdf',
            'file_path' => 'documents/eingang/kfz2.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'kfz_vertrag',
            'ai_extracted' => ['person' => ['email' => 'neu@example.com']],
        ]);
        $customer = $this->makeCustomer([], ['email' => 'bestand@example.com']);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson(route('admin.documents.assign', $doc->id), [
            'customer_id' => (string) $customer->id,
            'apply_fields' => ['email2'],
        ])->assertOk();

        $customer->refresh();
        $this->assertSame('bestand@example.com', $customer->user->email);
        $this->assertSame('neu@example.com', $customer->email2);
    }

    public function test_extracted_email_falls_back_to_email2_when_taken_by_another_user(): void
    {
        // Gehoert die Adresse schon einem ANDEREN Nutzer (users.email ist
        // unique), darf sie nicht als Login-Adresse gesetzt werden -> email2.
        Storage::fake('local');
        Storage::disk('local')->put('documents/eingang/kfz3.pdf', '%PDF-1.4');
        User::factory()->create(['email' => 'geteilt@example.com']);
        $doc = Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'Antrag3.pdf',
            'file_path' => 'documents/eingang/kfz3.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'kfz_vertrag',
            'ai_extracted' => ['person' => ['email' => 'geteilt@example.com']],
        ]);
        $customer = $this->makeCustomer([], ['email' => 'ohne-mail@dienstly24.internal']);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson(route('admin.documents.assign', $doc->id), [
            'customer_id' => (string) $customer->id,
            'apply_fields' => ['email2'],
        ])->assertOk();

        $customer->refresh();
        $this->assertSame('ohne-mail@dienstly24.internal', $customer->user->email);
        $this->assertFalse($customer->user->hasRealEmail());
        $this->assertSame('geteilt@example.com', $customer->email2);
    }

    public function test_create_customer_puts_extracted_email_in_main_not_email2(): void
    {
        // Neuanlage: die freie Adresse wird die Haupt-Login-Adresse - und wird
        // NICHT zusaetzlich (doppelt) in die Zweitadresse geschrieben.
        Storage::fake('local');
        Storage::disk('local')->put('documents/eingang/neu.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'Antrag_neu.pdf',
            'file_path' => 'documents/eingang/neu.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'kfz_vertrag',
            'ai_extracted' => ['person' => [
                'first_name' => 'Mustafa', 'last_name' => 'Jabir',
                'email' => 'mustafa@example.com',
            ]],
        ]);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson(route('admin.documents.create_customer', $doc->id), [
            'apply_fields' => ['email2'],
        ])->assertOk();

        $customer = Customer::findOrFail($doc->fresh()->customer_id);
        $this->assertSame('mustafa@example.com', $customer->user->email);
        $this->assertNull($customer->email2);
    }

    public function test_create_customer_requires_a_name_when_none_extracted(): void
    {
        // Wurde der Name nicht (sicher) gelesen, darf die Neuanlage nicht
        // stillschweigend scheitern - der Server verlangt einen Namen.
        Storage::fake('local');
        Storage::disk('local')->put('documents/eingang/be.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'Beitrittserklaerung.pdf',
            'file_path' => 'documents/eingang/be.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'beitrittserklaerung',
            'ai_extracted' => ['person' => ['birth_date' => '1990-05-02']],
        ]);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson(route('admin.documents.create_customer', $doc->id), [])
            ->assertStatus(422);
        $this->assertNull($doc->fresh()->customer_id);
    }

    public function test_create_customer_uses_manually_entered_name(): void
    {
        // Der Mitarbeiter sieht das Dokument und traegt den Namen selbst ein,
        // wenn die Extraktion keinen (sicheren) Namen geliefert hat.
        Storage::fake('local');
        Storage::disk('local')->put('documents/eingang/be2.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'Beitrittserklaerung.pdf',
            'file_path' => 'documents/eingang/be2.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'beitrittserklaerung',
            'ai_extracted' => ['person' => ['birth_date' => '1990-05-02']],
        ]);
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson(route('admin.documents.create_customer', $doc->id), [
            'first_name' => 'Osama',
            'last_name' => 'Salem',
            'apply_fields' => ['birth_date'],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $doc->refresh();
        $customer = Customer::findOrFail($doc->customer_id);
        $this->assertSame('Osama Salem', $customer->user->name);
        $this->assertSame('1990-05-02', (string) $customer->birth_date);
    }

    public function test_create_customer_manual_name_overrides_extracted(): void
    {
        // Explizit eingegebener Name hat Vorrang vor einem (falsch) gelesenen.
        Storage::fake('local');
        Storage::disk('local')->put('documents/eingang/be3.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'Beitrittserklaerung.pdf',
            'file_path' => 'documents/eingang/be3.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'beitrittserklaerung',
            'ai_extracted' => ['person' => ['first_name' => 'Falsch', 'last_name' => 'Gelesen']],
        ]);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson(route('admin.documents.create_customer', $doc->id), [
            'first_name' => 'Jaber',
            'last_name' => 'Salem',
        ])->assertOk();

        $customer = Customer::findOrFail($doc->fresh()->customer_id);
        $this->assertSame('Jaber Salem', $customer->user->name);
    }

    public function test_excluded_fields_are_never_applied_to_customer(): void
    {
        // Betreiber-Vorgabe: Beruf, Fuehrerscheindatum und weitere Fahrer werden
        // NIE automatisch uebernommen (oft ungenau) - sie stehen nicht auf der
        // apply_fields-Whitelist und werden abgewiesen. (Familienstand/Geschlecht
        // sind seit dem KKH-Beitrittsformular erlaubt: dort strukturiert +
        // zuverlaessig; das Kfz-Protokoll liefert sie ohnehin nicht.)
        Storage::fake('local');
        Storage::disk('local')->put('documents/eingang/scan2.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'Protokoll.pdf',
            'file_path' => 'documents/eingang/scan2.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_extracted' => ['person' => ['first_name' => 'Ahmed', 'last_name' => 'Nassar']],
        ]);
        $admin = $this->makeAdmin();

        foreach (['occupation', 'fuehrerscheindatum', 'weitere_fahrer'] as $field) {
            $this->actingAs($admin)->postJson(route('admin.documents.create_customer', $doc->id), [
                'apply_fields' => [$field],
            ])->assertStatus(422);
        }

        // Dokument blieb unzugeordnet (nichts wurde uebernommen).
        $this->assertNull($doc->fresh()->customer_id);
    }

    public function test_create_customer_is_blocked_when_similar_customer_exists(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/eingang/scan.pdf', '%PDF-1.4');

        $this->makeCustomer(['birth_date' => '1992-03-11'], ['name' => 'Sabine Neukundin']);

        $doc = Document::create([
            'customer_id' => null,
            'category' => 'identity',
            'file_name' => 'Personalausweis.pdf',
            'file_path' => 'documents/eingang/scan.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'personalausweis',
            'ai_extracted' => ['person' => [
                'first_name' => 'Sabine', 'last_name' => 'Neukundin', 'birth_date' => '1992-03-11',
            ]],
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)->postJson(route('admin.documents.create_customer', $doc->id), [])
            ->assertStatus(422);

        $this->assertNull($doc->fresh()->customer_id);
    }

    /* ---------------------------------------------------------------
     | Zugriffsschutz
     * -------------------------------------------------------------- */

    public function test_employee_without_portfolio_cannot_upload_or_assign_to_foreign_customer(): void
    {
        Storage::fake('local');
        config(['services.anthropic.key' => '']);

        $customer = $this->makeCustomer();
        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);

        $this->actingAs($employee)->postJson(route('admin.documents.smart_upload'), [
            'files' => [UploadedFile::fake()->image('a.jpg', 400, 400)],
            'customer_id' => (string) $customer->id,
        ])->assertForbidden();

        Storage::disk('local')->put('documents/eingang/scan.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'scan.pdf',
            'file_path' => 'documents/eingang/scan.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
        ]);

        $this->actingAs($employee)->postJson(route('admin.documents.assign', $doc->id), [
            'customer_id' => (string) $customer->id,
        ])->assertForbidden();
    }

    public function test_customers_cannot_reach_admin_smart_upload_endpoints(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)->get(route('admin.documents.inbox'))->assertRedirect();
        $this->actingAs($customer->user)->postJson(route('admin.documents.smart_upload'), [])->assertRedirect();
    }

    public function test_admin_inbox_page_lists_unassigned_documents(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/eingang/scan.pdf', '%PDF-1.4');
        Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'Unbekanntes-Dokument.pdf',
            'file_path' => 'documents/eingang/scan.pdf',
            'disk' => 'local',
            'ai_status' => 'pending',
        ]);

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.documents.inbox'))
            ->assertOk()
            ->assertSee('Unbekanntes-Dokument.pdf')
            ->assertSee('Dokumenten-Eingang');
    }

    public function test_admin_smart_upload_classifies_by_real_content_not_client_extension(): void
    {
        Storage::fake('local');
        config(['services.anthropic.key' => '']);

        // Echtes JPEG, aber vom Client als "foto.pdf" benannt.
        $img = imagecreatetruecolor(300, 400);
        ob_start();
        imagejpeg($img);
        $jpegBytes = (string) ob_get_clean();
        imagedestroy($img);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->postJson(route('admin.documents.smart_upload'), [
            'files' => [UploadedFile::fake()->createWithContent('foto.pdf', $jpegBytes)],
        ]);

        $response->assertOk();
        $doc = Document::findOrFail($response->json('ids.0'));
        // Als Bild erkannt -> zu einem Scan-PDF gebuendelt, nicht als kaputte "PDF" gespeichert.
        $this->assertSame(1, $doc->page_count);
        $this->assertStringStartsWith('%PDF-1.4', Storage::disk('local')->get($doc->file_path));
    }

    public function test_admin_smart_upload_without_bundling_creates_one_document_per_image(): void
    {
        Storage::fake('local');
        config(['services.anthropic.key' => '']);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->postJson(route('admin.documents.smart_upload'), [
            'files' => [
                UploadedFile::fake()->image('a.jpg', 400, 500),
                UploadedFile::fake()->image('b.jpg', 400, 500),
            ],
            'bundle_images' => 0,
        ]);

        $response->assertOk();
        $this->assertCount(2, $response->json('ids'));
        foreach ($response->json('ids') as $id) {
            $this->assertSame(1, Document::findOrFail($id)->page_count);
        }
    }

    public function test_portal_scan_rejects_pages_and_pdf_together(): void
    {
        Storage::fake('local');
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)->postJson(route('portal.documents.scan'), [
            'pages' => [UploadedFile::fake()->image('s.jpg', 400, 400)],
            'pdf' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_restricted_employee_can_open_inbox_documents_but_sees_only_own_uploads(): void
    {
        Storage::fake('local');
        config(['services.anthropic.key' => '']);

        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $admin = $this->makeAdmin();

        // Der Mitarbeiter laedt selbst ein Dokument in den Eingang hoch ...
        $response = $this->actingAs($employee)->postJson(route('admin.documents.smart_upload'), [
            'files' => [UploadedFile::fake()->image('eigenes.jpg', 400, 400)],
        ]);
        $ownDoc = Document::findOrFail($response->json('ids.0'));

        // ... und ein Admin ein weiteres.
        Storage::disk('local')->put('documents/eingang/fremd.pdf', '%PDF-1.4');
        Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'Fremdes-Dokument.pdf',
            'file_path' => 'documents/eingang/fremd.pdf',
            'disk' => 'local',
            'uploaded_by' => $admin->id,
        ]);

        // Eingang zeigt dem Mitarbeiter nur die eigenen Uploads.
        $this->actingAs($employee)->get(route('admin.documents.inbox'))
            ->assertOk()
            ->assertDontSee('Fremdes-Dokument.pdf');

        // Und der Download des eigenen Eingangs-Dokuments liefert kein 403 mehr.
        $this->actingAs($employee)->get(route('admin.documents.download', $ownDoc->id))
            ->assertOk();
    }

    public function test_create_customer_falls_back_to_placeholder_email_when_address_is_taken(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/eingang/scan.pdf', '%PDF-1.4');

        User::factory()->create(['email' => 'belegt@example.com']);

        $doc = Document::create([
            'customer_id' => null,
            'category' => 'identity',
            'file_name' => 'Ausweis.pdf',
            'file_path' => 'documents/eingang/scan.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'personalausweis',
            'ai_extracted' => ['person' => [
                'first_name' => 'Paul', 'last_name' => 'Einmalig', 'email' => 'belegt@example.com',
            ]],
        ]);

        $response = $this->actingAs($this->makeAdmin())
            ->postJson(route('admin.documents.create_customer', $doc->id), []);

        $response->assertOk();
        $customer = Customer::findOrFail($doc->fresh()->customer_id);
        // Kein 500 durch unique users.email - Platzhalter-Adresse statt Duplikat.
        $this->assertNotSame('belegt@example.com', $customer->user->email);
    }

    public function test_ai_extracted_is_encrypted_at_rest(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/eingang/scan.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'scan.pdf',
            'file_path' => 'documents/eingang/scan.pdf',
            'disk' => 'local',
            'ai_extracted' => ['bank' => ['iban' => 'DE89370400440532013000']],
        ]);

        $raw = (string) \Illuminate\Support\Facades\DB::table('documents')->where('id', $doc->id)->value('ai_extracted');
        $this->assertStringNotContainsString('DE89370400440532013000', $raw);
        $this->assertSame('DE89370400440532013000', $doc->fresh()->ai_extracted['bank']['iban']);
    }

    public function test_portal_status_hides_internal_error_details(): void
    {
        Storage::fake('local');
        $customer = $this->makeCustomer();
        Storage::disk('local')->put('customers/' . $customer->id . '/documents/scan.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => $customer->id,
            'category' => 'other',
            'file_name' => 'scan.pdf',
            'file_path' => 'customers/' . $customer->id . '/documents/scan.pdf',
            'disk' => 'local',
            'ai_status' => 'failed',
            'ai_error' => 'KI-Dienst antwortete mit HTTP 500',
        ]);

        $this->actingAs($customer->user)
            ->getJson(route('portal.documents.analyse_status', $doc->id))
            ->assertOk()
            ->assertJson(['status' => 'failed', 'error' => null]);
    }

    public function test_reanalyze_endpoint_restarts_analysis(): void
    {
        Storage::fake('local');
        $this->enableAi();
        $this->fakeAnalysis($this->gesundheitskartePayload());

        Storage::disk('local')->put('documents/eingang/scan.pdf', '%PDF-1.4 fake');
        $doc = Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'scan.pdf',
            'file_path' => 'documents/eingang/scan.pdf',
            'disk' => 'local',
            'ai_status' => 'failed',
            'ai_error' => 'Kaputt',
        ]);

        $this->actingAs($this->makeAdmin())
            ->postJson(route('admin.documents.reanalyze', $doc->id))
            ->assertOk();

        $doc->refresh();
        $this->assertSame('done', $doc->ai_status);
        $this->assertNull($doc->ai_error);
        $this->assertSame('gesundheitskarte', $doc->ai_type);
    }

    /* ---------------------------------------------------------------
     | Review-Fixes Runde 2 (Sicherheit/DSGVO/Korrektheit)
     * -------------------------------------------------------------- */

    public function test_ai_decision_output_is_encrypted_at_rest(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/eingang/scan.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'scan.pdf',
            'file_path' => 'documents/eingang/scan.pdf',
            'disk' => 'local',
        ]);
        AiDecision::create([
            'document_id' => $doc->id,
            'skill' => 'analyze_document',
            'input_hash' => str_repeat('a', 64),
            'output' => ['type' => 'gesundheitskarte', 'match' => ['name' => 'Max Mustermann', 'customer_number' => '2600001']],
            'status' => 'suggested',
        ]);

        $raw = (string) \Illuminate\Support\Facades\DB::table('ai_decisions')->where('document_id', $doc->id)->value('output');
        $this->assertStringNotContainsString('Max Mustermann', $raw);
        $this->assertStringNotContainsString('2600001', $raw);
    }

    public function test_customer_deletion_redacts_document_ai_decisions_but_keeps_audit_row(): void
    {
        Storage::fake('local');
        $customer = $this->makeCustomer();
        Storage::disk('local')->put('customers/' . $customer->id . '/documents/scan.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => $customer->id,
            'category' => 'other',
            'file_name' => 'scan.pdf',
            'file_path' => 'customers/' . $customer->id . '/documents/scan.pdf',
            'disk' => 'local',
        ]);
        $decision = AiDecision::create([
            'document_id' => $doc->id,
            'skill' => 'analyze_document',
            'input_hash' => str_repeat('a', 64),
            'output' => ['type' => 'gesundheitskarte', 'match' => ['name' => $customer->user->name, 'customer_number' => $customer->customer_number]],
            'status' => 'suggested',
        ]);

        app(\App\Services\CustomerDeletionService::class)->delete($customer);

        $decision->refresh();
        $this->assertNull($decision->document_id); // nullOnDelete greift nach der Kaskade
        $this->assertTrue($decision->output['redacted_on_customer_deletion'] ?? false);
        $this->assertArrayNotHasKey('match', $decision->output);
    }

    public function test_documents_prune_unassigned_deletes_old_inbox_documents_only(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put('documents/eingang/old.pdf', '%PDF-1.4');
        $old = Document::create([
            'customer_id' => null, 'category' => 'other', 'file_name' => 'old.pdf',
            'file_path' => 'documents/eingang/old.pdf', 'disk' => 'local',
        ]);
        $old->forceFill(['created_at' => now()->subDays(120)])->save();
        AiDecision::create([
            'document_id' => $old->id, 'skill' => 'analyze_document',
            'input_hash' => str_repeat('a', 64), 'output' => ['type' => 'sonstiges'], 'status' => 'suggested',
        ]);

        Storage::disk('local')->put('documents/eingang/recent.pdf', '%PDF-1.4');
        $recent = Document::create([
            'customer_id' => null, 'category' => 'other', 'file_name' => 'recent.pdf',
            'file_path' => 'documents/eingang/recent.pdf', 'disk' => 'local',
        ]);

        $customer = $this->makeCustomer();
        Storage::disk('local')->put('customers/' . $customer->id . '/documents/mine.pdf', '%PDF-1.4');
        $assigned = Document::create([
            'customer_id' => $customer->id, 'category' => 'other', 'file_name' => 'mine.pdf',
            'file_path' => 'customers/' . $customer->id . '/documents/mine.pdf', 'disk' => 'local',
        ]);
        $assigned->forceFill(['created_at' => now()->subDays(120)])->save();

        $this->artisan('documents:prune-unassigned')->assertExitCode(0);

        $this->assertDatabaseMissing('documents', ['id' => $old->id]);
        $this->assertDatabaseMissing('ai_decisions', ['document_id' => $old->id]);
        Storage::disk('local')->assertMissing('documents/eingang/old.pdf');

        $this->assertDatabaseHas('documents', ['id' => $recent->id]);   // zu jung
        $this->assertDatabaseHas('documents', ['id' => $assigned->id]); // zugeordnet, nicht betroffen
    }

    public function test_admin_status_and_actions_are_scoped_to_uploader_for_restricted_employee(): void
    {
        Storage::fake('local');
        $uploader = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $other = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);

        Storage::disk('local')->put('documents/eingang/scan.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => null, 'category' => 'other', 'file_name' => 'scan.pdf',
            'file_path' => 'documents/eingang/scan.pdf', 'disk' => 'local', 'uploaded_by' => $uploader->id,
        ]);

        // Der Uploader selbst darf den Status abfragen ...
        $this->actingAs($uploader)
            ->getJson(route('admin.documents.analyse_status', $doc->id))
            ->assertOk();

        // ... ein anderer eingeschraenkter Mitarbeiter nicht (vorher 403-Luecke).
        $this->actingAs($other)
            ->getJson(route('admin.documents.analyse_status', $doc->id))
            ->assertForbidden();
        $this->actingAs($other)
            ->postJson(route('admin.documents.reanalyze', $doc->id))
            ->assertForbidden();

        // Admin darf immer.
        $this->actingAs($this->makeAdmin())
            ->getJson(route('admin.documents.analyse_status', $doc->id))
            ->assertOk();
    }

    public function test_match_outside_portfolio_hides_name_and_number(): void
    {
        Storage::fake('local');
        $foreignCustomer = $this->makeCustomer([], ['name' => 'Geheim Kunde']);
        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        // employee sieht KEINEN Kunden (leeres Portfolio) -> foreignCustomer ist ausserhalb.

        Storage::disk('local')->put('documents/eingang/scan.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => null, 'category' => 'other', 'file_name' => 'scan.pdf',
            'file_path' => 'documents/eingang/scan.pdf', 'disk' => 'local', 'uploaded_by' => $employee->id,
            'ai_status' => 'done', 'ai_extracted' => [
                'match' => ['customer_id' => (string) $foreignCustomer->id, 'name' => 'Geheim Kunde', 'customer_number' => $foreignCustomer->customer_number, 'score' => 85, 'tier' => 'confirm'],
            ],
        ]);

        $inboxResponse = $this->actingAs($employee)->get(route('admin.documents.inbox'));
        $inboxResponse->assertOk()
            ->assertDontSee('Geheim Kunde')
            ->assertDontSee($foreignCustomer->customer_number)
            ->assertSee('außerhalb Ihres Portfolios');

        $statusResponse = $this->actingAs($employee)->getJson(route('admin.documents.analyse_status', $doc->id));
        $statusResponse->assertOk();
        $this->assertTrue($statusResponse->json('match.out_of_portfolio'));
        $this->assertArrayNotHasKey('name', $statusResponse->json('match'));
    }

    public function test_customer_search_is_throttled_and_scoped(): void
    {
        Storage::fake('local');
        $visible = $this->makeCustomer([], ['name' => 'Sichtbar Kunde']);
        $hidden = $this->makeCustomer([], ['name' => 'Versteckt Kunde']);

        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $employee->assignedCustomers()->attach((string) $visible->id);

        $this->actingAs($employee)
            ->getJson(route('admin.documents.customer_search') . '?q=Kunde')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Sichtbar Kunde'])
            ->assertJsonMissing(['name' => 'Versteckt Kunde']);

        // LIKE-Wildcards werden escaped, kein Server-Fehler bei Sonderzeichen.
        $this->actingAs($employee)
            ->getJson(route('admin.documents.customer_search') . '?q=' . urlencode('%_\\'))
            ->assertOk();
    }

    public function test_assign_returns_clean_404_for_deleted_customer(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/eingang/scan.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => null, 'category' => 'other', 'file_name' => 'scan.pdf',
            'file_path' => 'documents/eingang/scan.pdf', 'disk' => 'local',
        ]);

        $this->actingAs($this->makeAdmin())
            ->postJson(route('admin.documents.assign', $doc->id), ['customer_id' => (string) \Illuminate\Support\Str::uuid()])
            ->assertStatus(404)
            ->assertJsonStructure(['message']);
    }

    public function test_assign_is_idempotent_for_same_customer_and_conflicts_for_different_one(): void
    {
        Storage::fake('local');
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();
        Storage::disk('local')->put('customers/' . $customerA->id . '/documents/scan.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => $customerA->id, 'category' => 'other', 'file_name' => 'scan.pdf',
            'file_path' => 'customers/' . $customerA->id . '/documents/scan.pdf', 'disk' => 'local',
        ]);
        $admin = $this->makeAdmin();

        // Gleicher Kunde erneut zuordnen: idempotent, kein Fehler.
        $this->actingAs($admin)
            ->postJson(route('admin.documents.assign', $doc->id), ['customer_id' => (string) $customerA->id])
            ->assertOk()->assertJson(['ok' => true]);

        // Anderer Kunde: klarer Konflikt statt stillem Ueberschreiben.
        $this->actingAs($admin)
            ->postJson(route('admin.documents.assign', $doc->id), ['customer_id' => (string) $customerB->id])
            ->assertStatus(422);
    }

    public function test_create_customer_rejects_already_assigned_document(): void
    {
        Storage::fake('local');
        $customer = $this->makeCustomer();
        Storage::disk('local')->put('customers/' . $customer->id . '/documents/scan.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => $customer->id, 'category' => 'other', 'file_name' => 'scan.pdf',
            'file_path' => 'customers/' . $customer->id . '/documents/scan.pdf', 'disk' => 'local',
        ]);

        $this->actingAs($this->makeAdmin())
            ->postJson(route('admin.documents.create_customer', $doc->id), [])
            ->assertStatus(422);
    }

    public function test_reanalyze_rejects_when_ai_disabled_or_already_running(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/eingang/scan.pdf', '%PDF-1.4');

        config(['services.anthropic.key' => '']);
        $doc = Document::create([
            'customer_id' => null, 'category' => 'other', 'file_name' => 'scan.pdf',
            'file_path' => 'documents/eingang/scan.pdf', 'disk' => 'local', 'ai_status' => 'failed',
        ]);
        $this->actingAs($this->makeAdmin())
            ->postJson(route('admin.documents.reanalyze', $doc->id))
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Analyse ist nicht konfiguriert (kein KI-Anbieter und keine OCR-Stufe aktiv).']);

        $this->enableAi();
        $doc->update(['ai_status' => 'processing']);
        $this->actingAs($this->makeAdmin())
            ->postJson(route('admin.documents.reanalyze', $doc->id))
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Analyse laeuft bereits.']);
    }

    public function test_portal_customer_cannot_attach_document_to_foreign_contract(): void
    {
        Storage::fake('local');
        $owner = $this->makeCustomer();
        $foreignContract = Contract::create([
            'customer_id' => $owner->id, 'contract_number' => 'X-1', 'type' => 'kfz', 'insurer' => 'HUK24', 'status' => 'active',
        ]);
        $attacker = $this->makeCustomer();

        $response = $this->actingAs($attacker->user)->postJson(route('portal.documents.scan'), [
            'pages' => [UploadedFile::fake()->image('s.jpg', 400, 400)],
            'contract_id' => (string) $foreignContract->id,
        ]);

        $response->assertOk();
        $doc = Document::findOrFail($response->json('id'));
        $this->assertNull($doc->contract_id); // fremder Vertrag wurde NICHT uebernommen
    }

    public function test_analyze_job_does_not_run_twice_for_same_document(): void
    {
        Storage::fake('local');
        $this->enableAi();
        // Simuliert: ein anderer Job-Lauf hat den Claim bereits gewonnen.
        Storage::disk('local')->put('documents/eingang/scan.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => null, 'category' => 'other', 'file_name' => 'scan.pdf',
            'file_path' => 'documents/eingang/scan.pdf', 'disk' => 'local', 'ai_status' => 'processing',
        ]);

        (new \App\Jobs\AnalyzeDocumentJob($doc->id))->handle(
            app(\App\Services\Ai\DocumentAnalyzer::class),
            app(\App\Services\DocumentIntake\DocumentIntakeService::class),
        );

        Http::assertNothingSent();
        $this->assertSame('processing', $doc->fresh()->ai_status); // unveraendert, kein zweiter Lauf
    }

    public function test_portal_scan_rejects_more_than_twenty_pages(): void
    {
        Storage::fake('local');
        $customer = $this->makeCustomer();

        $pages = [];
        for ($i = 0; $i < 21; $i++) {
            $pages[] = UploadedFile::fake()->image("s$i.jpg", 100, 100);
        }

        $this->actingAs($customer->user)->postJson(route('portal.documents.scan'), ['pages' => $pages])
            ->assertStatus(422);
    }

    public function test_link_matching_contract_matches_umlaut_license_plates(): void
    {
        $customer = $this->makeCustomer();
        $contract = Contract::create([
            'customer_id' => $customer->id, 'contract_number' => null, 'type' => 'kfz', 'insurer' => 'HUK24', 'status' => 'active',
        ]);
        \App\Models\ContractVehicleDetail::create(['contract_id' => $contract->id, 'license_plate' => 'WÜ-AB 123']);

        $doc = Document::create([
            'customer_id' => $customer->id, 'category' => 'contract', 'file_name' => 'v.pdf',
            'file_path' => 'x.pdf', 'disk' => 'local',
            'ai_extracted' => ['kfz' => ['license_plate' => 'WÜ AB123']], // andere Schreibweise, gleiches Kennzeichen
        ]);

        $linked = app(\App\Services\DocumentIntake\DocumentIntakeService::class)->linkMatchingContract($doc, $customer);

        $this->assertNotNull($linked);
        $this->assertSame((string) $contract->id, (string) $linked->id);
    }

    public function test_admin_smart_upload_rejects_oversized_combined_batch(): void
    {
        Storage::fake('local');
        config(['services.anthropic.key' => '']);

        $files = [];
        for ($i = 0; $i < 7; $i++) {
            $files[] = UploadedFile::fake()->create("doc$i.pdf", 9 * 1024, 'application/pdf'); // 9 MB je Datei
        }

        $this->actingAs($this->makeAdmin())
            ->postJson(route('admin.documents.smart_upload'), ['files' => $files])
            ->assertStatus(422);
    }

    /* ---------------------------------------------------------------
     | OCR-Basisebene (Tesseract) ohne KI-Anbieter
     * -------------------------------------------------------------- */

    public function test_ocr_only_analysis_produces_low_confidence_ocr_sourced_result(): void
    {
        Storage::fake('local');
        config(['services.anthropic.key' => '']);
        $this->fakeOcr("PERSONALAUSWEIS\nMax Mustermann\nIBAN: DE89 3704 0044 0532 0130 00");

        $customer = $this->makeCustomer();
        $response = $this->actingAs($customer->user)->postJson(route('portal.documents.scan'), [
            'pages' => [UploadedFile::fake()->image('ausweis.jpg', 600, 800)],
        ]);

        $response->assertOk()->assertJson(['ai_enabled' => true]);
        $doc = Document::findOrFail($response->json('id'));
        $this->assertSame('done', $doc->ai_status);
        $this->assertSame('personalausweis', $doc->ai_type);
        $this->assertSame('ocr', $doc->ai_source);
        $this->assertSame(40, $doc->ai_confidence);
        $this->assertSame('DE89370400440532013000', $doc->ai_extracted['bank']['iban']);
        Http::assertNothingSent(); // kein KI-Aufruf, rein OCR-basiert
    }

    public function test_sufficient_ocr_result_skips_ai_even_when_configured(): void
    {
        Storage::fake('local');
        $this->enableAi();
        // "Kostenlos zuerst": erkennt OCR den Typ UND ein Feld (hier
        // sepa_mandat + IBAN), darf Claude gar nicht erst aufgerufen werden.
        $this->fakeOcr("SEPA-Lastschriftmandat\nIBAN: DE89 3704 0044 0532 0130 00");
        $this->fakeAnalysis($this->gesundheitskartePayload());

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->postJson(route('admin.documents.smart_upload'), [
            'files' => [UploadedFile::fake()->image('mandat.jpg', 800, 500)],
        ]);

        $response->assertOk();
        $doc = Document::findOrFail($response->json('ids.0'));
        $this->assertSame('sepa_mandat', $doc->ai_type);
        $this->assertSame('ocr', $doc->ai_source);
        Http::assertNothingSent(); // kostenlose Stufe reichte -> keine KI-Kosten
    }

    public function test_insufficient_ocr_result_escalates_to_ai(): void
    {
        Storage::fake('local');
        $this->enableAi();
        // OCR erkennt weder Typ (-> 'sonstiges') noch ein Feld: die kostenlose
        // Stufe reicht NICHT, also uebernimmt der KI-Anbieter (Claude).
        $this->fakeOcr('Belangloser OCR-Text ohne jedes Stichwort.');
        $this->fakeAnalysis($this->gesundheitskartePayload());

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->postJson(route('admin.documents.smart_upload'), [
            'files' => [UploadedFile::fake()->image('karte.jpg', 800, 500)],
        ]);

        $response->assertOk();
        $doc = Document::findOrFail($response->json('ids.0'));
        $this->assertSame('gesundheitskarte', $doc->ai_type);
        $this->assertSame('ai', $doc->ai_source);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com'));
    }

    public function test_reanalyze_forces_ai_and_skips_ocr(): void
    {
        Storage::fake('local');
        $this->enableAi();
        config(['services.ocr.enabled' => true]);
        // OCR-Extraktor, der bei Aufruf FEHLSCHLAEGT: die erzwungene
        // KI-Eskalation (Button "Mit KI analysieren") muss die kostenlose
        // Vorstufe komplett ueberspringen, sonst wuerde dieser Extractor
        // aufgerufen und werfen.
        $this->app->bind(TextExtractorInterface::class, fn () => new class implements TextExtractorInterface {
            public function isAvailable(): bool { return true; }
            public function extract(string $binary, string $mime): string
            {
                throw new \RuntimeException('OCR darf bei erzwungener KI-Analyse nicht laufen.');
            }
        });
        Storage::disk('local')->put('documents/eingang/scan.pdf', '%PDF-1.4');
        $doc = Document::create([
            'customer_id' => null, 'category' => 'other', 'file_name' => 'scan.pdf',
            'file_path' => 'documents/eingang/scan.pdf', 'disk' => 'local',
            'ai_status' => 'done', 'ai_type' => 'sonstiges', 'ai_source' => 'ocr',
        ]);
        $this->fakeAnalysis($this->gesundheitskartePayload());

        // Erzwungene KI jetzt per explizitem Flag (eigener Button "Mit KI").
        $this->actingAs($this->makeAdmin())
            ->postJson(route('admin.documents.reanalyze', $doc->id), ['force_ai' => 1])
            ->assertOk();

        $doc->refresh();
        $this->assertSame('done', $doc->ai_status);
        $this->assertSame('gesundheitskarte', $doc->ai_type);
        $this->assertSame('ai', $doc->ai_source);
    }

    public function test_ocr_disabled_leaves_document_without_analysis_like_before(): void
    {
        Storage::fake('local');
        config(['services.anthropic.key' => '', 'services.ocr.enabled' => false]);

        $customer = $this->makeCustomer();
        $response = $this->actingAs($customer->user)->postJson(route('portal.documents.scan'), [
            'pages' => [UploadedFile::fake()->image('seite-1.jpg', 600, 800)],
        ]);

        $response->assertOk()->assertJson(['ai_enabled' => false]);
        $doc = Document::findOrFail($response->json('id'));
        $this->assertSame('none', $doc->ai_status);
        $this->assertNull($doc->ai_source);
    }

    public function test_ocr_extracted_iban_can_be_applied_when_assigning_to_customer(): void
    {
        Storage::fake('local');
        config(['services.anthropic.key' => '']);
        $this->fakeOcr("SEPA-Lastschriftmandat\nIBAN: DE89 3704 0044 0532 0130 00");

        $admin = $this->makeAdmin();
        $upload = $this->actingAs($admin)->postJson(route('admin.documents.smart_upload'), [
            'files' => [UploadedFile::fake()->image('mandat.jpg', 600, 400)],
        ]);
        $doc = Document::findOrFail($upload->json('ids.0'));
        $this->assertSame('ocr', $doc->ai_source);
        $this->assertSame('DE89370400440532013000', $doc->ai_extracted['bank']['iban']);

        $customer = $this->makeCustomer();
        $this->assertNull($customer->iban);

        $this->actingAs($admin)->postJson(route('admin.documents.assign', $doc->id), [
            'customer_id' => (string) $customer->id,
            'apply_fields' => ['iban'],
        ])->assertOk();

        $this->assertSame('DE89370400440532013000', $customer->fresh()->iban);
    }
}
