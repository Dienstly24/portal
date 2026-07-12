<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerChangeRequest;
use App\Models\CustomerFamily;
use App\Models\User;
use App\Services\CustomerNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SmartNumberingAndImportTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(string $email, array $attrs = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => $email]);
        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => $attrs['customer_number'] ?? 'C-' . strtoupper(substr(md5($email), 0, 8)),
        ], $attrs));
    }

    // ------------------------------------------------------------------
    // Kundennummern-Schema
    // ------------------------------------------------------------------

    public function test_new_customer_numbers_are_year_based_and_sequential(): void
    {
        $gen = app(CustomerNumberGenerator::class);
        $yy = now()->format('y');

        $first = $gen->generate();
        $this->assertSame($yy . '00001', $first);

        // Nummer "verbrauchen" und nächste prüfen
        $this->makeCustomer('n1@k.de', ['customer_number' => $first]);
        $this->assertSame($yy . '00002', $gen->generate());
    }

    public function test_sequence_ignores_legacy_and_import_numbers(): void
    {
        $gen = app(CustomerNumberGenerator::class);
        $yy = now()->format('y');

        $this->makeCustomer('legacy@k.de', ['customer_number' => 'C-ABCD1234']);
        $this->makeCustomer('import@k.de', ['customer_number' => '251234']); // Import, andere Länge
        $this->makeCustomer('seq@k.de', ['customer_number' => $yy . '00007']);

        $this->assertSame($yy . '00008', $gen->generate());
    }

    public function test_import_number_keeps_original_with_25_prefix(): void
    {
        $gen = app(CustomerNumberGenerator::class);

        $this->assertSame('251234', $gen->generateForImport('1234'));
        $this->assertSame('25LEX99', $gen->generateForImport('LEX-99')); // Sonderzeichen entfernt
    }

    public function test_import_number_collision_gets_suffix(): void
    {
        $this->makeCustomer('taken@k.de', ['customer_number' => '251234']);

        $this->assertSame('251234-2', app(CustomerNumberGenerator::class)->generateForImport('1234'));
    }

    public function test_import_without_original_number_falls_back_to_sequence(): void
    {
        $number = app(CustomerNumberGenerator::class)->generateForImport(null);
        $this->assertMatchesRegularExpression('/^\d{2}\d{5}$/', $number);
    }

    // ------------------------------------------------------------------
    // CSV-Import (andere Plattformen)
    // ------------------------------------------------------------------

    private function importCsv(string $content)
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $file = UploadedFile::fake()->createWithContent('kunden.csv', $content);

        return $this->actingAs($admin)->post(route('admin.import'), ['csv_file' => $file]);
    }

    public function test_csv_import_keeps_original_number_and_structured_address(): void
    {
        $this->importCsv(implode("\n", [
            'Vorname,Nachname,E-Mail,Kundennummer,Geburtsdatum,Straße,Nr,PLZ,Ort,IBAN',
            'Anna,Beispiel,anna@k.de,4711,15.03.1990,Hauptstr.,12,10115,Berlin,DE89370400440532013000',
        ]))->assertRedirect();

        $customer = Customer::first();
        $this->assertNotNull($customer);
        $this->assertSame('254711', $customer->customer_number); // 25 + Original
        $this->assertSame('import', $customer->source);
        $this->assertSame('1990-03-15', $customer->birth_date);
        $this->assertSame('Hauptstr.', $customer->address_street);
        $this->assertSame('10115', $customer->address_zip);
        $this->assertSame('DE89370400440532013000', $customer->iban);

        // Originalnummer zusätzlich als externe Referenz nachvollziehbar
        $this->assertDatabaseHas('external_references', [
            'referenceable_id' => $customer->id,
            'type' => 'import_number',
            'value' => '4711',
        ]);
    }

    public function test_csv_import_creates_customer_without_email(): void
    {
        $this->importCsv(implode("\n", [
            'Vorname,Nachname,E-Mail',
            'Ohne,Mail,',
        ]))->assertRedirect();

        $this->assertSame(1, Customer::count());
        $this->assertStringContainsString('@dienstly24.internal', Customer::first()->user->email);
    }

    public function test_csv_import_deduplicates_by_matching_not_only_email(): void
    {
        // Bestand: gleicher Name + Geburtsdatum, aber ANDERE E-Mail
        $existing = $this->makeCustomer('alt@k.de', ['birth_date' => '1990-03-15']);
        $existing->user->update(['name' => 'Anna Beispiel']);

        $this->importCsv(implode("\n", [
            'Vorname,Nachname,E-Mail,Geburtsdatum',
            'Anna,Beispiel,neu@k.de,15.03.1990',
        ]))->assertRedirect();

        $this->assertSame(1, Customer::count()); // kein Duplikat
    }

    // ------------------------------------------------------------------
    // Familienmitglied löschen (Kunde beantragt, Admin genehmigt)
    // ------------------------------------------------------------------

    public function test_customer_can_request_family_deletion_and_admin_approval_deletes(): void
    {
        $customer = $this->makeCustomer('fam@k.de');
        $member = CustomerFamily::create(['customer_id' => $customer->id, 'name' => 'Kind Eins', 'relation' => 'kind']);

        // 1) Kunde beantragt Löschung -> Mitglied bleibt zunächst bestehen
        $this->actingAs($customer->user)->post(route('portal.family.delete', $member->id))
            ->assertRedirect();

        $this->assertDatabaseHas('customer_family', ['id' => $member->id]);
        $request = CustomerChangeRequest::where('customer_id', $customer->id)->where('status', 'pending')->first();
        $this->assertNotNull($request);
        $this->assertTrue((bool) ($request->new_data['delete'] ?? false));

        // 2) Admin genehmigt -> Mitglied wird gelöscht
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.change_requests.action', $request->id), [
            'action' => 'approve',
        ])->assertRedirect();

        $this->assertDatabaseMissing('customer_family', ['id' => $member->id]);
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_customer_cannot_request_deletion_of_foreign_family_member(): void
    {
        $mine = $this->makeCustomer('mine2@k.de');
        $other = $this->makeCustomer('other2@k.de');
        $foreign = CustomerFamily::create(['customer_id' => $other->id, 'name' => 'Fremd', 'relation' => 'kind']);

        $this->actingAs($mine->user)->post(route('portal.family.delete', $foreign->id))
            ->assertNotFound();

        $this->assertDatabaseHas('customer_family', ['id' => $foreign->id]);
    }
}
