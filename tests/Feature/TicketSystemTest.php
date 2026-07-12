<?php

namespace Tests\Feature;

use App\Mail\GuestTicketReplyMail;
use App\Mail\TicketReplyMail;
use App\Models\Customer;
use App\Models\InternalNotification;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Ticketsystem-Ausbau: Nummern, Status-Workflow, Zuweisung, Prioritaet/SLA,
 * interne Notizen, Verlauf, Kunden-Abschluss + Bewertung, Auto-Close.
 */
class TicketSystemTest extends TestCase
{
    use RefreshDatabase;

    private static int $customerSeq = 0;

    private function makeCustomer(string $email = 'kunde@test.de'): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => $email, 'name' => 'Timo Test']);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26000' . str_pad((string) ++self::$customerSeq, 2, '0', STR_PAD_LEFT),
            'first_name' => 'Timo',
            'last_name' => 'Test',
        ]);
    }

    private function makeTicket(Customer $customer, array $attrs = []): Ticket
    {
        return Ticket::create(array_merge([
            'customer_id' => $customer->id,
            'type' => 'other',
            'status' => 'open',
            'priority' => 'mittel',
            'subject' => 'Testanfrage',
            'description' => 'Beschreibung',
        ], $attrs));
    }

    // ---------------- Nummern, SLA & Verlauf bei Erstellung ----------------

    public function test_new_ticket_gets_number_due_date_and_created_event(): void
    {
        $ticket = $this->makeTicket($this->makeCustomer());

        $this->assertMatchesRegularExpression('/^T-\d{7}$/', $ticket->ticket_number);
        $this->assertNotNull($ticket->due_at, 'SLA-Faelligkeit muss gesetzt sein.');
        // Prioritaet mittel = 72h Reaktionszeit
        $this->assertTrue($ticket->due_at->between(now()->addHours(71), now()->addHours(73)));
        $this->assertSame(1, TicketEvent::where('ticket_id', $ticket->id)->where('event', 'created')->count());
    }

    public function test_ticket_numbers_are_sequential(): void
    {
        $customer = $this->makeCustomer();
        $a = $this->makeTicket($customer);
        $b = $this->makeTicket($customer);

        $this->assertSame(
            (int) substr($a->ticket_number, 4) + 1,
            (int) substr($b->ticket_number, 4)
        );
    }

    // ---------------- Status-Workflow (Beraterwelt) ----------------

    public function test_admin_can_resolve_ticket(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->makeTicket($this->makeCustomer());

        $this->actingAs($admin)->post(route('admin.ticket.status', $ticket->id), ['status' => 'resolved'])
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame('resolved', $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertSame(1, TicketEvent::where('ticket_id', $ticket->id)->where('event', 'status_changed')->count());
        // Kunde bekommt eine Portal-Glocke
        $this->assertNotNull(InternalNotification::where('user_id', $ticket->customer->user_id)
            ->where('title', 'Status Ihrer Anfrage')->first());
    }

    public function test_admin_can_close_and_reopen_ticket(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->makeTicket($this->makeCustomer());

        $this->actingAs($admin)->post(route('admin.ticket.status', $ticket->id), ['status' => 'closed']);
        $ticket->refresh();
        $this->assertSame('closed', $ticket->status);
        $this->assertNotNull($ticket->closed_at);
        $this->assertSame($admin->id, $ticket->closed_by);

        $this->actingAs($admin)->post(route('admin.ticket.status', $ticket->id), ['status' => 'open']);
        $ticket->refresh();
        $this->assertSame('open', $ticket->status);
        $this->assertNull($ticket->closed_at);
        $this->assertSame(1, $ticket->reopened_count);
        $this->assertSame(1, TicketEvent::where('ticket_id', $ticket->id)->where('event', 'reopened')->count());
    }

    public function test_taking_unassigned_ticket_in_progress_assigns_to_actor(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->makeTicket($this->makeCustomer());

        $this->actingAs($admin)->post(route('admin.ticket.status', $ticket->id), ['status' => 'in_progress']);

        $ticket->refresh();
        $this->assertSame('in_progress', $ticket->status);
        $this->assertSame($admin->id, $ticket->assigned_to);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->makeTicket($this->makeCustomer());

        $this->actingAs($admin)->from(route('admin.ticket', $ticket->id))
            ->post(route('admin.ticket.status', $ticket->id), ['status' => 'kaputt'])
            ->assertSessionHasErrors('status');
    }

    // ---------------- Zuweisung / Eigenschaften ----------------

    public function test_assigning_ticket_notifies_assignee_and_logs_event(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kollege = User::factory()->create(['role' => 'employee', 'name' => 'Karl Kollege']);
        $ticket = $this->makeTicket($this->makeCustomer());

        $this->actingAs($admin)->post(route('admin.ticket.update', $ticket->id), ['assigned_to' => $kollege->id]);

        $ticket->refresh();
        $this->assertSame($kollege->id, $ticket->assigned_to);
        $this->assertSame(1, TicketEvent::where('ticket_id', $ticket->id)->where('event', 'assigned')->count());
        $this->assertNotNull(InternalNotification::where('user_id', $kollege->id)
            ->where('title', '🎫 Ticket zugewiesen')->first());
    }

    public function test_ticket_cannot_be_assigned_to_customer_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $ticket = $this->makeTicket($customer);

        $this->actingAs($admin)->post(route('admin.ticket.update', $ticket->id), ['assigned_to' => $customer->user_id]);

        $this->assertNull($ticket->refresh()->assigned_to);
    }

    public function test_priority_change_recomputes_due_date_and_logs_event(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->makeTicket($this->makeCustomer());

        $this->actingAs($admin)->post(route('admin.ticket.update', $ticket->id), ['priority' => 'dringend']);

        $ticket->refresh();
        $this->assertSame('dringend', $ticket->priority);
        // dringend = 4h ab Erstellung
        $this->assertTrue($ticket->due_at->equalTo($ticket->created_at->copy()->addHours(4)));
        $this->assertSame(1, TicketEvent::where('ticket_id', $ticket->id)->where('event', 'priority_changed')->count());
    }

    // ---------------- Interne Notizen ----------------

    public function test_internal_note_is_hidden_from_customer_portal(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $ticket = $this->makeTicket($customer);

        $this->actingAs($admin)->post(route('admin.ticket.note', $ticket->id), ['body' => 'Geheime interne Notiz']);

        $note = TicketMessage::where('ticket_id', $ticket->id)->first();
        $this->assertTrue((bool) $note->is_internal);

        // Beraterwelt zeigt die Notiz ...
        $this->actingAs($admin)->get(route('admin.ticket', $ticket->id))->assertSee('Geheime interne Notiz');
        // ... das Kundenportal NIE
        $this->actingAs($customer->user)->get(route('portal.tickets.show', $ticket->id))
            ->assertOk()->assertDontSee('Geheime interne Notiz');
    }

    // ---------------- Antworten (Team) ----------------

    public function test_staff_reply_sets_first_response_and_supports_resolved_status(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->makeTicket($this->makeCustomer());

        $this->actingAs($admin)->post(route('admin.ticket.reply', $ticket->id), [
            'body' => 'Wir haben das Problem behoben.',
            'status' => 'resolved',
        ]);

        $ticket->refresh();
        $this->assertNotNull($ticket->first_response_at);
        $this->assertSame('resolved', $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
        Mail::assertSent(TicketReplyMail::class);
        $this->assertSame(1, TicketEvent::where('ticket_id', $ticket->id)->where('event', 'staff_reply')->count());
    }

    public function test_reply_to_guest_ticket_sends_mail_with_body(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = Ticket::create([
            'customer_id' => null,
            'type' => 'other',
            'status' => 'open',
            'subject' => 'Angebot bitte',
            'description' => 'Bitte um Angebot.',
            'source' => 'website',
            'guest_name' => 'Gerd Gast',
            'guest_email' => 'gast@example.de',
        ]);

        $this->actingAs($admin)->post(route('admin.ticket.reply', $ticket->id), [
            'body' => 'Gerne, anbei unser Angebot.',
            'status' => 'waiting',
        ]);

        Mail::assertSent(GuestTicketReplyMail::class, function ($mail) {
            return $mail->hasTo('gast@example.de')
                && str_contains($mail->render(), 'Gerne, anbei unser Angebot.');
        });
    }

    // ---------------- Berechtigungen ----------------

    public function test_employee_without_permission_cannot_manage_tickets(): void
    {
        $customer = $this->makeCustomer();
        $employee = User::factory()->create(['role' => 'employee', 'can_manage_tickets' => false]);
        $employee->assignedCustomers()->attach((string) $customer->id);
        $ticket = $this->makeTicket($customer);

        $this->actingAs($employee)->post(route('admin.ticket.status', $ticket->id), ['status' => 'closed'])
            ->assertForbidden();
        $this->actingAs($employee)->post(route('admin.ticket.reply', $ticket->id), [
            'body' => 'x', 'status' => 'open',
        ])->assertForbidden();

        // Lesen bleibt erlaubt
        $this->actingAs($employee)->get(route('admin.ticket', $ticket->id))->assertOk();
        $this->assertSame('open', $ticket->refresh()->status);
    }

    public function test_employee_with_permission_can_manage_own_customers_ticket(): void
    {
        $customer = $this->makeCustomer();
        $employee = User::factory()->create(['role' => 'employee', 'can_manage_tickets' => true]);
        $employee->assignedCustomers()->attach((string) $customer->id);
        $ticket = $this->makeTicket($customer);

        $this->actingAs($employee)->post(route('admin.ticket.status', $ticket->id), ['status' => 'in_progress'])
            ->assertRedirect();
        $this->assertSame('in_progress', $ticket->refresh()->status);
    }

    public function test_employee_cannot_touch_foreign_customers_ticket(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_manage_tickets' => true]);
        $ticket = $this->makeTicket($this->makeCustomer());

        $this->actingAs($employee)->post(route('admin.ticket.status', $ticket->id), ['status' => 'closed'])
            ->assertForbidden();
    }

    // ---------------- Liste: Filter & Suche ----------------

    public function test_ticket_list_filters_by_status_and_searches_by_number(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $offen = $this->makeTicket($customer, ['subject' => 'Offenes Anliegen']);
        $zu = $this->makeTicket($customer, ['subject' => 'Erledigtes Anliegen']);
        $zu->transitionTo('closed');

        $this->actingAs($admin)->get(route('admin.tickets', ['status' => 'open']))
            ->assertSee('Offenes Anliegen')->assertDontSee('Erledigtes Anliegen');

        $this->actingAs($admin)->get(route('admin.tickets', ['q' => $zu->ticket_number]))
            ->assertSee('Erledigtes Anliegen')->assertDontSee('Offenes Anliegen');
    }

    // ---------------- Kundenportal ----------------

    public function test_customer_reply_reopens_resolved_ticket(): void
    {
        $customer = $this->makeCustomer();
        $ticket = $this->makeTicket($customer);
        $ticket->transitionTo('resolved');

        $this->actingAs($customer->user)->post(route('portal.tickets.reply', $ticket->id), [
            'body' => 'Das Problem besteht leider weiterhin.',
        ]);

        $ticket->refresh();
        $this->assertSame('open', $ticket->status);
        $this->assertNull($ticket->resolved_at);
        $this->assertSame(1, $ticket->reopened_count);
    }

    public function test_customer_cannot_reply_to_closed_ticket(): void
    {
        $customer = $this->makeCustomer();
        $ticket = $this->makeTicket($customer);
        $ticket->transitionTo('closed');

        $this->actingAs($customer->user)->post(route('portal.tickets.reply', $ticket->id), [
            'body' => 'Noch eine Nachricht',
        ]);

        $this->assertSame(0, TicketMessage::where('ticket_id', $ticket->id)->count());
        $this->assertSame('closed', $ticket->refresh()->status);
    }

    public function test_customer_can_close_own_resolved_ticket(): void
    {
        $customer = $this->makeCustomer();
        $ticket = $this->makeTicket($customer);
        $ticket->transitionTo('resolved');

        $this->actingAs($customer->user)->post(route('portal.tickets.close', $ticket->id))
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame('closed', $ticket->status);
        $this->assertSame(1, TicketEvent::where('ticket_id', $ticket->id)->where('event', 'closed_by_customer')->count());
    }

    public function test_customer_cannot_close_foreign_ticket(): void
    {
        $fremd = $this->makeCustomer('fremd@test.de');
        $ich = $this->makeCustomer('ich@test.de');
        $ticket = $this->makeTicket($fremd);

        $this->actingAs($ich->user)->post(route('portal.tickets.close', $ticket->id))
            ->assertNotFound();
    }

    public function test_customer_can_rate_finished_ticket_once(): void
    {
        $customer = $this->makeCustomer();
        $ticket = $this->makeTicket($customer);
        $ticket->transitionTo('resolved');

        $this->actingAs($customer->user)->post(route('portal.tickets.rate', $ticket->id), [
            'rating' => 5,
            'rating_comment' => 'Super Service!',
        ]);

        $ticket->refresh();
        $this->assertSame(5, (int) $ticket->rating);
        $this->assertSame('Super Service!', $ticket->rating_comment);

        // Zweite Bewertung wird ignoriert
        $this->actingAs($customer->user)->post(route('portal.tickets.rate', $ticket->id), ['rating' => 1]);
        $this->assertSame(5, (int) $ticket->refresh()->rating);
    }

    public function test_customer_cannot_rate_open_ticket(): void
    {
        $customer = $this->makeCustomer();
        $ticket = $this->makeTicket($customer);

        $this->actingAs($customer->user)->post(route('portal.tickets.rate', $ticket->id), ['rating' => 3]);

        $this->assertNull($ticket->refresh()->rating);
    }

    public function test_portal_shows_resolved_banner_and_close_button(): void
    {
        $customer = $this->makeCustomer();
        $ticket = $this->makeTicket($customer);
        $ticket->transitionTo('resolved');

        $this->actingAs($customer->user)->get(route('portal.tickets.show', $ticket->id))
            ->assertOk()
            ->assertSee('als gelöst markiert')
            ->assertSee(route('portal.tickets.close', $ticket->id))
            ->assertSee($ticket->ticket_number);
    }

    // ---------------- Auto-Close ----------------

    public function test_auto_close_closes_old_resolved_tickets_only(): void
    {
        $customer = $this->makeCustomer();
        $alt = $this->makeTicket($customer, ['subject' => 'Altes geloestes Ticket']);
        $alt->transitionTo('resolved');
        $alt->forceFill(['resolved_at' => now()->subDays(10)])->save();

        $frisch = $this->makeTicket($customer, ['subject' => 'Frisch geloestes Ticket']);
        $frisch->transitionTo('resolved');

        $offen = $this->makeTicket($customer, ['subject' => 'Offenes Ticket']);

        $this->artisan('tickets:auto-close')->assertExitCode(0);

        $this->assertSame('closed', $alt->refresh()->status);
        $this->assertSame(1, TicketEvent::where('ticket_id', $alt->id)->where('event', 'auto_closed')->count());
        $this->assertSame('resolved', $frisch->refresh()->status);
        $this->assertSame('open', $offen->refresh()->status);
    }
}
