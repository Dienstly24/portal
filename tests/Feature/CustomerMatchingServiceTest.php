<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\User;
use App\Services\Matching\CustomerMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerMatchingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(string $name, string $email, ?string $birthDate = null, ?string $phone = null): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => $email]);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5($email), 0, 8)),
            'birth_date' => $birthDate,
            'phone' => $phone,
        ]);
    }

    public function test_matching_all_signals_yields_automatic_tier(): void
    {
        // Geburtsdatum (40) + Name exakt (30) + E-Mail (20) + Telefon-Bonus (5) = 95 > 90
        $this->makeCustomer('Anna Beispiel', 'anna@example.com', '1990-05-04', '030123456');

        $result = (new CustomerMatchingService())->match([
            'full_name' => 'Anna Beispiel',
            'birth_date' => '1990-05-04',
            'email' => 'anna@example.com',
            'phone' => '030123456',
        ]);

        $this->assertTrue($result->hasMatch());
        $this->assertSame('auto', $result->tier());
        $this->assertGreaterThan(90, $result->score);
    }

    public function test_birth_date_and_name_only_yields_confirm_tier(): void
    {
        // Geburtsdatum (40) + Name exakt (30) = 70 -> Bestätigungsstufe, nicht automatisch
        $this->makeCustomer('Max Mustermann', 'max@example.com', '1980-01-01');

        $result = (new CustomerMatchingService())->match([
            'full_name' => 'Max Mustermann',
            'birth_date' => '1980-01-01',
        ]);

        $this->assertTrue($result->hasMatch());
        $this->assertSame('confirm', $result->tier());
        $this->assertGreaterThanOrEqual(70, $result->score);
        $this->assertLessThanOrEqual(90, $result->score);
    }

    public function test_no_matching_signals_yields_manual_tier_without_match(): void
    {
        $this->makeCustomer('Peter Beispiel', 'peter@example.com', '1975-03-03');

        $result = (new CustomerMatchingService())->match([
            'full_name' => 'Völlig Unbekannt',
            'email' => 'unbekannt@nirgendwo.invalid',
        ]);

        $this->assertSame('manual', $result->tier());
    }

    public function test_empty_criteria_returns_no_match(): void
    {
        $this->makeCustomer('Irrelevant Person', 'irrelevant@example.com');

        $result = (new CustomerMatchingService())->match([]);

        $this->assertFalse($result->hasMatch());
        $this->assertSame(0, $result->score);
    }

    public function test_disambiguates_between_similar_candidates_using_birth_date(): void
    {
        $this->makeCustomer('Julia Schmidt', 'julia1@example.com', '1985-06-15');
        $target = $this->makeCustomer('Julia Schmidt', 'julia2@example.com', '1992-11-20');

        $result = (new CustomerMatchingService())->match([
            'full_name' => 'Julia Schmidt',
            'birth_date' => '1992-11-20',
        ]);

        $this->assertTrue($result->hasMatch());
        $this->assertSame((string) $target->id, (string) $result->customer->id);
    }

    public function test_address_similarity_contributes_to_score(): void
    {
        $customer = $this->makeCustomer('Klaus Weber', 'klaus@example.com', '1970-02-02');
        CustomerAddress::create([
            'customer_id' => $customer->id,
            'type' => 'home',
            'street' => 'Musterstraße 12',
            'zip' => '12345',
            'city' => 'Berlin',
            'country' => 'Deutschland',
        ]);

        $result = (new CustomerMatchingService())->match([
            'full_name' => 'Klaus Weber',
            'street' => 'Musterstrasse 12',
            'zip' => '12345',
        ]);

        $this->assertTrue($result->hasMatch());
        $this->assertArrayHasKey('address', $result->breakdown);
        $this->assertGreaterThan(0, $result->breakdown['address']['points']);
    }
}
