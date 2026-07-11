<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ExternalReference;
use App\Models\SystemSetting;
use App\Services\Lexoffice\LexofficeContactMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LexofficeImportTest extends TestCase
{
    use RefreshDatabase;

    private function contact(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'lx-' . uniqid(),
            'roles' => ['customer' => ['number' => 10001]],
            'person' => ['firstName' => 'Max', 'lastName' => 'Mustermann'],
            'emailAddresses' => ['office' => ['max@office.de']],
            'phoneNumbers' => ['mobile' => ['+49 170 1234567']],
            'addresses' => ['billing' => [['street' => 'Keplerstr. 32a', 'zip' => '75433', 'city' => 'Maulbronn']]],
            'note' => 'Geburtstag 15.02.1985, IBAN DE89 3704 0044 0532 0130 00',
        ], $overrides);
    }

    public function test_mapper_reads_all_fields_correctly(): void
    {
        $data = app(LexofficeContactMapper::class)->map($this->contact());

        $this->assertSame('Max Mustermann', $data['full_name']);
        $this->assertSame('max@office.de', $data['email']); // 'office'-Kübel wird gelesen
        $this->assertSame('privat', $data['customer_type']);
        $this->assertSame('1985-02-15', $data['birth_date']);
        $this->assertSame('DE89370400440532013000', $data['iban']);
        $this->assertSame('Keplerstr.', $data['street']);
        $this->assertSame('32a', $data['house_number']);
        $this->assertSame('75433', $data['zip']);
        $this->assertSame('Maulbronn', $data['city']);

        $types = array_column($data['external_references'], 'type');
        $this->assertContains('lexoffice_number', $types);
        $this->assertContains('lexoffice_id', $types);
    }

    public function test_company_contact_is_typed_as_firma(): void
    {
        $data = app(LexofficeContactMapper::class)->map($this->contact([
            'person' => null,
            'company' => ['name' => 'ACME GmbH'],
        ]));

        $this->assertSame('firma', $data['customer_type']);
        $this->assertSame('ACME GmbH', $data['company_name']);
    }

    public function test_contact_without_name_is_skipped(): void
    {
        $this->assertNull(app(LexofficeContactMapper::class)->map([
            'id' => 'x', 'person' => ['firstName' => '', 'lastName' => ''],
        ]));
    }

    public function test_import_creates_customer_with_external_reference_and_internal_number(): void
    {
        SystemSetting::set('lexoffice_api_key', 'test-key');
        Http::fake([
            '*/contacts*' => Http::response([
                'content' => [$this->contact()],
                'totalPages' => 1,
            ], 200),
        ]);

        $this->artisan('lexoffice:import')->assertExitCode(0);

        $this->assertSame(1, Customer::count());
        $customer = Customer::first();

        // Interne Nummer (C-...), NICHT die Lexoffice-Nummer
        $this->assertStringStartsWith('C-', $customer->customer_number);
        $this->assertSame('lexoffice', $customer->source);
        $this->assertSame('1985-02-15', $customer->birth_date);
        // IBAN verschlüsselt gespeichert, aber lesbar
        $this->assertSame('DE89370400440532013000', $customer->iban);

        // Lexoffice-Nummer als externe Referenz
        $this->assertDatabaseHas('external_references', [
            'referenceable_id' => $customer->id,
            'type' => 'lexoffice_number',
            'value' => '10001',
        ]);
    }

    public function test_import_deduplicates_against_existing_customer(): void
    {
        SystemSetting::set('lexoffice_api_key', 'test-key');

        // Bereits vorhandener Kunde mit gleichem Geburtsdatum + Namen
        $user = \App\Models\User::factory()->create(['role' => 'customer', 'name' => 'Max Mustermann', 'email' => 'max@office.de']);
        Customer::create(['user_id' => $user->id, 'customer_number' => 'C-EXISTING', 'birth_date' => '1985-02-15']);

        Http::fake([
            '*/contacts*' => Http::response(['content' => [$this->contact()], 'totalPages' => 1], 200),
        ]);

        $this->artisan('lexoffice:import')->assertExitCode(0);

        // Kein Duplikat angelegt
        $this->assertSame(1, Customer::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        SystemSetting::set('lexoffice_api_key', 'test-key');
        Http::fake([
            '*/contacts*' => Http::response(['content' => [$this->contact()], 'totalPages' => 1], 200),
        ]);

        $this->artisan('lexoffice:import', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, Customer::count());
        $this->assertSame(0, ExternalReference::count());
    }
}
