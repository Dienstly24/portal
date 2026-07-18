<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sammel-Zusammenfuehrung mehrerer Dubletten-Paare in einem Schritt,
 * inkl. Cluster-Bildung (mehrere Datensaetze derselben Person -> ein Kunde).
 */
class DuplicateBulkMergeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function customer(string $name, string $email): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => $email]);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5($email . microtime()), 0, 8)),
        ]);
    }

    public function test_bulk_merge_of_two_pairs_merges_both(): void
    {
        $a1 = $this->customer('Anna Eins', 'a1@example.com');
        $a2 = $this->customer('Anna Eins', 'a2@example.com');
        $b1 = $this->customer('Bernd Zwei', 'b1@example.com');
        $b2 = $this->customer('Bernd Zwei', 'b2@example.com');

        $response = $this->actingAs($this->admin())->post(route('admin.customers.duplicates.merge'), [
            'pairs' => ["{$a1->id}|{$a2->id}", "{$b1->id}|{$b2->id}"],
        ]);

        $response->assertRedirect(route('admin.customers.duplicates'));
        // Je Paar bleibt genau ein Kunde uebrig.
        $this->assertEquals(1, Customer::whereIn('id', [$a1->id, $a2->id])->count());
        $this->assertEquals(1, Customer::whereIn('id', [$b1->id, $b2->id])->count());
    }

    public function test_overlapping_pairs_collapse_into_single_customer(): void
    {
        // Fuenf Datensaetze derselben Person, ueber ueberlappende Paare verknuepft.
        $c = [];
        foreach (range(1, 5) as $i) {
            $c[$i] = $this->customer('Ahmad Albhre', "ahmad{$i}@example.com");
        }
        Contract::create(['customer_id' => $c[3]->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active']);

        $pairs = [
            "{$c[1]->id}|{$c[2]->id}",
            "{$c[2]->id}|{$c[3]->id}",
            "{$c[3]->id}|{$c[4]->id}",
            "{$c[4]->id}|{$c[5]->id}",
        ];

        $this->actingAs($this->admin())->post(route('admin.customers.duplicates.merge'), ['pairs' => $pairs])
            ->assertRedirect(route('admin.customers.duplicates'));

        // Alle fuenf zu genau einem Kunden vereint.
        $remaining = Customer::whereIn('id', collect($c)->pluck('id'))->get();
        $this->assertCount(1, $remaining, 'Cluster muss zu genau einem Kunden zusammenschmelzen');
        // Der aelteste (c1) bleibt Hauptkunde und traegt jetzt den Vertrag.
        $this->assertEquals((string) $c[1]->id, (string) $remaining->first()->id);
        $this->assertEquals(1, Contract::where('customer_id', $c[1]->id)->count());
    }

    public function test_bulk_merge_is_capped_at_30(): void
    {
        $pairs = [];
        for ($i = 0; $i < 31; $i++) {
            $x = $this->customer("Person {$i}", "p{$i}a@example.com");
            $y = $this->customer("Person {$i}", "p{$i}b@example.com");
            $pairs[] = "{$x->id}|{$y->id}";
        }

        $response = $this->actingAs($this->admin())->post(route('admin.customers.duplicates.merge'), ['pairs' => $pairs]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        // Nichts wurde zusammengefuehrt (alle Paare noch vollstaendig vorhanden).
        $this->assertEquals(62, Customer::count());
    }

    public function test_employee_cannot_bulk_merge(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $a1 = $this->customer('Test Eins', 't1@example.com');
        $a2 = $this->customer('Test Eins', 't2@example.com');

        // Mitarbeiter ohne admin/manager werden von der Rollen-Middleware
        // weggeleitet (302) - die Sammel-Zusammenfuehrung greift nicht.
        $this->actingAs($employee)->post(route('admin.customers.duplicates.merge'), [
            'pairs' => ["{$a1->id}|{$a2->id}"],
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertEquals(2, Customer::whereIn('id', [$a1->id, $a2->id])->count());
    }
}
