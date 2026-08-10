<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GET /admin/documents/{id} ist keine Detailseite, sondern eine Weiche:
 * solche Aufrufe entstehen ueber den Browser-Verlauf (Formular-Action des
 * Bearbeiten-/Loeschen-Dialogs), alte Lesezeichen oder gekuerzte
 * Download-Links und liefen frueher ins 404. Jetzt wird zum richtigen Ort
 * weitergeleitet (Kundenakte bzw. Dokumenten-Eingang).
 */
class DocumentShowRedirectTest extends TestCase
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

    private function makeDocument(?Customer $customer, ?int $uploadedBy = null): Document
    {
        Storage::fake('local');
        Storage::disk('local')->put('docs/test.pdf', '%PDF-1.4 test');
        return Document::create([
            'customer_id' => $customer?->id,
            'category' => 'other',
            'file_name' => 'test.pdf',
            'file_path' => 'docs/test.pdf',
            'disk' => 'local',
            'uploaded_by' => $uploadedBy,
        ]);
    }

    public function test_assigned_document_link_redirects_to_customer_file(): void
    {
        $customer = $this->makeCustomer();
        $doc = $this->makeDocument($customer);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/admin/documents/' . $doc->id)
            ->assertRedirect(route('admin.customer', $customer->id) . '#tab-dokumente');
    }

    public function test_unassigned_inbox_document_link_redirects_to_inbox(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $doc = $this->makeDocument(null, $admin->id);

        $this->actingAs($admin)
            ->get('/admin/documents/' . $doc->id)
            ->assertRedirect(route('admin.documents.inbox'));
    }

    public function test_unknown_document_link_redirects_to_inbox_with_warning(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/admin/documents/9a8cd652-ca9a-4526-8b2e-fbf8802647d7')
            ->assertRedirect(route('admin.documents.inbox'))
            ->assertSessionHas('warning');
    }

    public function test_foreign_customer_document_link_is_forbidden_for_limited_employee(): void
    {
        $customer = $this->makeCustomer();
        $doc = $this->makeDocument($customer);
        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);

        $this->actingAs($employee)
            ->get('/admin/documents/' . $doc->id)
            ->assertForbidden();
    }

    public function test_foreign_inbox_document_link_is_forbidden_for_limited_employee(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $doc = $this->makeDocument(null, $admin->id);
        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);

        // Spiegelt authorizeDocumentAccess: Inbox-Dokumente sieht ein
        // portfolio-begrenzter Mitarbeiter nur, wenn er sie selbst hochlud.
        $this->actingAs($employee)
            ->get('/admin/documents/' . $doc->id)
            ->assertForbidden();

        $this->actingAs($admin)
            ->get('/admin/documents/' . $doc->id)
            ->assertRedirect(route('admin.documents.inbox'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $customer = $this->makeCustomer();
        $doc = $this->makeDocument($customer);

        $this->get('/admin/documents/' . $doc->id)->assertRedirect(route('login'));
    }

    public function test_fixed_segment_routes_still_match_before_the_id_wildcard(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Die Kundensuche (fester Pfad /documents/customer-search) darf nicht
        // vom neuen {id}-Wildcard geschluckt werden - Reihenfolge der Routen.
        $this->actingAs($admin)
            ->getJson('/admin/documents/customer-search?q=abc')
            ->assertOk();
    }
}
