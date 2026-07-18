<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-End der Dubletten-Pruefung: Uebersichtsseite, Vorauswahl in der
 * Merge-Maske und die eigentliche Zusammenfuehrung ueber die Route.
 */
class DuplicateDetectionHttpTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function customer(string $name, string $email, ?string $birth = null): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => $email]);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5($email), 0, 8)),
            'birth_date' => $birth,
        ]);
    }

    public function test_duplicates_page_lists_suspected_pairs(): void
    {
        $this->customer('Ahmad Albhre', 'ahmad@example.com', '1990-05-04');
        $this->customer('Ahmad Albhre', 'ahmad2@example.com', '1990-05-04');

        $response = $this->actingAs($this->admin())->get(route('admin.customers.duplicates'));

        $response->assertOk();
        $response->assertSee('Mögliche Dubletten');
        $response->assertSee('Ahmad Albhre');
    }

    public function test_merge_route_preserves_contracts_end_to_end(): void
    {
        $primary = $this->customer('Klaus Weber', 'klaus1@example.com', '1970-02-02');
        $duplicate = $this->customer('Klaus Weber', 'klaus2@example.com', '1970-02-02');
        $contract = Contract::create(['customer_id' => $duplicate->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active']);

        $response = $this->actingAs($this->admin())
            ->post(route('admin.customer.merge.do', $primary->id), ['duplicate_id' => $duplicate->id]);

        $response->assertRedirect(route('admin.customer', $primary->id));
        $this->assertEquals($primary->id, $contract->fresh()->customer_id);
        $this->assertNull(Customer::find($duplicate->id));
    }

    public function test_merge_form_shows_transfer_preview_for_suggested_duplicate(): void
    {
        $primary = $this->customer('Julia Schmidt', 'julia1@example.com', '1985-06-15');
        $duplicate = $this->customer('Julia Schmidt', 'julia2@example.com', '1985-06-15');
        Contract::create(['customer_id' => $duplicate->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active']);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.customer.merge', $primary->id) . '?duplicate=' . $duplicate->id);

        $response->assertOk();
        $response->assertSee('Möglicher Treffer erkannt');
        $response->assertSee('Verträge');
    }
}
