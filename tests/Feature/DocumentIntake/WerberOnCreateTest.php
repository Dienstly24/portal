<?php

namespace Tests\Feature\DocumentIntake;

use App\Models\Customer;
use App\Models\Document;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Geworben von" direkt beim Anlegen eines Kunden aus dem Dokumenten-Eingang
 * (Review-Modal): Werber-Schluessel 'u:{id}'/'p:{uuid}' wird auf den neuen
 * Kunden angewendet - nur fuer Verwaltung, ungueltige Werte ohne Wirkung.
 */
class WerberOnCreateTest extends TestCase
{
    use RefreshDatabase;

    private function inboxDoc(array $extracted, ?int $uploadedBy = null): Document
    {
        Storage::fake('local');
        $path = 'documents/eingang/'.uniqid().'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4');
        return Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'scan.pdf',
            'file_path' => $path,
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'personalausweis',
            'ai_extracted' => $extracted,
            'uploaded_by' => $uploadedBy,
        ]);
    }

    public function test_admin_setzt_mitarbeiter_werber_beim_anlegen_aus_dokument(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $werber = User::factory()->create(['role' => 'employee']);
        $doc = $this->inboxDoc(['person' => ['first_name' => 'Ahmed', 'last_name' => 'Nassar']]);

        $response = $this->actingAs($admin)->postJson(route('admin.documents.create_customer', $doc->id), [
            'werber' => 'u:'.$werber->id,
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $customer = Customer::findOrFail($response->json('customer_id'));
        $this->assertSame($werber->id, $customer->acquired_by);
        $this->assertNull($customer->acquired_by_partner_id);
        // Anleger wird weiterhin automatisch festgehalten.
        $this->assertSame($admin->id, $customer->created_by);
    }

    public function test_admin_setzt_partner_werber_im_batch(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $partner = Partner::create(['name' => 'Fonds Finanz']);
        $doc = $this->inboxDoc(['person' => ['first_name' => 'Sara', 'last_name' => 'Omar']]);

        $response = $this->actingAs($admin)->postJson(route('admin.documents.create_customer_batch'), [
            'document_ids' => [$doc->id],
            'werber' => 'p:'.$partner->id,
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $customer = Customer::findOrFail($response->json('customer_id'));
        $this->assertSame($partner->id, $customer->acquired_by_partner_id);
        $this->assertNull($customer->acquired_by);
    }

    public function test_mitarbeiter_werber_angabe_wird_ignoriert(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $doc = $this->inboxDoc(
            ['person' => ['first_name' => 'Tarek', 'last_name' => 'Ali']],
            uploadedBy: $employee->id,
        );

        $response = $this->actingAs($employee)->postJson(route('admin.documents.create_customer', $doc->id), [
            'werber' => 'u:'.$employee->id,
        ]);

        $response->assertOk();
        $customer = Customer::findOrFail($response->json('customer_id'));
        // Kunde entsteht, aber der Werber-Wunsch eines Mitarbeiters greift nicht.
        $this->assertNull($customer->acquired_by);
        $this->assertNull($customer->acquired_by_partner_id);
    }

    public function test_ungueltiger_werber_schluessel_blockiert_anlage_nicht(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $doc = $this->inboxDoc(['person' => ['first_name' => 'Mona', 'last_name' => 'Karim']]);

        $response = $this->actingAs($admin)->postJson(route('admin.documents.create_customer', $doc->id), [
            'werber' => 'u:999999',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $customer = Customer::findOrFail($response->json('customer_id'));
        $this->assertNull($customer->acquired_by);
    }
}
