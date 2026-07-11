<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_iban_is_encrypted_at_rest_but_readable_via_model(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $customer = Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'K-' . uniqid(),
            'iban' => 'DE89370400440532013000',
        ]);

        // Über das Model transparent lesbar (entschlüsselt)
        $this->assertSame('DE89370400440532013000', $customer->fresh()->iban);

        // In der Datenbank NICHT im Klartext
        $raw = DB::table('customers')->where('id', $customer->id)->value('iban');
        $this->assertNotSame('DE89370400440532013000', $raw);
        $this->assertStringNotContainsString('DE8937040044', (string) $raw);
    }

    public function test_security_headers_are_present(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy');
    }

    public function test_customer_list_is_paginated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(30)->create(['role' => 'customer'])->each(function ($u) {
            Customer::create(['user_id' => $u->id, 'customer_number' => 'K-' . uniqid()]);
        });

        $response = $this->actingAs($admin)->get(route('admin.customers'));
        $response->assertOk();

        $customers = $response->viewData('customers');
        $this->assertSame(25, $customers->count(), 'Pro Seite sollen 25 Kunden geladen werden.');
        $this->assertSame(30, $customers->total());
        $response->assertSee('Weiter'); // Pager sichtbar
    }
}
