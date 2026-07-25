<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerRelationship;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentIntake\DocumentIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Geburtsurkunde: das Kind wird automatisch mit den bereits erfassten
 * Eltern-Kunden verknuepft (CustomerRelationship type 'family'), sobald das
 * Dokument dem Kind-Kunden zugeordnet wird. Verknuepft wird nur bei exaktem,
 * eindeutigem Namens-Treffer.
 */
class GeburtsurkundeVerknuepfungTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(string $name): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5((string) $user->id), 0, 8)),
        ]);
    }

    private function birthCertificate(Customer $child): Document
    {
        return Document::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'customer_id' => $child->id,
            'file_name' => 'geburtsurkunde.pdf',
            'file_path' => 'customers/' . $child->id . '/documents/geburtsurkunde.pdf',
            'disk' => 'local',
            'category' => 'identity',
            'ai_status' => 'done',
            'ai_type' => 'geburtsurkunde',
            'ai_extracted' => [
                'person' => ['first_name' => 'Kahlan Tariq Mohammed', 'last_name' => 'Al Mansoer'],
                'personen' => [
                    ['first_name' => 'Rasha Hussein Mohammed', 'last_name' => 'Al-Sewari', 'gender' => 'female', 'relation' => 'mutter'],
                    ['first_name' => 'Tariq Mohammed Abbas', 'last_name' => 'Al Mansoer', 'gender' => 'male', 'relation' => 'vater'],
                ],
            ],
        ]);
    }

    public function test_child_is_linked_to_existing_parents(): void
    {
        $father = $this->makeCustomer('Tariq Mohammed Abbas Al Mansoer');
        $mother = $this->makeCustomer('Rasha Hussein Mohammed Al-Sewari');
        $child = $this->makeCustomer('Kahlan Tariq Mohammed Al Mansoer');

        $doc = $this->birthCertificate($child);

        $linked = app(DocumentIntakeService::class)->linkBirthCertificateParents($doc, $child, null);

        $this->assertCount(2, $linked);

        foreach ([$father, $mother] as $parent) {
            [$a, $b] = CustomerRelationship::pairKey((string) $child->id, (string) $parent->id);
            $this->assertDatabaseHas('customer_relationships', [
                'customer_a_id' => $a,
                'customer_b_id' => $b,
                'type' => 'family',
            ]);
        }
    }

    public function test_no_link_when_parent_not_a_customer(): void
    {
        // Nur der Vater existiert; die Mutter ist (noch) kein Kunde.
        $father = $this->makeCustomer('Tariq Mohammed Abbas Al Mansoer');
        $child = $this->makeCustomer('Kahlan Tariq Mohammed Al Mansoer');

        $doc = $this->birthCertificate($child);
        $linked = app(DocumentIntakeService::class)->linkBirthCertificateParents($doc, $child, null);

        $this->assertSame(['Tariq Mohammed Abbas Al Mansoer'], $linked);
        $this->assertSame(1, CustomerRelationship::count());
    }

    public function test_no_link_when_parent_name_is_ambiguous(): void
    {
        // Zwei Kunden mit identischem Namen -> kein automatisches Verknuepfen
        // (der Mitarbeiter entscheidet, welcher der richtige Elternteil ist).
        $this->makeCustomer('Tariq Mohammed Abbas Al Mansoer');
        $this->makeCustomer('Tariq Mohammed Abbas Al Mansoer');
        $child = $this->makeCustomer('Kahlan Tariq Mohammed Al Mansoer');

        $doc = $this->birthCertificate($child);
        $linked = app(DocumentIntakeService::class)->linkBirthCertificateParents($doc, $child, null);

        $this->assertNotContains('Tariq Mohammed Abbas Al Mansoer', $linked);
        // Die (eindeutige) Mutter fehlt hier ganz -> gar keine Verknuepfung.
        $this->assertSame(0, CustomerRelationship::count());
    }

    public function test_linking_is_idempotent(): void
    {
        $father = $this->makeCustomer('Tariq Mohammed Abbas Al Mansoer');
        $mother = $this->makeCustomer('Rasha Hussein Mohammed Al-Sewari');
        $child = $this->makeCustomer('Kahlan Tariq Mohammed Al Mansoer');
        $doc = $this->birthCertificate($child);

        $svc = app(DocumentIntakeService::class);
        $svc->linkBirthCertificateParents($doc, $child, null);
        $svc->linkBirthCertificateParents($doc, $child, null);

        // Zwei Laeufe erzeugen keine Duplikat-Beziehungen.
        $this->assertSame(2, CustomerRelationship::count());
    }
}
