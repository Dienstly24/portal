<?php

namespace Tests\Feature;

use App\Http\Controllers\SupportFormController;
use App\Mail\CustomerWelcomeMail;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportFormTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => 'hilfe@kunde.de', 'name' => 'Sara Hilfe']);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '2600042',
            'first_name' => 'Sara',
            'last_name' => 'Hilfe',
        ]);
    }

    // ---------------- Formular ----------------

    public function test_form_is_public_and_asks_guests_for_contact_data(): void
    {
        $this->get('/hilfe')->assertOk()
            ->assertSee('Hilfe & Kontakt')
            ->assertSee('Gewünschte Leistung')
            ->assertSee('name="name"', false)
            ->assertSee('name="email"', false);
    }

    public function test_form_prefills_customer_from_email_token(): void
    {
        $customer = $this->makeCustomer();
        $token = SupportFormController::tokenFor($customer);

        $this->get('/hilfe?t=' . urlencode($token))->assertOk()
            ->assertSee('2600042')
            ->assertSee('Ihrem Konto zugeordnet')
            ->assertDontSee('name="email"', false); // keine manuelle Eingabe nötig
    }

    public function test_invalid_token_falls_back_to_guest_form(): void
    {
        $this->get('/hilfe?t=kaputt')->assertOk()
            ->assertSee('name="email"', false);
    }

    // ---------------- Absenden ----------------

    public function test_submit_with_token_creates_ticket_linked_to_customer(): void
    {
        $customer = $this->makeCustomer();

        $this->post('/hilfe', [
            't' => SupportFormController::tokenFor($customer),
            'leistung' => 'login',
            'message' => 'Ich habe eine Frage zum Login.',
        ])->assertOk()->assertSee('Vorgangsnummer');

        $ticket = Ticket::first();
        $this->assertNotNull($ticket);
        $this->assertSame((string) $customer->id, (string) $ticket->customer_id);
        $this->assertSame('hilfe-formular', $ticket->source);
        $this->assertSame('open', $ticket->status);
        $this->assertStringContainsString('Login / Zugang zum Portal', $ticket->subject);
        $this->assertSame('Ich habe eine Frage zum Login.', $ticket->description);
    }

    public function test_guest_submit_is_matched_to_customer_by_email(): void
    {
        $customer = $this->makeCustomer();

        $this->post('/hilfe', [
            'name' => 'Sara Hilfe',
            'email' => 'hilfe@kunde.de',
            'leistung' => 'vertrag',
            'message' => 'Frage zu meinem Stromvertrag.',
        ])->assertOk();

        $ticket = Ticket::first();
        $this->assertSame((string) $customer->id, (string) $ticket->customer_id, 'Anfrage muss per E-Mail der Kundenakte zugeordnet werden.');
    }

    public function test_unknown_guest_creates_guest_ticket(): void
    {
        $this->post('/hilfe', [
            'name' => 'Neuer Interessent',
            'email' => 'neu@interessent.de',
            'leistung' => 'angebot',
            'message' => 'Bitte um ein Angebot.',
        ])->assertOk();

        $ticket = Ticket::first();
        $this->assertNull($ticket->customer_id);
        $this->assertSame('Neuer Interessent', $ticket->guest_name);
        $this->assertSame('neu@interessent.de', $ticket->guest_email);
    }

    public function test_honeypot_blocks_bots(): void
    {
        $this->post('/hilfe', [
            'name' => 'Bot', 'email' => 'bot@bot.de',
            'leistung' => 'login', 'message' => 'spam',
            'website' => 'http://spam.example',
        ])->assertStatus(422);

        $this->assertSame(0, Ticket::count());
    }

    // ---------------- Willkommens-Mail ----------------

    public function test_welcome_mail_contains_prefilled_support_link(): void
    {
        $customer = $this->makeCustomer();

        $html = (new CustomerWelcomeMail($customer, 'birthdate'))->render();

        $this->assertStringContainsString('/hilfe?t=', $html);
        $this->assertStringContainsString('Anfrage senden', $html);
    }
}
