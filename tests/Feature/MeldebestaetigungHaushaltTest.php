<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerRelationship;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentIntake\DocumentIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Meldebestaetigung eines KINDES (Betreiber-Vorgabe 04.08.2026): Die
 * Bestaetigungen einer Familie kommen als Stapel - je ein Blatt fuer Vater,
 * Mutter und Kind, alle mit derselben neuen Anschrift. Ist die Person
 * minderjaehrig, verknuepft das System sie automatisch mit den bereits
 * erfassten ERWACHSENEN an genau dieser Anschrift.
 *
 * Belastbar durch die Kombination gleiche Anschrift + passender Familienname;
 * der Name wird transkriptions-tolerant verglichen ("Najm" / "Al-Najm" /
 * "Najim" sind dieselbe Familie). WER Vater und wer Mutter ist, behauptet das
 * System bewusst nicht - die Meldebestaetigung belegt nur den Haushalt.
 */
class MeldebestaetigungHaushaltTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(string $name, ?string $birth, array $address = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(substr(md5((string) $user->id), 0, 8)),
            'birth_date' => $birth,
            'address_street' => 'Gartenstraße',
            'address_house_number' => '105',
            'address_zip' => '71522',
            'address_city' => 'Backnang',
        ], $address));
    }

    private function meldebestaetigung(Customer $customer, array $person): Document
    {
        return Document::create([
            'id' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'file_name' => 'meldebestaetigung.pdf',
            'file_path' => 'customers/'.$customer->id.'/documents/melde.pdf',
            'disk' => 'local',
            'category' => 'identity',
            'ai_status' => 'done',
            'ai_type' => 'meldebescheinigung',
            'ai_extracted' => ['person' => $person],
        ]);
    }

    /** Das Kind aus dem realen Fall: Khaled Najm, geboren 13.12.2024. */
    private function kindDaten(): array
    {
        return [
            'first_name' => 'Khaled', 'last_name' => 'Najm', 'birth_date' => '2024-12-13',
            'street' => 'Gartenstraße', 'house_number' => '105',
            'zip' => '71522', 'city' => 'Backnang',
        ];
    }

    public function test_child_is_linked_to_adults_of_the_same_household(): void
    {
        // Die Erwachsenen sind bereits erfasst - mit ABWEICHENDER Schreibweise
        // des Familiennamens, wie sie die Aemter liefern.
        $vater = $this->makeCustomer('Mohamad Najim', '1999-02-10');
        $mutter = $this->makeCustomer('Aya Al-Najm', '2007-01-01');
        $kind = $this->makeCustomer('Khaled Najm', '2024-12-13');

        $doc = $this->meldebestaetigung($kind, $this->kindDaten());
        $linked = app(DocumentIntakeService::class)->linkMeldebestaetigungHousehold($doc, $kind, null);

        $this->assertCount(2, $linked);
        foreach ([$vater, $mutter] as $adult) {
            [$a, $b] = CustomerRelationship::pairKey((string) $kind->id, (string) $adult->id);
            $rel = CustomerRelationship::where('customer_a_id', $a)->where('customer_b_id', $b)->first();
            $this->assertNotNull($rel, 'Beziehung zu '.$adult->user->name.' fehlt');
            $this->assertSame('family', $rel->type);
            // Die Begruendung nennt die Belege, behauptet aber keine Elternrolle.
            $this->assertStringContainsString('Meldebestätigung', $rel->note);
            $this->assertStringContainsString('Gartenstraße 105', $rel->note);
            $this->assertStringNotContainsString('Vater', $rel->note);
            $this->assertStringNotContainsString('Mutter', $rel->note);
        }
    }

    public function test_no_link_for_a_different_address(): void
    {
        // Gleicher Familienname, ANDERE Anschrift -> kein Haushalt, keine
        // Beziehung (Namensgleichheit allein beweist nichts).
        $fremd = $this->makeCustomer('Ali Najm', '1990-05-05', [
            'address_street' => 'Hauptstraße', 'address_house_number' => '2',
            'address_zip' => '70173', 'address_city' => 'Stuttgart',
        ]);
        $kind = $this->makeCustomer('Khaled Najm', '2024-12-13');

        $doc = $this->meldebestaetigung($kind, $this->kindDaten());
        $linked = app(DocumentIntakeService::class)->linkMeldebestaetigungHousehold($doc, $kind, null);

        $this->assertSame([], $linked);
        $this->assertSame(0, CustomerRelationship::count());
        $this->assertNotNull($fremd);
    }

    public function test_no_link_for_a_different_family_name_at_the_same_address(): void
    {
        // Mitbewohner in derselben Wohnung, aber andere Familie -> keine
        // Familien-Beziehung.
        $this->makeCustomer('Peter Schmidt', '1980-03-03');
        $kind = $this->makeCustomer('Khaled Najm', '2024-12-13');

        $doc = $this->meldebestaetigung($kind, $this->kindDaten());
        $linked = app(DocumentIntakeService::class)->linkMeldebestaetigungHousehold($doc, $kind, null);

        $this->assertSame([], $linked);
        $this->assertSame(0, CustomerRelationship::count());
    }

    public function test_no_link_for_an_adult_registration(): void
    {
        // Die Meldebestaetigung eines ERWACHSENEN erzeugt keine automatische
        // Familien-Beziehung - das waere Spekulation.
        $this->makeCustomer('Mohamad Najim', '1999-02-10');
        $erwachsen = $this->makeCustomer('Aya Al-Najm', '2007-01-01');

        $doc = $this->meldebestaetigung($erwachsen, [
            'first_name' => 'Aya', 'last_name' => 'Al-Najm', 'birth_date' => '2007-01-01',
            'street' => 'Gartenstraße', 'house_number' => '105',
            'zip' => '71522', 'city' => 'Backnang',
        ]);
        $linked = app(DocumentIntakeService::class)->linkMeldebestaetigungHousehold($doc, $erwachsen, null);

        $this->assertSame([], $linked);
        $this->assertSame(0, CustomerRelationship::count());
    }

    public function test_linking_is_idempotent(): void
    {
        $this->makeCustomer('Mohamad Najim', '1999-02-10');
        $kind = $this->makeCustomer('Khaled Najm', '2024-12-13');
        $doc = $this->meldebestaetigung($kind, $this->kindDaten());

        $intake = app(DocumentIntakeService::class);
        $intake->linkMeldebestaetigungHousehold($doc, $kind, null);
        $intake->linkMeldebestaetigungHousehold($doc, $kind, null);

        // Dasselbe Dokument zweimal verarbeitet -> genau EINE Beziehung.
        $this->assertSame(1, CustomerRelationship::count());
    }
}
