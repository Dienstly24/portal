<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\CustomerCreation\CustomerAutoCreationService;
use App\Services\CustomerCreation\DuplicateCustomerException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAutoCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CustomerAutoCreationService
    {
        return app(CustomerAutoCreationService::class);
    }

    public function test_creates_customer_with_unique_number_and_source(): void
    {
        $customer = $this->service()->createFromUnmatched([
            'full_name' => 'Neuer Kunde',
            'email' => 'neu@example.com',
            'birth_date' => '1995-08-20',
        ], 'email_import');

        $this->assertNotNull($customer->id);
        $this->assertMatchesRegularExpression('/^\d{2}\d{5}$/', $customer->customer_number);
        $this->assertSame('email_import', $customer->source);
        $this->assertSame('neu@example.com', $customer->user->email);
        $this->assertSame('customer', $customer->user->role);
    }

    public function test_rejects_unknown_source(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->createFromUnmatched(['full_name' => 'X'], 'made_up_source');
    }

    public function test_generates_placeholder_email_when_none_known(): void
    {
        $customer = $this->service()->createFromUnmatched([
            'full_name' => 'Ohne Email',
            'birth_date' => '1960-01-01',
        ], 'fonds_finanz');

        $this->assertStringEndsWith('@dienstly24.internal', $customer->user->email);
    }

    public function test_refuses_to_create_duplicate_when_strong_match_exists(): void
    {
        $existingUser = User::factory()->create(['role' => 'customer', 'name' => 'Bestehender Kunde', 'email' => 'bestehend@example.com']);
        Customer::create([
            'user_id' => $existingUser->id,
            'customer_number' => 'C-EXISTING',
            'birth_date' => '1990-01-01',
            'phone' => '030999999',
        ]);

        $this->expectException(DuplicateCustomerException::class);

        // Alle Signale stimmen überein -> das ist genau der Fall, den der
        // Aufrufer (Workflow-Engine) eigentlich vorher hätte abfangen sollen.
        $this->service()->createFromUnmatched([
            'full_name' => 'Bestehender Kunde',
            'email' => 'bestehend@example.com',
            'birth_date' => '1990-01-01',
            'phone' => '030999999',
        ], 'email_import');
    }

    public function test_attach_documents_links_existing_documents_to_new_customer(): void
    {
        $orphanCustomer = $this->service()->createFromUnmatched(['full_name' => 'Platzhalter'], 'manual');
        $document = Document::create([
            'customer_id' => $orphanCustomer->id,
            'category' => 'other',
            'file_name' => 'test.pdf',
            'file_path' => 'path/test.pdf',
            'disk' => 'local',
        ]);

        $newCustomer = $this->service()->createFromUnmatched([
            'full_name' => 'Neuer Zielkunde',
            'email' => 'ziel@example.com',
        ], 'fonds_finanz');

        $this->service()->attachDocuments($newCustomer, [$document]);

        $this->assertSame((string) $newCustomer->id, (string) $document->fresh()->customer_id);
    }

    public function test_number_generator_produces_unique_numbers(): void
    {
        $generator = app(\App\Services\CustomerNumberGenerator::class);

        // Jahresbasierte Sequenz: jede vergebene (persistierte) Nummer
        // erhöht den Zähler; Format JJ + 5-stellig (z. B. 2600001).
        $numbers = [];
        foreach (range(1, 20) as $i) {
            $n = $generator->generate();
            $numbers[] = $n;
            $u = \App\Models\User::factory()->create(['role' => 'customer']);
            \App\Models\Customer::create(['user_id' => $u->id, 'customer_number' => $n]);
        }

        $this->assertCount(20, array_unique($numbers));
        foreach ($numbers as $n) {
            $this->assertMatchesRegularExpression('/^\d{2}\d{5}$/', $n);
        }
    }
}
