<?php
namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\LexofficeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regressionstests fuer die im E2E-Audit (2026-07-19) behobenen
 * Critical/High-Befunde. Referenzen: docs/SYSTEM_AUDIT_E2E_2026-07-19.md
 */
class AuditE2EFixesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function customerWithName(string $name): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5((string) $user->id), 0, 6)),
        ]);
    }

    // INT-1: CSV-Export neutralisiert Formel-Injection.
    public function test_csv_export_escapes_formula_injection(): void
    {
        $this->customerWithName('=SUM(1+9)');
        $body = $this->actingAs($this->admin())->get(route('admin.export'))
            ->assertOk()->getContent();

        // Der gefaehrliche Wert darf nicht unescaped als Zellenanfang auftauchen.
        $this->assertStringNotContainsString(',=SUM(1+9)', $body);
        // EscapeFormula stellt der Formel ein Schutzzeichen (') voran.
        $this->assertStringContainsString("'=SUM(1+9)", $body);
    }

    // INT-2: Die frueher fehlenden Lexoffice-Methoden existieren.
    public function test_lexoffice_service_has_invoice_methods(): void
    {
        $this->assertTrue(method_exists(LexofficeService::class, 'getInvoicePdf'));
        $this->assertTrue(method_exists(LexofficeService::class, 'sendInvoice'));
    }

    // INT-2: Rechnungs-Download liefert kein 500 mehr (Undefined method).
    public function test_lexoffice_invoice_download_does_not_500(): void
    {
        Http::fake([
            'api.lexware.io/v1/invoices/*/document' => Http::response(['documentFileId' => 'file-1'], 200),
            'api.lexware.io/v1/files/*' => Http::response('%PDF-1.4 fake', 200),
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.lexoffice.invoice.download', ['id' => 'inv-1']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    // UX-1/UX-2: Rollen-Select immer sichtbar + can_import_export-Control vorhanden.
    public function test_employee_edit_shows_role_and_import_permission(): void
    {
        $emp = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => true]);
        $res = $this->actingAs($this->admin())->get(route('admin.employees.edit', $emp->id))->assertOk();
        $res->assertSee('id="role"', false);
        $res->assertSee('name="can_import_export"', false);
    }

    // UX-4/UX-5: Die frueher undefinierten CSS-Klassen sind definiert.
    public function test_broken_css_classes_are_defined(): void
    {
        $res = $this->actingAs($this->admin())->get(route('admin.employees'))->assertOk();
        $res->assertSee('.badge-danger', false);
        $res->assertSee('.alert-warning', false);
    }

    // SEC-2: Nicht zugeordnetes Inbox-Dokument ist fuer fremde begrenzte
    // Mitarbeiter nicht abrufbar (IDOR), fuer den Uploader schon.
    public function test_inbox_document_idor_blocked_for_other_limited_staff(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('inbox/secret.pdf', '%PDF fake');

        $uploader = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $other = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);

        $doc = Document::create([
            'customer_id' => null,
            'uploaded_by' => $uploader->id,
            'category' => 'sonstiges',
            'file_name' => 'secret.pdf',
            'file_path' => 'inbox/secret.pdf',
            'disk' => 'local',
            'visibility' => 'intern',
        ]);

        $this->actingAs($other)->get(route('admin.documents.download', $doc->id))->assertForbidden();
        $this->actingAs($uploader)->get(route('admin.documents.download', $doc->id))->assertOk();
    }

    // SEC-1: HTML-Antworten tragen einen Content-Security-Policy-Header.
    public function test_csp_header_present_on_html_response(): void
    {
        $res = $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk();
        $csp = $res->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    // INT-8: Fehlgeschlagener Login landet im Audit-Trail (ohne Passwort).
    public function test_failed_login_is_audited(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->post('/login', ['email' => $user->email, 'password' => 'falsch-falsch']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'login_failed']);
    }

    // INT-8: Voll-Export der Kundendaten wird protokolliert.
    public function test_customer_export_is_audited(): void
    {
        $this->customerWithName('Max Muster');
        $this->actingAs($this->admin())->get(route('admin.export'))->assertOk();
        $this->assertDatabaseHas('activity_logs', ['action' => 'customers_exported']);
    }
}
