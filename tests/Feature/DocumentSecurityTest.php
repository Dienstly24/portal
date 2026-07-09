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
}
