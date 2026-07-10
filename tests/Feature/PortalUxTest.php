<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalUxTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);
        return Customer::create(['user_id' => $user->id, 'customer_number' => 'C-' . strtoupper(substr(md5((string)$user->id),0,6))]);
    }

    // Punkt 13: Validierungsfehler werden dem Kunden angezeigt
    public function test_validation_errors_are_shown_on_portal(): void
    {
        $customer = $this->makeCustomer();
        // ungültige IBAN -> Fehler zurück in die Session
        $this->actingAs($customer->user)
            ->from(route('portal.profile'))
            ->post(route('portal.profile.update'), ['iban' => 'INVALID'])
            ->assertRedirect(route('portal.profile'))
            ->assertSessionHasErrors('iban');
    }

    // Punkt 13: jede Portal-Hauptseite lädt fehlerfrei und zeigt Orientierung
    public function test_all_portal_pages_load(): void
    {
        $customer = $this->makeCustomer();
        foreach (['portal.dashboard','portal.contracts','portal.documents','portal.family','portal.profile','portal.contacts','portal.change_requests','portal.tickets'] as $route) {
            $this->actingAs($customer->user)->get(route($route))->assertOk();
        }
    }

    // Punkt 13/Security: fremde Ticket-Anhänge sind nicht abrufbar
    public function test_customer_cannot_download_foreign_ticket_attachment(): void
    {
        $owner = $this->makeCustomer();
        $ticket = Ticket::create(['customer_id' => $owner->id, 'type' => 'other', 'status' => 'open', 'subject' => 's', 'description' => 'd']);
        $att = TicketAttachment::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'ticket_id' => $ticket->id, 'file_name' => 'x.pdf', 'file_path' => 'x.pdf']);

        $attacker = $this->makeCustomer();
        $this->actingAs($attacker->user)->get(route('portal.attachment.download', $att->id))->assertNotFound();
    }
}
