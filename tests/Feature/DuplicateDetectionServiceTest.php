<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use App\Services\Matching\DuplicateDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(string $name, string $email, array $attrs = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => $email]);
        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5($email . microtime()), 0, 8)),
        ], $attrs));
    }

    private function scan(): array
    {
        return app(DuplicateDetectionService::class)->scan();
    }

    /** Der eigentliche Fehler aus der Praxis: gleicher Name allein muss reichen. */
    public function test_same_name_alone_is_detected(): void
    {
        $this->makeCustomer('Ahmad Albhre', 'ahmad@example.com');
        $this->makeCustomer('Ahmad Albhre', 'ahmad.zweit@web.de');

        $result = $this->scan();

        $this->assertCount(1, $result['pairs'], 'Zwei Kunden mit demselben Namen muessen als Verdacht erscheinen');
        $this->assertContains('Gleicher Name', $result['pairs'][0]['signals']);
    }

    public function test_name_order_and_umlauts_do_not_prevent_match(): void
    {
        $this->makeCustomer('Jürgen Müller', 'j1@example.com');
        $this->makeCustomer('Mueller Juergen', 'j2@example.com');

        $result = $this->scan();

        $this->assertCount(1, $result['pairs']);
        $this->assertContains('Gleicher Name', $result['pairs'][0]['signals']);
    }

    public function test_same_phone_alone_is_detected(): void
    {
        $this->makeCustomer('Anna Beispiel', 'anna@example.com', ['phone' => '+49 30 1234567']);
        $this->makeCustomer('Andere Person', 'andere@example.com', ['phone' => '030 1234567']);

        $result = $this->scan();

        $this->assertCount(1, $result['pairs']);
        $this->assertContains('Gleiche Telefonnummer', $result['pairs'][0]['signals']);
    }

    public function test_same_email_as_second_address_is_detected(): void
    {
        $this->makeCustomer('Klaus Weber', 'klaus@example.com');
        $this->makeCustomer('K. Weber', 'anders@example.com', ['email2' => 'klaus@example.com']);

        $result = $this->scan();

        $this->assertGreaterThanOrEqual(1, count($result['pairs']));
        $this->assertContains('Gleiche E-Mail-Adresse', $result['pairs'][0]['signals']);
    }

    public function test_same_contract_number_in_different_formatting_is_detected(): void
    {
        // contracts.contract_number ist DB-seitig unique - dieselbe Police
        // taucht bei Doppelanlage daher nur mit anderer Schreibweise auf
        // ("POL-99887" vs "POL 99887"), die normalisiert gleich ist.
        $a = $this->makeCustomer('Person Eins', 'eins@example.com');
        $b = $this->makeCustomer('Person Zwei', 'zwei@example.com');
        Contract::create(['customer_id' => $a->id, 'contract_number' => 'POL-99887', 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active']);
        Contract::create(['customer_id' => $b->id, 'contract_number' => 'POL 99887', 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active']);

        $result = $this->scan();

        $this->assertCount(1, $result['pairs']);
        $this->assertContains('Gleiche Vertragsnummer', $result['pairs'][0]['signals']);
    }

    public function test_same_iban_is_detected(): void
    {
        $this->makeCustomer('Person Eins', 'eins@example.com', ['iban' => 'DE89370400440532013000']);
        $this->makeCustomer('Person Zwei', 'zwei@example.com', ['iban' => 'DE89 3704 0044 0532 0130 00']);

        $result = $this->scan();

        $this->assertCount(1, $result['pairs']);
        $this->assertContains('Gleiche Bankverbindung (IBAN)', $result['pairs'][0]['signals']);
    }

    public function test_scan_ignores_clearly_distinct_customers(): void
    {
        $this->makeCustomer('Anna Beispiel', 'anna@example.com', ['phone' => '030111', 'birth_date' => '1990-05-04']);
        $this->makeCustomer('Bernd Anders', 'bernd@example.com', ['phone' => '040222', 'birth_date' => '1975-01-01']);

        $result = $this->scan();

        $this->assertCount(0, $result['pairs']);
    }

    public function test_pair_is_reported_only_once(): void
    {
        $this->makeCustomer('Max Mustermann', 'max1@example.com', ['birth_date' => '1980-01-01', 'phone' => '030111']);
        $this->makeCustomer('Max Mustermann', 'max2@example.com', ['birth_date' => '1980-01-01', 'phone' => '030111']);

        $result = $this->scan();

        $this->assertCount(1, $result['pairs']);
        $this->assertTrue(
            $result['pairs'][0]['primary']->created_at <= $result['pairs'][0]['duplicate']->created_at
        );
    }

    public function test_scan_respects_visible_id_scope(): void
    {
        $a = $this->makeCustomer('Julia Schmidt', 'julia1@example.com');
        $this->makeCustomer('Julia Schmidt', 'julia2@example.com');

        $result = app(DuplicateDetectionService::class)->scan([(string) $a->id]);

        $this->assertCount(0, $result['pairs']);
    }
}
