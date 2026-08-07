<?php

namespace Tests\Feature;

use App\Services\LexofficeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LexofficeServiceFallbackTest extends TestCase
{
    use RefreshDatabase;

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

        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get('/admin/lexoffice/contacts');

        $response->assertOk();
    }
}
