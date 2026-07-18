<?php

namespace Tests\Feature\DocumentIntake;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Mehrere Eingangs-Dokumente (Ausweis + Bankkarte + Fuehrerschein +
 * Beratungsprotokoll) zu EINEM Kunden zusammenfuehren: Feld-Hoheit nach
 * Dokumenttyp, Namens-Abgleich Ausweis vs. Fuehrerschein.
 */
class DocumentMergeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function inboxDoc(string $type, array $extracted, string $name = 'x.pdf'): Document
    {
        Storage::fake('local');
        $path = 'documents/eingang/' . uniqid() . '.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4');
        return Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => $name,
            'file_path' => $path,
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => $type,
            'ai_extracted' => $extracted,
        ]);
    }

    public function test_merges_documents_into_one_customer(): void
    {
        // Ausweis: Name/Geburtsdatum/Adresse. Protokoll enthaelt bewusst einen
        // FALSCHEN Vornamen - der Ausweis muss gewinnen.
        $ausweis = $this->inboxDoc('personalausweis', ['person' => [
            'first_name' => 'Ahmed', 'last_name' => 'Nassar', 'birth_date' => '1999-08-15',
            'street' => 'Gartenstr.', 'house_number' => '6', 'zip' => '70806', 'city' => 'Kornwestheim',
        ]], 'ausweis.pdf');
        $bank = $this->inboxDoc('sonstiges', ['bank' => ['iban' => 'DE89370400440532013000']], 'bank.pdf');
        $protokoll = $this->inboxDoc('kfz_vertrag', [
            'person' => ['first_name' => 'Achmed', 'last_name' => 'Nassar'],
            'versicherung' => ['insurer' => 'HUK24', 'sparte' => 'kfz'],
            'kfz' => ['license_plate' => 'S-AB 1234', 'hsn' => '0603', 'tsn' => 'AMK'],
        ], 'protokoll.pdf');

        $response = $this->actingAs($this->admin())->postJson(route('admin.documents.create_customer_batch'), [
            'document_ids' => [$ausweis->id, $bank->id, $protokoll->id],
            'apply_fields' => ['birth_date', 'address', 'iban'],
            'create_contract' => true,
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'documents' => 3]);

        $customer = Customer::findOrFail($response->json('customer_id'));
        $this->assertSame('Ahmed Nassar', $customer->user->name);       // Ausweis gewinnt
        $this->assertSame('1999-08-15', (string) $customer->birth_date);
        $this->assertSame('70806', $customer->address_zip);
        $this->assertSame('DE89370400440532013000', $customer->iban);

        // Alle drei Dokumente wurden dem Kunden zugeordnet.
        foreach ([$ausweis, $bank, $protokoll] as $doc) {
            $this->assertSame((string) $customer->id, (string) $doc->fresh()->customer_id);
        }

        // Kfz-Vertrag wurde aus dem Protokoll angelegt.
        $contract = Contract::where('customer_id', $customer->id)->first();
        $this->assertNotNull($contract);
        $this->assertSame('kfz', $contract->type);
    }

    public function test_name_mismatch_between_id_and_license_blocks_creation(): void
    {
        $ausweis = $this->inboxDoc('personalausweis', ['person' => ['first_name' => 'Ahmed', 'last_name' => 'Nassar']]);
        $fuehrerschein = $this->inboxDoc('fuehrerschein', ['person' => ['first_name' => 'Ahmad', 'last_name' => 'Nasser']]);

        $response = $this->actingAs($this->admin())->postJson(route('admin.documents.create_customer_batch'), [
            'document_ids' => [$ausweis->id, $fuehrerschein->id],
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('name', $response->json('conflicts'));
        $this->assertSame(0, Customer::count());
        $this->assertNull($ausweis->fresh()->customer_id);
    }

    public function test_matching_names_on_id_and_license_are_ok(): void
    {
        $ausweis = $this->inboxDoc('personalausweis', ['person' => ['first_name' => 'Ahmed', 'last_name' => 'Nassar', 'birth_date' => '1999-08-15']]);
        // Fuehrerschein mit gleichem Namen (andere Gross-/Kleinschreibung/Leerzeichen).
        $fuehrerschein = $this->inboxDoc('fuehrerschein', ['person' => ['first_name' => 'AHMED ', 'last_name' => 'Nassar']]);

        $response = $this->actingAs($this->admin())->postJson(route('admin.documents.create_customer_batch'), [
            'document_ids' => [$ausweis->id, $fuehrerschein->id],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(1, Customer::count());
    }
}
