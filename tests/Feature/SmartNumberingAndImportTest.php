<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerChangeRequest;
use App\Models\CustomerFamily;
use App\Models\User;
use App\Services\CustomerNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
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

    /**
     * Zweistufiger Import: erst Vorschau hochladen (legt NICHTS an), dann mit
     * dem Vorschau-Token bestaetigen (legt die Kunden an). Gibt die Antwort
     * des Bestaetigungsschritts zurueck.
     */
    private function importCsv(string $content, string $filename = 'kunden.csv')
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $file = UploadedFile::fake()->createWithContent($filename, $content);

        $preview = $this->actingAs($admin)->post(route('admin.import'), ['csv_file' => $file]);
        $preview->assertOk();
        $token = $preview->viewData('token');

        return $this->actingAs($admin)->post(route('admin.import.confirm'), ['token' => $token]);
    }

    /** Nur die Vorschau (Schritt 1) ausfuehren und die View-Daten zurueckgeben. */
    private function previewCsv(string $content): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $file = UploadedFile::fake()->createWithContent('kunden.csv', $content);

        $preview = $this->actingAs($admin)->post(route('admin.import'), ['csv_file' => $file]);
        $preview->assertOk();

        return $preview->viewData('preview');
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

    public function test_csv_import_reads_semicolon_latin1_and_numbered_columns(): void
    {
        // Reproduziert den frueher kaputten Fremdexport: Windows-1252,
        // Semikolon-Trennung, nummerierte Spalten (E-Mail 1, Telefon 1 ...).
        // Frueher wurde die E-Mail nicht erkannt -> Platzhalter-Adresse.
        $utf8 = implode("\n", [
            'Kundennummer;Firmenname;Anrede;Vorname;Nachname;Straße 1;PLZ 1;Ort 1;Telefon 1;E-Mail 1',
            '14017;;Herr;Mohamad;Abbas;Warthestr. 4 A;47169;Duisburg;017634963362;mhd.modar@example.com',
        ]);
        $latin1 = mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');

        $this->importCsv($latin1, 'kontakte.csv')->assertRedirect();

        $customer = Customer::first();
        $this->assertNotNull($customer);
        // Kern der Sache: echte E-Mail landet am User, KEINE Platzhalteradresse.
        $this->assertSame('mhd.modar@example.com', $customer->user->email);
        $this->assertStringNotContainsString('@dienstly24.internal', $customer->user->email);
        $this->assertSame('Mohamad Abbas', $customer->user->name);
        $this->assertSame('2514017', $customer->customer_number);
        $this->assertSame('Warthestr. 4 A', $customer->address_street);
        $this->assertSame('47169', $customer->address_zip);
        $this->assertSame('Duisburg', $customer->address_city);
        $this->assertSame('017634963362', $customer->phone);
        $this->assertSame('male', $customer->gender);
    }

    public function test_csv_import_uses_company_name_when_person_name_missing(): void
    {
        $content = implode("\n", [
            'Kundennummer;Firmenname;Vorname;Nachname;E-Mail 1',
            '20001;Beispiel GmbH;;;kontakt@beispiel-gmbh.de',
        ]);

        $this->importCsv($content, 'firma.csv')->assertRedirect();

        $customer = Customer::first();
        $this->assertNotNull($customer);
        $this->assertSame('Beispiel GmbH', $customer->user->name);
        $this->assertSame('firma', $customer->customer_type);
        $this->assertSame('kontakt@beispiel-gmbh.de', $customer->user->email);
    }

    public function test_import_preview_does_not_create_customers(): void
    {
        // Schritt 1 (Vorschau) darf noch NICHTS anlegen.
        $preview = $this->previewCsv(implode("\n", [
            'Vorname;Nachname;E-Mail 1;Kundennummer',
            'Anna;Beispiel;anna@k.de;4711',
            'Ben;Muster;ben@k.de;4712',
            ';;;', // ohne Namen -> uebersprungen
        ]));

        $this->assertSame(0, Customer::count()); // nichts angelegt
        $this->assertSame(2, $preview['new_count']);
        $this->assertSame(1, $preview['skipped']);
        $this->assertSame('anna@k.de', $preview['new'][0]['email']);
    }

    public function test_import_preview_flags_in_file_duplicate_email(): void
    {
        $preview = $this->previewCsv(implode("\n", [
            'Vorname;Nachname;E-Mail 1',
            'Anna;Beispiel;dup@k.de',
            'Anna;Beispiel;dup@k.de', // gleiche E-Mail zweimal in der Datei
        ]));

        $this->assertSame(1, $preview['new_count']);
        $this->assertSame(1, $preview['dup_count']);
    }

    public function test_confirm_dispatches_background_job(): void
    {
        \Illuminate\Support\Facades\Bus::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $file = UploadedFile::fake()->createWithContent('kunden.csv', implode("\n", [
            'Vorname;Nachname;E-Mail 1',
            'Anna;Beispiel;anna@k.de',
        ]));

        $preview = $this->actingAs($admin)->post(route('admin.import'), ['csv_file' => $file]);
        $token = $preview->viewData('token');

        $this->actingAs($admin)->post(route('admin.import.confirm'), ['token' => $token])
            ->assertRedirect(route('admin.import_export'));

        // Import laeuft im Hintergrund, nicht synchron im Web-Request.
        Bus::assertDispatched(\App\Jobs\ImportCustomersJob::class);
    }

    public function test_import_notifies_user_when_finished(): void
    {
        // Sync-Queue in Tests -> Job laeuft inline; Benachrichtigung entsteht.
        $this->importCsv(implode("\n", [
            'Vorname;Nachname;E-Mail 1',
            'Anna;Beispiel;anna@k.de',
        ]))->assertRedirect();

        $this->assertSame(1, Customer::count());
        $this->assertDatabaseHas('internal_notifications', [
            'title' => 'Kunden-Import abgeschlossen',
        ]);
    }

    public function test_confirm_import_with_expired_token_shows_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('admin.import.confirm'), ['token' => '00000000-0000-0000-0000-000000000000'])
            ->assertRedirect(route('admin.import_export'));

        $this->assertSame(0, Customer::count());
    }

    public function test_cleanup_command_removes_only_placeholder_import_customers(): void
    {
        // Kaputter Import: Quelle 'import' + Platzhalter-E-Mail -> muss weg.
        $broken = $this->makeCustomer('import-abc@dienstly24.internal', ['source' => 'import']);
        // Guter Import: echte E-Mail -> bleibt.
        $good = $this->makeCustomer('echt@k.de', ['source' => 'import']);
        // Platzhalter, aber andere Quelle -> bleibt (kein CSV-Import-Fehler).
        $fonds = $this->makeCustomer('import-xyz@dienstly24.internal', ['source' => 'fonds_finanz']);

        $this->artisan('customers:cleanup-import --force')
            ->expectsConfirmation('Wirklich diese 1 Import-Datensaetze endgueltig loeschen?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseMissing('customers', ['id' => $broken->id]);
        $this->assertDatabaseHas('customers', ['id' => $good->id]);
        $this->assertDatabaseHas('customers', ['id' => $fonds->id]);
    }

    public function test_cleanup_command_dry_run_deletes_nothing(): void
    {
        $broken = $this->makeCustomer('import-abc@dienstly24.internal', ['source' => 'import']);

        $this->artisan('customers:cleanup-import')->assertSuccessful();

        $this->assertDatabaseHas('customers', ['id' => $broken->id]);
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
