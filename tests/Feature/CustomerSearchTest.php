<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractEnergyDetail;
use App\Models\ContractVehicleDetail;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Volltext-Kundensuche (Customer::scopeSearch) und ihre Anbindung an die
 * Kundenliste, die Kopfzeilen-Suche und die Mitarbeiter-Zuweisung. Die Suche
 * muss den Kunden ueber JEDES vorliegende Merkmal finden - nicht nur ueber den
 * Namen.
 */
class CustomerSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function customer(array $userAttrs = [], array $customerAttrs = []): Customer
    {
        $user = User::factory()->create(array_merge([
            'role' => 'customer', 'name' => 'Max Mustermann', 'email' => 'kunde-' . uniqid() . '@kunde.de',
        ], $userAttrs));

        return Customer::create(array_merge([
            'user_id' => $user->id, 'customer_number' => 'K-' . uniqid(),
        ], $customerAttrs));
    }

    // ---------- Scope: Treffer ueber verschiedene Felder ----------

    public function test_search_matches_by_name(): void
    {
        $this->customer(['name' => 'Ahmad Hassan']);
        $this->customer(['name' => 'Bernd Bauer']);

        $ids = Customer::search('Ahmad')->pluck('id');
        $this->assertCount(1, $ids);
    }

    public function test_search_matches_by_email(): void
    {
        $hit = $this->customer(['email' => 'ziel@example.com', 'name' => 'Kein Name Treffer']);
        $this->customer(['email' => 'andere@example.com']);

        $this->assertEquals([$hit->id], Customer::search('ziel@example.com')->pluck('id')->all());
    }

    public function test_search_matches_by_phone_mobile_and_customer_number(): void
    {
        $phone = $this->customer([], ['phone' => '030 12345678']);
        $mobile = $this->customer([], ['mobile' => '0170 9998887']);
        $number = $this->customer([], ['customer_number' => '2600042']);

        $this->assertEquals([$phone->id], Customer::search('12345678')->pluck('id')->all());
        $this->assertEquals([$mobile->id], Customer::search('9998887')->pluck('id')->all());
        $this->assertEquals([$number->id], Customer::search('2600042')->pluck('id')->all());
    }

    public function test_search_matches_by_address_zip_and_city(): void
    {
        $hit = $this->customer([], ['address_zip' => '24768', 'address_city' => 'Rendsburg', 'address_street' => 'Hauptstrasse']);
        $this->customer([], ['address_zip' => '10115', 'address_city' => 'Berlin']);

        $this->assertEquals([$hit->id], Customer::search('24768')->pluck('id')->all());
        $this->assertEquals([$hit->id], Customer::search('Rendsburg')->pluck('id')->all());
        $this->assertEquals([$hit->id], Customer::search('Hauptstrasse')->pluck('id')->all());
    }

    public function test_search_matches_by_contract_number(): void
    {
        $hit = $this->customer(['name' => 'Vertrag Kunde']);
        Contract::create([
            'customer_id' => $hit->id, 'type' => 'kfz', 'insurer' => 'HUK',
            'status' => 'active', 'contract_number' => 'POL-778899',
        ]);
        $this->customer(['name' => 'Ohne Vertrag']);

        $this->assertEquals([$hit->id], Customer::search('POL-778899')->pluck('id')->all());
    }

    public function test_search_matches_by_license_plate_and_vin(): void
    {
        $hit = $this->customer(['name' => 'Auto Kunde']);
        $contract = Contract::create([
            'customer_id' => $hit->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active', 'contract_number' => 'V-' . uniqid(),
        ]);
        ContractVehicleDetail::create([
            'contract_id' => $contract->id, 'license_plate' => 'RD-AB 123', 'vin' => 'WVWZZZ1JZXW000001',
        ]);
        $this->customer(['name' => 'Kein Auto']);

        $this->assertEquals([$hit->id], Customer::search('RD-AB 123')->pluck('id')->all());
        $this->assertEquals([$hit->id], Customer::search('WVWZZZ1JZXW000001')->pluck('id')->all());
    }

    public function test_search_matches_by_energy_meter_number(): void
    {
        $hit = $this->customer(['name' => 'Strom Kunde']);
        $contract = Contract::create([
            'customer_id' => $hit->id, 'type' => 'strom', 'insurer' => 'Stadtwerke', 'status' => 'active', 'contract_number' => 'V-' . uniqid(),
        ]);
        ContractEnergyDetail::create([
            'contract_id' => $contract->id, 'meter_number' => '1ISK0001234567', 'malo_id' => '50012345678',
        ]);
        $this->customer(['name' => 'Kein Strom']);

        $this->assertEquals([$hit->id], Customer::search('1ISK0001234567')->pluck('id')->all());
        $this->assertEquals([$hit->id], Customer::search('50012345678')->pluck('id')->all());
    }

    public function test_search_matches_by_standalone_vehicle(): void
    {
        $hit = $this->customer(['name' => 'Stammfahrzeug Kunde']);
        CustomerVehicle::create([
            'customer_id' => $hit->id, 'brand' => 'VW', 'model' => 'Golf', 'license_plate' => 'B-XY 4242', 'vin' => 'VIN123456789',
        ]);
        $this->customer(['name' => 'Kein Fahrzeug']);

        $this->assertEquals([$hit->id], Customer::search('B-XY 4242')->pluck('id')->all());
    }

    public function test_search_is_multi_word_and_narrowing(): void
    {
        $both = $this->customer(['name' => 'Ahmad Hassan'], ['address_city' => 'Berlin']);
        $onlyName = $this->customer(['name' => 'Ahmad Klein'], ['address_city' => 'Hamburg']);
        $onlyCity = $this->customer(['name' => 'Petra Gross'], ['address_city' => 'Berlin']);

        $ids = Customer::search('Ahmad Berlin')->pluck('id')->all();
        $this->assertEquals([$both->id], $ids);
        $this->assertNotContains($onlyName->id, $ids);
        $this->assertNotContains($onlyCity->id, $ids);
    }

    public function test_empty_search_term_is_a_noop(): void
    {
        $this->customer(['name' => 'Egal Eins']);
        $this->customer(['name' => 'Egal Zwei']);

        $this->assertEquals(2, Customer::search('')->count());
        $this->assertEquals(2, Customer::search(null)->count());
    }

    public function test_search_escapes_like_wildcards(): void
    {
        $this->customer(['name' => 'Prozent Kunde']);
        // Ein reines Wildcard darf NICHT alle Kunden matchen.
        $this->assertEquals(0, Customer::search('%')->count());
    }

    // ---------- Kundenliste (admin.customers?q=) ----------

    public function test_customer_list_search_by_zip_shows_only_matches(): void
    {
        $this->customer(['name' => 'Rendsburg Kunde'], ['address_zip' => '24768']);
        $this->customer(['name' => 'Berlin Kunde'], ['address_zip' => '10115']);

        $res = $this->actingAs($this->admin)->get(route('admin.customers', ['q' => '24768']));
        $res->assertOk()->assertSee('Rendsburg Kunde')->assertDontSee('Berlin Kunde');
    }

    public function test_customer_list_search_by_plate_finds_customer(): void
    {
        $hit = $this->customer(['name' => 'Kennzeichen Kunde']);
        $contract = Contract::create([
            'customer_id' => $hit->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active', 'contract_number' => 'V-' . uniqid(),
        ]);
        ContractVehicleDetail::create(['contract_id' => $contract->id, 'license_plate' => 'RD-ZZ 999']);
        $this->customer(['name' => 'Anderer Kunde']);

        $res = $this->actingAs($this->admin)->get(route('admin.customers', ['q' => 'RD-ZZ 999']));
        $res->assertOk()->assertSee('Kennzeichen Kunde')->assertDontSee('Anderer Kunde');
    }

    // ---------- Mitarbeiter-Zuweisung (employees.customer-search) ----------

    public function test_assignment_search_endpoint_finds_by_non_name_field(): void
    {
        $hit = $this->customer(['name' => 'Zuweisung Kunde'], ['customer_number' => '2600777']);
        $this->customer(['name' => 'Nicht Treffer']);

        $res = $this->actingAs($this->admin)->getJson(route('admin.employees.customer-search', ['q' => '2600777']));
        $res->assertOk();
        $data = $res->json();
        $this->assertCount(1, $data);
        $this->assertEquals($hit->id, $data[0]['id']);
    }

    public function test_global_search_matches_broad_fields(): void
    {
        $hit = $this->customer(['name' => 'Global Kunde'], ['mobile' => '0171 5554443']);
        $this->customer(['name' => 'Global Andere']);

        $res = $this->actingAs($this->admin)->getJson(route('admin.search', ['q' => '5554443']), [
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
        $res->assertOk();
        $titles = collect($res->json())->pluck('title');
        $this->assertTrue($titles->contains('Global Kunde'));
        $this->assertFalse($titles->contains('Global Andere'));
    }
}
