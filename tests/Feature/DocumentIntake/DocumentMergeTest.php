<?php

namespace Tests\Feature\DocumentIntake;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    public function test_files_uploaded_together_share_intake_batch(): void
    {
        Storage::fake('local');
        config(['services.anthropic.key' => '']); // keine Analyse noetig fuer diesen Test

        $response = $this->actingAs($this->admin())->postJson(route('admin.documents.smart_upload'), [
            'files' => [
                UploadedFile::fake()->create('ausweis.pdf', 40, 'application/pdf'),
                UploadedFile::fake()->create('protokoll.pdf', 40, 'application/pdf'),
            ],
        ]);

        $response->assertOk();
        $ids = $response->json('ids');
        $this->assertCount(2, $ids);
        $batches = Document::whereIn('id', $ids)->pluck('intake_batch')->unique();
        $this->assertCount(1, $batches);
        $this->assertNotNull($batches->first());
    }

    public function test_inbox_groups_batch_documents_as_one_vorgang(): void
    {
        Storage::fake('local');
        $batch = (string) \Illuminate\Support\Str::uuid();
        foreach ([['personalausweis', 'ausweis.pdf'], ['kfz_vertrag', 'protokoll.pdf']] as [$type, $name]) {
            $this->inboxDoc($type, ['person' => ['first_name' => 'Ahmed', 'last_name' => 'Nassar']], $name)
                ->update(['intake_batch' => $batch]);
        }

        $response = $this->actingAs($this->admin())->get(route('admin.documents.inbox'));

        $response->assertOk()
            ->assertSee('Ein Vorgang · 2 Dokumente', false)
            ->assertSee('Neuen Kunden aus allen 2 anlegen', false);
    }

    public function test_inbox_batch_with_name_conflict_shows_warning_instead_of_button(): void
    {
        Storage::fake('local');
        $batch = (string) \Illuminate\Support\Str::uuid();
        $this->inboxDoc('personalausweis', ['person' => ['first_name' => 'Ahmed', 'last_name' => 'Nassar']])
            ->update(['intake_batch' => $batch]);
        $this->inboxDoc('fuehrerschein', ['person' => ['first_name' => 'Ahmad', 'last_name' => 'Nasser']])
            ->update(['intake_batch' => $batch]);

        $response = $this->actingAs($this->admin())->get(route('admin.documents.inbox'));

        $response->assertOk()
            ->assertSee('stimmen nicht ueberein', false)
            ->assertDontSee('Neuen Kunden aus allen', false);
    }

    public function test_single_upload_gets_no_batch(): void
    {
        Storage::fake('local');
        config(['services.anthropic.key' => '']);

        $response = $this->actingAs($this->admin())->postJson(route('admin.documents.smart_upload'), [
            'files' => [UploadedFile::fake()->create('einzel.pdf', 40, 'application/pdf')],
        ]);

        $response->assertOk();
        $this->assertNull(Document::findOrFail($response->json('ids.0'))->intake_batch);
    }

    /**
     * Manuelle Mehrfachauswahl: der Mitarbeiter markiert getrennt hochgeladene
     * Dokumente (z.B. Ausweis-Vorder- und -Rueckseite) selbst und bekommt die
     * gleiche zusammengefuehrte Vorschau wie ein automatischer Vorgang.
     */
    public function test_batch_preview_merges_selected_documents(): void
    {
        $ausweis = $this->inboxDoc('personalausweis', ['person' => [
            'first_name' => 'Ahmed', 'last_name' => 'Nassar', 'birth_date' => '1999-08-15',
        ]], 'ausweis.pdf');
        $protokoll = $this->inboxDoc('kfz_vertrag', [
            'person' => ['first_name' => 'Ahmed', 'last_name' => 'Nassar'],
            'versicherung' => ['insurer' => 'HUK24', 'sparte' => 'kfz'],
        ], 'protokoll.pdf');

        $response = $this->actingAs($this->admin())->postJson(route('admin.documents.batch_preview'), [
            'document_ids' => [$ausweis->id, $protokoll->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('has_name', true)
            ->assertJsonPath('merged.person.first_name', 'Ahmed')
            ->assertJsonCount(2, 'ids')
            ->assertJsonCount(2, 'file_names');
    }

    public function test_batch_preview_reports_name_conflict(): void
    {
        $ausweis = $this->inboxDoc('personalausweis', ['person' => ['first_name' => 'Ahmed', 'last_name' => 'Nassar']]);
        $fuehrerschein = $this->inboxDoc('fuehrerschein', ['person' => ['first_name' => 'Ahmad', 'last_name' => 'Nasser']]);

        $response = $this->actingAs($this->admin())->postJson(route('admin.documents.batch_preview'), [
            'document_ids' => [$ausweis->id, $fuehrerschein->id],
        ]);

        // Die Vorschau selbst liefert 200 mit den Konflikten (die UI warnt),
        // das eigentliche Anlegen (create_customer_batch) blockt dann mit 422.
        $response->assertOk();
        $this->assertNotEmpty($response->json('conflicts'));
    }

    public function test_batch_preview_rejects_already_assigned_document(): void
    {
        $doc = $this->inboxDoc('personalausweis', ['person' => ['first_name' => 'Ahmed', 'last_name' => 'Nassar']]);
        $user = User::factory()->create(['role' => 'customer']);
        $customer = Customer::create(['user_id' => $user->id, 'customer_number' => 'C-ASSIGNED']);
        $doc->update(['customer_id' => $customer->id]);
        $other = $this->inboxDoc('kfz_vertrag', ['person' => ['first_name' => 'Ahmed', 'last_name' => 'Nassar']]);

        $this->actingAs($this->admin())->postJson(route('admin.documents.batch_preview'), [
            'document_ids' => [$doc->id, $other->id],
        ])->assertStatus(422);
    }

    public function test_inbox_shows_selection_checkbox_and_view_button(): void
    {
        $this->inboxDoc('personalausweis', ['person' => ['first_name' => 'Ahmed', 'last_name' => 'Nassar']], 'ausweis.pdf');

        $response = $this->actingAs($this->admin())->get(route('admin.documents.inbox'));

        $response->assertOk()
            ->assertSee('inbox-select', false)                 // Mehrfachauswahl-Checkbox
            ->assertSee('inbox-selection-bar', false)          // Aktionsleiste
            ->assertSee('Anzeigen', false);                    // Anzeigen-Button
    }

    public function test_inbox_shows_view_button_for_failed_document(): void
    {
        $doc = $this->inboxDoc('sonstiges', [], 'scan.pdf');
        $doc->update(['ai_status' => 'failed', 'ai_error' => 'Analyse kaputt']);

        $response = $this->actingAs($this->admin())->get(route('admin.documents.inbox'));

        // Auch ein fehlgeschlagenes Dokument laesst sich ansehen (der
        // Mitarbeiter kann sonst nicht erkennen, um welche Datei es geht).
        $response->assertOk()
            ->assertSee('Analyse fehlgeschlagen', false)
            ->assertSee('Anzeigen', false);
    }
}
