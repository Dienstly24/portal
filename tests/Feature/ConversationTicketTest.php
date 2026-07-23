<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Omnichannel Phase C: Vorgang (Ticket) aus der laufenden Unterhaltung
 * eroeffnen und der Problem-Cockpit-Kopf (Status, Prioritaet, SLA).
 */
class ConversationTicketTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(string $name = 'Max Meyer'): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26' . str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
        ]);
    }

    public function test_admin_eroeffnet_ticket_aus_unterhaltung(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();

        $this->actingAs($admin)
            ->post(route('admin.customer_chat.ticket', $customer->id), [
                'type' => 'damage',
                'priority' => 'hoch',
                'subject' => 'Wasserschaden Kueche',
                'description' => 'Rohrbruch unter der Spuele',
            ])
            ->assertRedirect(route('admin.customer_chat', ['kunde' => (string) $customer->id]));

        $this->assertDatabaseHas('tickets', [
            'customer_id' => $customer->id,
            'type' => 'damage',
            'priority' => 'hoch',
            'subject' => 'Wasserschaden Kueche',
            'status' => 'open',
            'source' => 'kundenkommunikation',
        ]);
    }

    public function test_assign_me_setzt_bearbeiter_und_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();

        $this->actingAs($admin)
            ->post(route('admin.customer_chat.ticket', $customer->id), [
                'type' => 'offer', 'priority' => 'mittel',
                'subject' => 'Angebot KFZ', 'description' => 'Bitte Angebot erstellen',
                'assign_me' => '1',
            ])->assertRedirect();

        $ticket = Ticket::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('in_progress', $ticket->status);
        $this->assertSame($admin->id, $ticket->assigned_to);
        // Zuweisungs-Ereignis wird protokolliert (erscheint in der Timeline)
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id, 'event' => 'assigned',
        ]);
    }

    public function test_employee_ohne_ticketrecht_darf_nicht(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_manage_tickets' => false]);
        $customer = $this->makeCustomer();
        $customer->betreuer()->attach($employee->id);

        $this->actingAs($employee)
            ->post(route('admin.customer_chat.ticket', $customer->id), [
                'type' => 'other', 'priority' => 'mittel',
                'subject' => 'x', 'description' => 'y',
            ])->assertForbidden();

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_fremder_kunde_ist_verboten(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_manage_tickets' => true]);
        $foreign = $this->makeCustomer();

        $this->actingAs($employee)
            ->post(route('admin.customer_chat.ticket', $foreign->id), [
                'type' => 'other', 'priority' => 'mittel',
                'subject' => 'x', 'description' => 'y',
            ])->assertForbidden();
    }

    public function test_button_und_modal_mit_vorbefuellung_sichtbar(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        CustomerMessage::create([
            'customer_id' => $customer->id, 'sender_id' => $customer->user_id,
            'body' => 'Mein Auto hatte einen Unfall, was muss ich tun?', 'from_staff' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customer_chat', ['kunde' => (string) $customer->id]))
            ->assertOk()
            ->assertSee('Vorgang erstellen')
            ->assertSee('kx-ticket-modal', false)
            ->assertSee(route('admin.customer_chat.ticket', $customer->id), false)
            // Beschreibung ist mit der letzten Kundennachricht vorbefuellt
            ->assertSee('Mein Auto hatte einen Unfall, was muss ich tun?');
    }

    public function test_cockpit_zeigt_aktiven_vorgang_mit_status_und_prioritaet(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'type' => 'damage', 'status' => 'in_progress',
            'subject' => 'Wasserschaden Kueche', 'description' => 'x', 'priority' => 'hoch',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customer_chat', ['kunde' => (string) $customer->id]))
            ->assertOk()
            ->assertSee('kx-cockpit', false)
            ->assertSee('#' . $ticket->ticket_number)
            ->assertSee('In Bearbeitung')
            ->assertSee('Wasserschaden Kueche');
    }

    public function test_cockpit_markiert_ueberfaelligen_vorgang(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'type' => 'damage', 'status' => 'open',
            'subject' => 'Dringend', 'description' => 'x', 'priority' => 'hoch',
        ]);
        // SLA-Frist in der Vergangenheit, noch keine erste Antwort -> ueberfaellig
        $ticket->forceFill(['due_at' => now()->subHours(2), 'first_response_at' => null])->save();

        $this->assertTrue($ticket->fresh()->isOverdue());

        $this->actingAs($admin)
            ->get(route('admin.customer_chat', ['kunde' => (string) $customer->id]))
            ->assertOk()
            ->assertSee('SLA überfällig');
    }
}
