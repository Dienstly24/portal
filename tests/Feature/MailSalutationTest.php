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

    private function customerWithGender(?string $gender, string $name, ?string $company = null): Customer
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'customer']);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5($name), 0, 6)),
            'gender' => $gender,
            'company_name' => $company,
        ]);
    }

    public function test_male_gender_gives_herr_salutation(): void
    {
        $customer = $this->customerWithGender('male', 'Max Mustermann');
        $ticket = Ticket::create(['customer_id' => $customer->id, 'type' => 'other', 'status' => 'open', 'subject' => 's', 'description' => 'd']);

        $html = (new TicketReplyMail($ticket, 'Antwort'))->render();
        $this->assertStringContainsString('Sehr geehrter Herr Mustermann', $html);
        $this->assertStringNotContainsString('Hallo Max', $html);
    }

    public function test_female_gender_gives_frau_salutation(): void
    {
        $customer = $this->customerWithGender('female', 'Erika Müller');
        $contract = Contract::create(['customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active', 'contract_number' => 'X', 'end_date' => now()->addDays(20)]);

        $html = (new ContractExpiryMail($contract, 20))->render();
        $this->assertStringContainsString('Sehr geehrte Frau Müller', $html);
    }

    public function test_company_customer_gets_generic_greeting(): void
    {
        $customer = $this->customerWithGender(null, 'Muster GmbH', 'Muster GmbH');
        $ticket = Ticket::create(['customer_id' => $customer->id, 'type' => 'other', 'status' => 'open', 'subject' => 's', 'description' => 'd']);

        $html = (new TicketReplyMail($ticket, 'Antwort'))->render();
        $this->assertStringContainsString('Sehr geehrte Damen und Herren', $html);
    }

    public function test_welcome_mail_uses_central_greeting_partial(): void
    {
        $html = (new CustomerWelcomeMail('Anna Beispiel', 'a@b.de', 'pw', 'de'))->render();
        $this->assertStringContainsString('Guten Tag Anna Beispiel', $html);
        $this->assertStringNotContainsString('Hallo <strong>', $html);
    }
}
