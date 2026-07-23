<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\CustomerNote;
use App\Models\EmailMessage;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\CustomerConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Omnichannel Phase A: EINE chronologische Timeline pro Kunde
 * (Chat + Tickets + E-Mails + Dokumente + interne Notizen).
 */
class CustomerConversationTimelineTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Max Meyer']);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26' . str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
        ]);
    }

    public function test_timeline_fuehrt_kanaele_chronologisch_zusammen(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Lina Weber']);
        $customer = $this->makeCustomer();

        $chat = CustomerMessage::create([
            'customer_id' => $customer->id, 'sender_id' => $customer->user_id,
            'body' => 'Chat-Nachricht', 'from_staff' => false,
        ]);
        $chat->created_at = now()->subHours(3); $chat->save();

        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'type' => 'damage', 'status' => 'open',
            'subject' => 'Wasserschaden Kueche', 'description' => 'Details', 'priority' => 'mittel',
        ]);
        TicketMessage::create(['ticket_id' => $ticket->id, 'sender_id' => $customer->user_id, 'body' => 'Ticket-Antwort vom Kunden']);
        TicketMessage::create(['ticket_id' => $ticket->id, 'sender_id' => $admin->id, 'body' => 'Nur intern sichtbar', 'is_internal' => true]);
        $ticket->logEvent('status_changed', 'Offen -> In Bearbeitung', $admin->id);
        CustomerNote::create(['customer_id' => $customer->id, 'created_by' => $admin->id, 'note' => 'Wichtige interne Notiz', 'type' => 'note']);

        $timeline = (new CustomerConversationService())->timeline($customer);

        $kinds = $timeline->pluck('kind');
        $this->assertTrue($kinds->contains('chat'));
        $this->assertTrue($kinds->contains('event'));       // Ticket erstellt
        $this->assertTrue($kinds->contains('ticket_msg'));  // Kundenantwort im Ticket
        $this->assertTrue($kinds->contains('note'));        // interne Notiz + interne Ticket-Notiz

        // Status-Ereignis traegt Label, Bearbeiter und Details (Spalten
        // heissen event/details - Regressionsschutz gegen falsche Feldnamen)
        $statusEvent = $timeline->first(fn ($i) => $i['kind'] === 'event' && str_contains((string) $i['title'], 'Status geändert'));
        $this->assertNotNull($statusEvent);
        $this->assertStringContainsString('Lina Weber', $statusEvent['title']);
        $this->assertSame('Offen -> In Bearbeitung', $statusEvent['body']);

        // Chronologisch aufsteigend sortiert
        $times = $timeline->pluck('at')->map(fn ($t) => $t->timestamp);
        $this->assertSame($times->sort()->values()->all(), $times->values()->all());

        // Chat (3h alt) kommt vor dem gerade erstellten Ticket
        $this->assertTrue(
            $timeline->search(fn ($i) => $i['kind'] === 'chat')
            < $timeline->search(fn ($i) => $i['kind'] === 'event')
        );
    }

    public function test_emails_nur_fuer_rollen_mit_posteingang(): void
    {
        $customer = $this->makeCustomer();
        $account = \App\Models\EmailAccount::create(['name' => 'Support', 'email_address' => 'support@dienstly24.de', 'provider' => 'imap']);
        EmailMessage::create([
            'email_account_id' => $account->id, 'message_uid' => 'u1',
            'from_address' => 'kunde@example.de', 'subject' => 'Frage zur Police',
            'match_status' => 'confirmed', 'customer_id' => $customer->id,
            'received_at' => now(),
        ]);

        $service = new CustomerConversationService();
        $this->assertTrue($service->timeline($customer, includeEmails: true)->pluck('kind')->contains('email'));
        $this->assertFalse($service->timeline($customer, includeEmails: false)->pluck('kind')->contains('email'));
    }

    public function test_kundenkommunikation_zeigt_timeline_filter_und_schnellaktionen(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'type' => 'damage', 'status' => 'open',
            'subject' => 'Wasserschaden Kueche', 'description' => 'Details', 'priority' => 'mittel',
        ]);
        CustomerNote::create(['customer_id' => $customer->id, 'created_by' => $admin->id, 'note' => 'Interne Sicht', 'type' => 'note']);

        $this->actingAs($admin)
            ->get(route('admin.customer_chat', ['kunde' => (string) $customer->id]))
            ->assertOk()
            // Timeline-Elemente
            ->assertSee('Ticket erstellt: Wasserschaden Kueche')
            ->assertSee('Interne Sicht')
            ->assertSee('data-kind="event"', false)
            ->assertSee('data-kind="note"', false)
            // Kanal-Filter + Schnellaktionen
            ->assertSee('kx-filters', false)
            ->assertSee('🎫 #' . $ticket->ticket_number)
            ->assertSee(route('admin.ticket.status', $ticket->id), false)
            ->assertSee(route('admin.customer.note.store', $customer->id), false);
    }

    public function test_sidebar_hat_email_gruppe_und_umbenannte_zentrale(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Kundenkommunikation')
            ->assertSee('data-group="email"', false)
            ->assertSee('Posteingang');
    }
}
