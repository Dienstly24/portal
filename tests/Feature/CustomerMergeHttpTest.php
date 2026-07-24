<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-End-Absicherung des Merge-Vorgangs ueber die echte Route
 * (Dubletten-Ansicht + Zusammenfuehren). Reproduziert den gemeldeten
 * HTTP-500-Fehler und beweist, dass er behoben ist.
 */
class CustomerMergeHttpTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(string $name, string $email): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => $email]);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5($email . microtime()), 0, 8)),
        ]);
    }

    public function test_merge_route_does_not_500_when_admin_opened_both_customers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $primary = $this->makeCustomer('Nadia Hoffmann', 'nadia1@example.com');
        $duplicate = $this->makeCustomer('Nadia Hoffmann', 'nadia2@example.com');

        // Der Admin oeffnet - wie im echten Ablauf - erst beide Akten, bevor er
        // sie zusammenfuehrt. Jeder Aufruf legt eine customer_views-Zeile an.
        $this->actingAs($admin)->get("/admin/customers/{$primary->id}")->assertOk();
        $this->actingAs($admin)->get("/admin/customers/{$duplicate->id}")->assertOk();
        $this->assertEquals(2, DB::table('customer_views')->where('user_id', $admin->id)->count());

        // Merge-Formular oeffnen (GET) - darf nicht 500 werfen.
        $this->actingAs($admin)->get("/admin/customers/{$primary->id}/merge")->assertOk();

        // Zusammenfuehren absenden (POST) - vorher: UNIQUE-Verletzung -> 500.
        $this->actingAs($admin)
            ->post("/admin/customers/{$primary->id}/merge", ['duplicate_id' => $duplicate->id])
            ->assertRedirect(route('admin.customer', $primary->id));

        // Duplikat wurde verlustfrei zusammengefuehrt.
        $this->assertNull(Customer::find($duplicate->id));
        $this->assertEquals(1, DB::table('customer_views')->where('customer_id', $primary->id)->count());
    }
}
