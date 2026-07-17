<?php

namespace Tests\Feature;

use App\Models\AiDecision;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
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
}
