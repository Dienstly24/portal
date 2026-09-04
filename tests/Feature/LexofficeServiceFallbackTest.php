<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LexofficeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class LexofficeServiceFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Retry-Wartezeit ueberspringen, damit die Verbindungsfehler-Tests
        // nicht real schlafen (retry(2, 500)).
        Sleep::fake();
    }

    public function test_get_contacts_returns_fallback_on_http_error(): void
    {
        Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)]);

        $data = app(LexofficeService::class)->getContacts();

        $this->assertSame([], $data['content']);
        $this->assertSame(0, $data['totalElements']);
    }

    public function test_create_voucher_returns_null_on_http_error(): void
    {
        Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)]);

        $voucher = app(LexofficeService::class)->createVoucher(['type' => 'salesinvoice']);

        $this->assertNull($voucher);
    }

    public function test_contacts_page_survives_lexoffice_http_error(): void
    {
        Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)]);

        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get('/admin/lexoffice/contacts');

        $response->assertOk();
    }

    // ===== Verbindungs-Fehler (Host nicht erreichbar) =====
    // throw:false unterdrueckt nur HTTP-Status-Fehler; ein nicht erreichbarer
    // Host wirft eine ConnectionException, die frueher ungefiltert zu HTTP 500
    // auf jeder Lexoffice-Seite und auf dem Geld-Pfad wurde (Audit INT-5).

    public function test_get_contacts_returns_fallback_on_connection_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $data = app(LexofficeService::class)->getContacts();

        $this->assertSame([], $data['content']);
        $this->assertSame(0, $data['totalElements']);
    }

    public function test_create_voucher_returns_null_on_connection_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $voucher = app(LexofficeService::class)->createVoucher(['type' => 'salesinvoice']);

        $this->assertNull($voucher);
    }

    public function test_financial_summary_survives_connection_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $summary = app(LexofficeService::class)->getFinancialSummary();

        $this->assertSame(0, $summary['open_count']);
        $this->assertSame(0, $summary['total_invoices']);
        $this->assertSame(0, $summary['open_amount']);
    }

    public function test_contacts_page_survives_lexoffice_connection_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get('/admin/lexoffice/contacts');

        $response->assertOk();
    }

    public function test_invoices_page_survives_lexoffice_connection_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get('/admin/lexoffice/invoices');

        $response->assertOk();
    }
}
