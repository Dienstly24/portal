<?php

namespace Tests\Feature;

use App\Mail\ContractExpiryMail;
use App\Mail\CustomerWelcomeMail;
use App\Mail\TicketReplyMail;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailSalutationTest extends TestCase
{
    use RefreshDatabase;

    private function customerWithSalutation(string $salutation, string $name): Customer
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'customer']);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5($name), 0, 6)),
            'salutation' => $salutation,
        ]);
    }

    public function test_herr_salutation_in_ticket_mail(): void
    {
        $customer = $this->customerWithSalutation('herr', 'Max Mustermann');
        $ticket = Ticket::create(['customer_id' => $customer->id, 'type' => 'other', 'status' => 'open', 'subject' => 's', 'description' => 'd']);

        $html = (new TicketReplyMail($ticket, 'Antwort'))->render();
        $this->assertStringContainsString('Sehr geehrter Herr Mustermann', $html);
        $this->assertStringNotContainsString('Hallo Max', $html);
    }

    public function test_frau_salutation_in_contract_mail(): void
    {
        $customer = $this->customerWithSalutation('frau', 'Erika Müller');
        $contract = Contract::create(['customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active', 'contract_number' => 'X', 'end_date' => now()->addDays(20)]);

        $html = (new ContractExpiryMail($contract, 20))->render();
        $this->assertStringContainsString('Sehr geehrte Frau Müller', $html);
    }

    public function test_firma_salutation_generic_greeting(): void
    {
        $customer = $this->customerWithSalutation('firma', 'Muster GmbH');
        $ticket = Ticket::create(['customer_id' => $customer->id, 'type' => 'other', 'status' => 'open', 'subject' => 's', 'description' => 'd']);

        $html = (new TicketReplyMail($ticket, 'Antwort'))->render();
        $this->assertStringContainsString('Sehr geehrte Damen und Herren', $html);
    }

    public function test_welcome_mail_uses_central_greeting_partial(): void
    {
        // Neue Signatur: Customer-Objekt statt Einzelstrings; die Anrede
        // kommt weiter aus dem zentralen _greeting-Partial.
        $customer = $this->customerWithSalutation('frau', 'Anna Beispiel');
        $html = (new CustomerWelcomeMail($customer, 'manual', 'pw'))->render();
        $this->assertStringContainsString('Sehr geehrte Frau Beispiel', $html);
        $this->assertStringNotContainsString('Hallo <strong>', $html);
    }
}
