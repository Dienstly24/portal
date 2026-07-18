<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerRelationship;
use App\Models\User;
use App\Services\Matching\DuplicateDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Kein Duplikat" -> Beziehung ("Verwandte Kunden"): das Paar verschwindet
 * aus der Dubletten-Liste und erscheint als Beziehung; reversibel.
 */
class CustomerRelationshipTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function customer(string $name, string $email, array $attrs = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => $email]);
        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5($email . microtime()), 0, 8)),
        ], $attrs));
    }

    public function test_dismissed_pair_disappears_from_duplicates_and_becomes_relationship(): void
    {
        // Gleiche Anschrift, verschiedene Personen (Familie) -> Verdacht, aber kein Duplikat.
        $a = $this->customer('Anna Meier', 'anna@example.com', ['address' => 'Hauptstr. 5, 10115 Berlin']);
        $b = $this->customer('Bernd Meier', 'bernd@example.com', ['address' => 'Hauptstr. 5, 10115 Berlin']);

        // Zunaechst ist das Paar ein Verdachtsfall.
        $before = app(DuplicateDetectionService::class)->scan();
        $this->assertCount(1, $before['pairs']);

        // Als "kein Duplikat" markieren.
        $this->actingAs($this->admin())->post(route('admin.customers.duplicates.dismiss'), [
            'customer_a' => (string) $a->id,
            'customer_b' => (string) $b->id,
        ])->assertRedirect();

        $this->assertDatabaseCount('customer_relationships', 1);

        // Danach taucht das Paar NICHT mehr in den Dubletten auf.
        $after = app(DuplicateDetectionService::class)->scan();
        $this->assertCount(0, $after['pairs']);
    }

    public function test_dismiss_is_order_independent_and_deduplicated(): void
    {
        $a = $this->customer('Klaus Weber', 'k1@example.com', ['phone' => '030111']);
        $b = $this->customer('Klaus Weber', 'k2@example.com', ['phone' => '030111']);

        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.customers.duplicates.dismiss'), ['customer_a' => (string) $a->id, 'customer_b' => (string) $b->id]);
        // Gleiches Paar in umgekehrter Reihenfolge -> keine zweite Zeile.
        $this->actingAs($admin)->post(route('admin.customers.duplicates.dismiss'), ['customer_a' => (string) $b->id, 'customer_b' => (string) $a->id]);

        $this->assertDatabaseCount('customer_relationships', 1);
    }

    public function test_relationships_page_lists_marked_pairs(): void
    {
        $a = $this->customer('Sara Klein', 's1@example.com', ['phone' => '040222']);
        $b = $this->customer('Sara Klein', 's2@example.com', ['phone' => '040222']);
        [$x, $y] = CustomerRelationship::pairKey($a->id, $b->id);
        CustomerRelationship::create(['customer_a_id' => $x, 'customer_b_id' => $y, 'type' => 'not_duplicate']);

        $response = $this->actingAs($this->admin())->get(route('admin.customers.relationships'));
        $response->assertOk();
        $response->assertSee('Verwandte Kunden');
        $response->assertSee('Sara Klein');
    }

    public function test_removing_relationship_makes_pair_reappear_as_duplicate(): void
    {
        $a = $this->customer('Tom Fischer', 't1@example.com', ['phone' => '030999']);
        $b = $this->customer('Tom Fischer', 't2@example.com', ['phone' => '030999']);
        [$x, $y] = CustomerRelationship::pairKey($a->id, $b->id);
        $rel = CustomerRelationship::create(['customer_a_id' => $x, 'customer_b_id' => $y, 'type' => 'not_duplicate']);

        $this->assertCount(0, app(DuplicateDetectionService::class)->scan()['pairs']);

        $this->actingAs($this->admin())->delete(route('admin.customers.relationships.delete', $rel->id))->assertRedirect();

        $this->assertDatabaseCount('customer_relationships', 0);
        $this->assertCount(1, app(DuplicateDetectionService::class)->scan()['pairs']);
    }

    public function test_bulk_dismiss_marks_multiple_pairs_at_once(): void
    {
        $a1 = $this->customer('Fam A', 'fa1@example.com', ['address' => 'Weg 1, 10115 Berlin']);
        $a2 = $this->customer('Fam B', 'fa2@example.com', ['address' => 'Weg 1, 10115 Berlin']);
        $b1 = $this->customer('Haus C', 'hc1@example.com', ['phone' => '030555']);
        $b2 = $this->customer('Haus D', 'hc2@example.com', ['phone' => '030555']);

        $this->actingAs($this->admin())->post(route('admin.customers.duplicates.dismiss_bulk'), [
            'pairs' => ["{$a1->id}|{$a2->id}", "{$b1->id}|{$b2->id}"],
        ])->assertRedirect(route('admin.customers.duplicates'));

        $this->assertDatabaseCount('customer_relationships', 2);
        $this->assertCount(0, app(DuplicateDetectionService::class)->scan()['pairs']);
    }

    public function test_relations_for_finds_customers_sharing_a_signal(): void
    {
        $main = $this->customer('Nina Roth', 'nina@example.com', ['phone' => '0301234']);
        $shares = $this->customer('Otto Roth', 'otto@example.com', ['phone' => '0301234']);
        $this->customer('Unbeteiligt Person', 'weg@example.com', ['phone' => '0999999']);

        $relations = app(DuplicateDetectionService::class)->relationsFor($main->fresh());

        $ids = array_map(fn ($r) => (string) $r['customer']->id, $relations);
        $this->assertContains((string) $shares->id, $ids);
        $this->assertCount(1, $relations);
    }
}
