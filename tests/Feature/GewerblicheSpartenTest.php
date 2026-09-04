<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gewerbliche Sparten (Betreiber-Vorgabe 30.07.2026): Betriebshaftpflicht und
 * Frachtfuehrerhaftpflicht versichern den BETRIEB, nicht die Privatperson -
 * andere Gesellschaften, andere Beitraege, andere Beratung. Sie sind daher
 * eigene Sparten und im Vertragsformular als "Gewerblich" gruppiert.
 */
class GewerblicheSpartenTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(substr(md5((string) $user->id), 0, 8)),
        ]);
    }

    public function test_commercial_types_are_registered_and_flagged(): void
    {
        foreach (['betriebshaftpflicht', 'frachtfuehrerhaftpflicht'] as $key) {
            $this->assertContains($key, Contract::typeKeys());
            $this->assertContains($key, Contract::commercialTypeKeys());
            $this->assertTrue((new Contract(['type' => $key]))->isCommercial());
        }

        // Die private Haftpflicht bleibt privat - sie darf nicht mitrutschen.
        $this->assertNotContains('haftpflicht', Contract::commercialTypeKeys());
        $this->assertFalse((new Contract(['type' => 'haftpflicht']))->isCommercial());
    }

    public function test_labels_and_icons_are_available_for_display(): void
    {
        $this->assertSame('Betriebshaftpflicht', (new Contract(['type' => 'betriebshaftpflicht']))->typeLabel());
        $this->assertSame('Frachtführerhaftpflicht', (new Contract(['type' => 'frachtfuehrerhaftpflicht']))->typeLabel());
        // Kein Fallback auf "Sonstige" (dann waere die Sparte nicht eingetragen).
        $this->assertNotSame(
            Contract::TYPES['andere']['icon'],
            (new Contract(['type' => 'frachtfuehrerhaftpflicht']))->typeIcon()
        );
    }

    public function test_contract_form_offers_the_commercial_group(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())
            ->get(route('admin.contract.create', $customer->id))
            ->assertOk()
            ->assertSee('Gewerblich')
            ->assertSee('Betriebshaftpflicht')
            ->assertSee('Frachtführerhaftpflicht', false);
    }

    public function test_commercial_contract_can_be_stored(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())->post(route('admin.contract.store', $customer->id), [
            'type' => 'frachtfuehrerhaftpflicht',
            'insurer' => 'Helvetia Versicherungs-AG',
            'status' => 'active',
            'start_date' => '2026-07-31',
            'premium_amount' => 696.12,
            'premium_interval' => 'yearly',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('contracts', [
            'customer_id' => $customer->id,
            'type' => 'frachtfuehrerhaftpflicht',
            'insurer' => 'Helvetia Versicherungs-AG',
        ]);
    }
}
