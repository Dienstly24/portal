<?php

namespace Tests\Feature\DocumentIntake;

use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Betreiber-Meldung 20.11.2026: Ein Bestandskunde bringt neue Unterlagen
 * (Gesundheitskarte, Bankkarte, Aufenthaltstitel, Lohnabrechnung). Im
 * Eingang gab es fuer den Vorgang nur "Neuen Kunden anlegen" - das scheiterte
 * am Dubletten-Schutz mit "Es existiert bereits ein aehnlicher Kunde ...
 * Bitte stattdessen zuordnen", ohne Weg zur Zuordnung. Jetzt:
 * (1) alle Dokumente in EINEM Schritt einem vorhandenen Kunden zuordnen,
 * (2) die Dubletten-Meldung liefert den gefundenen Kunden mit,
 * (3) ein Kunde ausserhalb des Portfolios wird dabei nie genannt.
 */
class BestandskundeStapelZuordnungTest extends TestCase
{
    use RefreshDatabase;

    private function inboxDoc(string $type, array $extracted, string $name, ?int $uploadedBy = null): Document
    {
        $path = 'documents/eingang/'.uniqid().'.pdf';
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
            'uploaded_by' => $uploadedBy,
        ]);
    }

    private function bestandskunde(): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Max Mustermann', 'email' => 'max.mustermann@example.com']);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '2600123',
            'birth_date' => '1996-01-01',
        ]);
    }

    /** @return array<int, Document> */
    private function vorgang(): array
    {
        $person = ['first_name' => 'Max', 'last_name' => 'Mustermann', 'birth_date' => '1996-01-01'];

        return [
            $this->inboxDoc('aufenthaltstitel', ['person' => $person + [
                'street' => 'Musterweg', 'house_number' => '8', 'zip' => '10115', 'city' => 'Berlin',
            ]], 'aufenthaltstitel.pdf'),
            $this->inboxDoc('gesundheitskarte', ['person' => $person], 'gesundheitskarte.pdf'),
            $this->inboxDoc('sepa_mandat', ['bank' => ['iban' => 'DE89370400440532013000']], 'bankkarte.pdf'),
        ];
    }

    public function test_alle_dokumente_eines_vorgangs_gehen_an_den_bestandskunden(): void
    {
        Storage::fake('local');
        $customer = $this->bestandskunde();
        $docs = $this->vorgang();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson(route('admin.documents.assign_batch'), [
            'document_ids' => array_map(fn ($d) => $d->id, $docs),
            'customer_id' => $customer->id,
            'apply_fields' => ['address', 'iban'],
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'documents' => 3, 'customer_id' => (string) $customer->id]);
        foreach ($docs as $doc) {
            $this->assertSame((string) $customer->id, (string) $doc->fresh()->customer_id);
        }
        $customer->refresh();
        $this->assertSame('10115', $customer->address_zip);
        $this->assertSame('DE89370400440532013000', $customer->iban);
        // Es entsteht KEIN zweiter Kunde.
        $this->assertSame(1, Customer::count());
    }

    public function test_bereits_anderem_kunden_zugeordnetes_dokument_blockiert_alles(): void
    {
        Storage::fake('local');
        $customer = $this->bestandskunde();
        $otherUser = User::factory()->create(['role' => 'customer']);
        $other = Customer::create(['user_id' => $otherUser->id, 'customer_number' => '2600999']);
        $docs = $this->vorgang();
        $docs[2]->update(['customer_id' => $other->id]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('admin.documents.assign_batch'), [
            'document_ids' => array_map(fn ($d) => $d->id, $docs),
            'customer_id' => $customer->id,
        ])->assertStatus(422);

        // Nichts halb zugeordnet.
        $this->assertNull($docs[0]->fresh()->customer_id);
        $this->assertNull($docs[1]->fresh()->customer_id);
    }

    public function test_verschiedene_personen_werden_nicht_einem_kunden_zugeordnet(): void
    {
        Storage::fake('local');
        $customer = $this->bestandskunde();
        $a = $this->inboxDoc('personalausweis', ['person' => ['first_name' => 'Max', 'last_name' => 'Mustermann']], 'a.pdf');
        $b = $this->inboxDoc('fuehrerschein', ['person' => ['first_name' => 'Karim', 'last_name' => 'Yousef']], 'b.pdf');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('admin.documents.assign_batch'), [
            'document_ids' => [$a->id, $b->id],
            'customer_id' => $customer->id,
        ])->assertStatus(422)->assertJsonStructure(['conflicts']);

        $this->assertNull($a->fresh()->customer_id);
    }

    public function test_dubletten_meldung_liefert_den_gefundenen_kunden_mit(): void
    {
        Storage::fake('local');
        $customer = $this->bestandskunde();
        $docs = $this->vorgang();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson(route('admin.documents.create_customer_batch'), [
            'document_ids' => array_map(fn ($d) => $d->id, $docs),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('duplicate.customer_id', (string) $customer->id)
            ->assertJsonPath('duplicate.customer_number', '2600123');
        $this->assertSame(1, Customer::count());
    }

    public function test_dubletten_meldung_nennt_keinen_kunden_ausserhalb_des_portfolios(): void
    {
        Storage::fake('local');
        $this->bestandskunde();
        $employee = User::factory()->create(['role' => 'employee']);
        abort_if($employee->canSeeAllCustomers(), 500, 'Testannahme: Mitarbeiter sieht nicht alle Kunden.');
        $doc = $this->inboxDoc('aufenthaltstitel', ['person' => [
            'first_name' => 'Max', 'last_name' => 'Mustermann', 'birth_date' => '1996-01-01',
        ]], 'aufenthaltstitel.pdf', $employee->id);

        $response = $this->actingAs($employee)->postJson(route('admin.documents.create_customer', $doc->id));

        $response->assertStatus(422)->assertJsonMissingPath('duplicate');
        $this->assertStringNotContainsString('Mustermann', $response->json('message'));
    }

    public function test_eingang_zeigt_zuordnen_knopf_fuer_vorgang_und_auswahl(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('admin.documents.inbox'))->assertOk()->getContent();

        $this->assertStringContainsString('data-h-click="auswahl-zuordnen"', $html);
        $this->assertStringContainsString('window.__h["auswahl-zuordnen"]', $html);
        $this->assertStringContainsString('window.__h["stapel-zuordnen"]', $html);
        $this->assertStringContainsString(json_encode(route('admin.documents.assign_batch')), $html);
    }
}
