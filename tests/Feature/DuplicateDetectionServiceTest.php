<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\Matching\DuplicateDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateDetectionServiceTest extends TestCase
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

    public function test_scan_finds_pair_with_matching_name_and_birth_date(): void
    {
        $this->makeCustomer('Ahmad Albhre', 'ahmad@example.com', '1990-05-04');
        $this->makeCustomer('Ahmad Albhre', 'ahmad.zweit@example.com', '1990-05-04');

        $result = app(DuplicateDetectionService::class)->scan();

        $this->assertCount(1, $result['pairs'], 'Erwartet genau ein Verdachtspaar');
        $this->assertGreaterThanOrEqual(70, $result['pairs'][0]['score']);
        $this->assertNotEmpty($result['pairs'][0]['signals']);
    }

    public function test_scan_ignores_clearly_distinct_customers(): void
    {
        $this->makeCustomer('Anna Beispiel', 'anna@example.com', '1990-05-04');
        $this->makeCustomer('Bernd Anders', 'bernd@example.com', '1975-01-01');

        $result = app(DuplicateDetectionService::class)->scan();

        $this->assertCount(0, $result['pairs']);
    }

    public function test_pair_is_reported_only_once(): void
    {
        // Drei identische Datensaetze -> Paare, aber jedes Paar nur einmal.
        $this->makeCustomer('Max Mustermann', 'max1@example.com', '1980-01-01', '030111');
        $this->makeCustomer('Max Mustermann', 'max2@example.com', '1980-01-01', '030111');

        $result = app(DuplicateDetectionService::class)->scan();

        $this->assertCount(1, $result['pairs']);
        // Aelterer Datensatz ist Hauptkunde.
        $this->assertTrue(
            $result['pairs'][0]['primary']->created_at <= $result['pairs'][0]['duplicate']->created_at
        );
    }

    public function test_scan_respects_visible_id_scope(): void
    {
        $a = $this->makeCustomer('Julia Schmidt', 'julia1@example.com', '1985-06-15');
        $b = $this->makeCustomer('Julia Schmidt', 'julia2@example.com', '1985-06-15');

        // Nur einer der beiden ist sichtbar -> kein Paar (kein Datenleck).
        $result = app(DuplicateDetectionService::class)->scan([(string) $a->id]);

        $this->assertCount(0, $result['pairs']);
    }
}
