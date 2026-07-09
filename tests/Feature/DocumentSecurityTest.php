<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerChangeRequest;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5((string) $user->id), 0, 8)),
        ]);
    }

    private function makePrivateDocument(Customer $customer): Document
    {
        Storage::fake('local');
        Storage::disk('local')->put('contract_documents/' . $customer->id . '/police.pdf', '%PDF-1.4 test');
        return Document::create([
            'customer_id' => $customer->id,
            'category' => 'contract',
            'file_name' => 'police.pdf',
            'file_path' => 'contract_documents/' . $customer->id . '/police.pdf',
            'disk' => 'local',
        ]);
    }

    public function test_customer_can_download_own_contract_document(): void
    {
        $customer = $this->makeCustomer();
        $doc = $this->makePrivateDocument($customer);

        $this->actingAs($customer->user)
            ->get(route('portal.documents.download', $doc->id))
            ->assertOk()
            ->assertDownload('police.pdf');
    }

    public function test_customer_cannot_download_foreign_document(): void
    {
        $owner = $this->makeCustomer();
        $doc = $this->makePrivateDocument($owner);

        $attacker = $this->makeCustomer();
        $this->actingAs($attacker->user)
            ->get(route('portal.documents.download', $doc->id))
            ->assertNotFound();
    }

    public function test_unassigned_employee_cannot_download_via_admin_route(): void
    {
        $customer = $this->makeCustomer();
        $doc = $this->makePrivateDocument($customer);

        $unassigned = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $this->actingAs($unassigned)
            ->get(route('admin.documents.download', $doc->id))
            ->assertForbidden();

        // Zugewiesener Mitarbeiter und Admin dürfen
        $assigned = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $assigned->assignedCustomers()->attach((string) $customer->id);
        $this->actingAs($assigned)->get(route('admin.documents.download', $doc->id))->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.documents.download', $doc->id))->assertOk();
    }

    public function test_guest_cannot_download(): void
    {
        $doc = $this->makePrivateDocument($this->makeCustomer());
        $this->get(route('portal.documents.download', $doc->id))->assertRedirect(route('login'));
    }

    public function test_new_contract_uploads_are_stored_privately_not_publicly(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)->post(route('portal.contracts.report'), [
            'type' => 'kfz',
            'insurer' => 'HUK',
            'contract_number' => 'K-1',
            'document' => UploadedFile::fake()->create('vertrag.pdf', 200, 'application/pdf'),
        ])->assertSessionHas('success');

        $request = CustomerChangeRequest::first();
        $path = $request->new_data['document_path'];

        // Direkte öffentliche URL kann nicht funktionieren: Datei liegt
        // ausschließlich im privaten Storage, nicht unter public/storage.
        $this->assertSame('local', $request->new_data['document_disk']);
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);

        // Nach Genehmigung: Document-Datensatz zeigt auf die private Disk
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.change_requests.action', $request->id), ['action' => 'approve']);
        $this->assertDatabaseHas('documents', ['file_path' => $path, 'disk' => 'local']);
    }

    public function test_change_request_document_download_respects_portfolio(): void
    {
        Storage::fake('local');
        $customer = $this->makeCustomer();
        $this->actingAs($customer->user)->post(route('portal.contracts.report'), [
            'type' => 'kfz', 'insurer' => 'HUK',
            'document' => UploadedFile::fake()->create('vertrag.pdf', 100, 'application/pdf'),
        ]);
        $request = CustomerChangeRequest::first();

        $this->actingAs(User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]))
            ->get(route('admin.change_requests.document', $request->id))
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.change_requests.document', $request->id))
            ->assertOk();
    }

    public function test_internal_document_is_hidden_from_customer(): void
    {
        $customer = $this->makeCustomer();
        Storage::fake('local');
        Storage::disk('local')->put('customers/' . $customer->id . '/internal.pdf', 'x');
        $doc = Document::create([
            'customer_id' => $customer->id,
            'category' => 'other',
            'file_name' => 'intern.pdf',
            'file_path' => 'customers/' . $customer->id . '/internal.pdf',
            'disk' => 'local',
            'visibility' => 'internal',
        ]);

        // Nicht in der Portal-Liste ...
        $this->actingAs($customer->user)->get(route('portal.documents'))
            ->assertOk()->assertDontSee('intern.pdf');
        // ... und Download blockiert (404), obwohl es dem eigenen Kunden gehört
        $this->actingAs($customer->user)->get(route('portal.documents.download', $doc->id))
            ->assertNotFound();
    }

    public function test_customer_can_download_own_customer_visible_document(): void
    {
        $customer = $this->makeCustomer();
        $doc = $this->makePrivateDocument($customer); // visibility default 'customer'
        $this->actingAs($customer->user)->get(route('portal.documents.download', $doc->id))->assertOk();
    }

    public function test_multi_upload_stores_all_files_privately_with_audit(): void
    {
        Storage::fake('local');
        $customer = $this->makeCustomer();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.customer.document.store', $customer->id), [
            'documents' => [
                UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('b.pdf', 120, 'application/pdf'),
            ],
            'category' => 'police',
            'visibility' => 'internal',
        ])->assertSessionHas('success');

        $this->assertSame(2, Document::count());
        Document::all()->each(function ($d) {
            $this->assertSame('local', $d->disk);
            $this->assertSame('internal', $d->visibility);
            Storage::disk('local')->assertExists($d->file_path);
            Storage::disk('public')->assertMissing($d->file_path);
        });
        $this->assertDatabaseHas('activity_logs', ['action' => 'document_uploaded', 'user_id' => $admin->id]);
    }

    public function test_document_replace_sets_updated_by_and_audits(): void
    {
        Storage::fake('local');
        $customer = $this->makeCustomer();
        $doc = $this->makePrivateDocument($customer);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.documents.replace', $doc->id), [
            'document' => UploadedFile::fake()->create('neu.pdf', 100, 'application/pdf'),
        ])->assertSessionHas('success');

        $doc->refresh();
        $this->assertSame('neu.pdf', $doc->file_name);
        $this->assertSame($admin->id, $doc->updated_by);
        $this->assertSame('local', $doc->disk);
        $this->assertDatabaseHas('activity_logs', ['action' => 'document_replaced', 'user_id' => $admin->id]);
    }

    public function test_unassigned_employee_cannot_replace_document(): void
    {
        Storage::fake('local');
        $customer = $this->makeCustomer();
        $doc = $this->makePrivateDocument($customer);
        $unassigned = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);

        $this->actingAs($unassigned)->post(route('admin.documents.replace', $doc->id), [
            'document' => UploadedFile::fake()->create('x.pdf', 50, 'application/pdf'),
        ])->assertForbidden();
    }
}