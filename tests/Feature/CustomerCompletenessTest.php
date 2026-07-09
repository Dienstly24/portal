<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerFamily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCompletenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_customer_has_low_completeness_and_lists_missing(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $customer = Customer::create(['user_id' => $user->id, 'customer_number' => 'C-EMPTY']);

        $c = $customer->completeness();
        $this->assertSame(0, $c['percent']);
        $labels = collect($c['missing'])->pluck('label');
        $this->assertTrue($labels->contains('Krankenkasse fehlt'));
        $this->assertTrue($labels->contains('Bankverbindung fehlt'));
        $this->assertTrue($labels->contains('Steuer-ID optional'));
    }

    public function test_completeness_increases_as_data_is_added(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $customer = Customer::create([
            'user_id' => $user->id, 'customer_number' => 'C-FULL',
            'health_insurance_company' => 'TK', 'iban' => 'DE89370400440532013000',
        ]);
        CustomerFamily::create(['customer_id' => $customer->id, 'name' => 'Kind', 'relation' => 'kind']);

        $c = $customer->fresh()->completeness();
        // 3 von 6 Pflichtpunkten erfüllt -> 50 %
        $this->assertSame(50, $c['percent']);
    }

    public function test_dashboard_shows_completeness_card(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        Customer::create(['user_id' => $user->id, 'customer_number' => 'C-DASH']);

        $this->actingAs($user)->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('Ihre Kundenakte')
            ->assertSee('Krankenkasse fehlt');
    }
}
