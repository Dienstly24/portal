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

    public function test_composer_bietet_ticket_kanal_und_versionshinweis(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'type' => 'damage', 'status' => 'open',
            'subject' => 'Wasserschaden', 'description' => 'x', 'priority' => 'mittel',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customer_chat', ['kunde' => (string) $customer->id]))
            ->assertOk()
            // Kanalwahl Chat/Ticket + versteckter Status fuer den Reply-Endpoint
            ->assertSee('kx-chan', false)
            ->assertSee(route('admin.ticket.reply', $ticket->id), false)
            ->assertSee('kc-ticket-status', false)
            // Intern-Teilen (interner Chat mit Mentions) + E-Mail-Shortcut
            ->assertSee(route('admin.internal.store', $customer->id), false)
            ->assertSee(route('admin.email.compose', ['customer_id' => $customer->id]), false)
            // Aktualisieren-Hinweis vorhanden
            ->assertSee('kx-refresh', false);
    }

    public function test_feed_liefert_timeline_version_und_aendert_sich(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();

        $first = $this->actingAs($admin)
            ->getJson(route('admin.customer_chat.feed', $customer->id))
            ->assertOk()->json('timeline_version');

        Ticket::create([
            'customer_id' => $customer->id, 'type' => 'damage', 'status' => 'open',
            'subject' => 'Neues Ticket', 'description' => 'x', 'priority' => 'mittel',
        ]);

        $second = $this->actingAs($admin)
            ->getJson(route('admin.customer_chat.feed', $customer->id))
            ->assertOk()->json('timeline_version');

        $this->assertNotSame($first, $second);
    }

    public function test_whatsapp_button_nur_mit_rufnummer_und_normalisiert(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $mit = $this->makeCustomer();
        $mit->update(['mobile' => '0176 1234567']);
        $ohne = Customer::create([
            'user_id' => User::factory()->create(['role' => 'customer', 'name' => 'Ohne Nummer'])->id,
            'customer_number' => '2699999', 'preferred_lang' => 'de',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customer_chat', ['kunde' => (string) $mit->id]))
            ->assertOk()
            ->assertSee('https://wa.me/491761234567', false);

        $this->actingAs($admin)
            ->get(route('admin.customer_chat', ['kunde' => (string) $ohne->id]))
            ->assertOk()
            ->assertDontSee('wa.me', false);
    }

    public function test_kundenakte_hat_kommunikations_tab_mit_timeline(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        Ticket::create([
            'customer_id' => $customer->id, 'type' => 'damage', 'status' => 'open',
            'subject' => 'Akten-Test-Ticket', 'description' => 'x', 'priority' => 'mittel',
        ]);

        $this->actingAs($admin)->get(route('admin.customer', $customer->id))
            ->assertOk()
            ->assertSee('tab-kommunikation', false)
            ->assertSee('Komplette Kommunikation')
            ->assertSee('Ticket erstellt: Akten-Test-Ticket');
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
