<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Papierkorb (Soft Delete) + Bulk-Aktionen der Ticketliste:
 *  - Loeschen nur admin/manager, endgueltig NUR admin und nur aus dem Papierkorb.
 *  - Mitarbeiter/Support loeschen NIE (analog Kundenloeschung).
 *  - Geloeschte Tickets verschwinden aus Liste, Portal und Nummernvergabe.
 *  - Bulk: Status/Zuweisung/Prioritaet/Papierkorb, max. 30, Sichtbarkeits-Check.
 */
class TicketTrashAndBulkTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function makeCustomer(): Customer
    {
        $n = ++self::$seq;
        $user = User::factory()->create(['role' => 'customer', 'email' => "kunde{$n}@trash.de", 'name' => "Kunde {$n}"]);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26005' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'first_name' => 'Kunde',
            'last_name' => (string) $n,
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

    private function makeStaff(string $role, array $attrs = []): User
    {
        $n = ++self::$seq;
        return User::factory()->create(array_merge([
            'role' => $role,
            'email' => "staff{$n}@trash.de",
            'name' => ucfirst($role) . " {$n}",
        ], $attrs));
    }

    // ---------------- Papierkorb: Soft Delete ----------------

    public function test_admin_can_move_ticket_to_trash(): void
    {
        $ticket = $this->makeTicket($this->makeCustomer());

        $response = $this->actingAs($this->makeStaff('admin'))
            ->delete(route('admin.ticket.delete', $ticket->id));

        $response->assertRedirect(route('admin.tickets'));
        $this->assertSoftDeleted('tickets', ['id' => $ticket->id]);
        $this->assertSame(1, TicketEvent::where('ticket_id', $ticket->id)->where('event', 'deleted')->count());
    }

    public function test_manager_can_move_ticket_to_trash(): void
    {
        $ticket = $this->makeTicket($this->makeCustomer());

        $this->actingAs($this->makeStaff('manager'))
            ->delete(route('admin.ticket.delete', $ticket->id));

        $this->assertSoftDeleted('tickets', ['id' => $ticket->id]);
    }

    public function test_support_and_employee_can_never_delete_tickets(): void
    {
        $ticket = $this->makeTicket($this->makeCustomer());

        // Auch das Recht "Tickets bearbeiten" erlaubt KEIN Loeschen -
        // die role-Middleware leitet falsche Rollen zum Dashboard um.
        $this->actingAs($this->makeStaff('employee', ['can_manage_tickets' => true, 'can_see_all_customers' => true]))
            ->delete(route('admin.ticket.delete', $ticket->id))->assertRedirect(route('admin.dashboard'));
        $this->actingAs($this->makeStaff('support'))
            ->delete(route('admin.ticket.delete', $ticket->id))->assertRedirect(route('admin.dashboard'));

        $this->assertNull($ticket->fresh()->deleted_at);
    }

    public function test_trashed_ticket_disappears_from_list_and_portal(): void
    {
        $customer = $this->makeCustomer();
        $ticket = $this->makeTicket($customer, ['subject' => 'Verschwindet nach Loeschung']);
        $ticket->logEvent('deleted');
        $ticket->delete();

        // Beraterwelt-Liste
        $this->actingAs($this->makeStaff('admin'))->get(route('admin.tickets'))
            ->assertOk()->assertDontSee('Verschwindet nach Loeschung');

        // Kundenportal: Liste + Detail (404 statt Inhalt)
        $this->actingAs($customer->user)->get(route('portal.tickets'))
            ->assertOk()->assertDontSee('Verschwindet nach Loeschung');
        $this->actingAs($customer->user)->get(route('portal.tickets.show', $ticket->id))
            ->assertNotFound();
    }

    public function test_trash_view_lists_trashed_tickets_for_admin_and_manager_only(): void
    {
        $ticket = $this->makeTicket($this->makeCustomer(), ['subject' => 'Papierkorb-Eintrag']);
        $ticket->delete();

        $this->actingAs($this->makeStaff('admin'))
            ->get(route('admin.tickets', ['status' => 'papierkorb']))
            ->assertOk()->assertSee('Papierkorb-Eintrag');

        $this->actingAs($this->makeStaff('support'))
            ->get(route('admin.tickets', ['status' => 'papierkorb']))->assertForbidden();
        $this->actingAs($this->makeStaff('employee', ['can_manage_tickets' => true]))
            ->get(route('admin.tickets', ['status' => 'papierkorb']))->assertForbidden();
    }

    public function test_trashed_ticket_detail_visible_for_admin_but_not_support(): void
    {
        $ticket = $this->makeTicket($this->makeCustomer());
        $ticket->delete();

        $this->actingAs($this->makeStaff('admin'))
            ->get(route('admin.ticket', $ticket->id))
            ->assertOk()->assertSee('Papierkorb');

        $this->actingAs($this->makeStaff('support'))
            ->get(route('admin.ticket', $ticket->id))->assertNotFound();
    }

    public function test_trashed_ticket_rejects_write_actions(): void
    {
        $ticket = $this->makeTicket($this->makeCustomer());
        $ticket->delete();
        $admin = $this->makeStaff('admin');

        // findOrFail ohne withTrashed -> 404 fuer alle Schreibpfade
        $this->actingAs($admin)->post(route('admin.ticket.status', $ticket->id), ['status' => 'resolved'])->assertNotFound();
        $this->actingAs($admin)->post(route('admin.ticket.reply', $ticket->id), ['body' => 'Hallo', 'status' => 'waiting'])->assertNotFound();
        $this->actingAs($admin)->post(route('admin.ticket.note', $ticket->id), ['body' => 'Notiz'])->assertNotFound();
    }

    // ---------------- Wiederherstellen ----------------

    public function test_restore_brings_ticket_back(): void
    {
        $ticket = $this->makeTicket($this->makeCustomer());
        $ticket->delete();

        $this->actingAs($this->makeStaff('manager'))
            ->post(route('admin.ticket.restore', $ticket->id))
            ->assertRedirect();

        $this->assertNull($ticket->fresh()->deleted_at);
        $this->assertSame(1, TicketEvent::where('ticket_id', $ticket->id)->where('event', 'restored')->count());
    }

    public function test_employee_cannot_restore(): void
    {
        $ticket = $this->makeTicket($this->makeCustomer());
        $ticket->delete();

        $this->actingAs($this->makeStaff('employee', ['can_manage_tickets' => true]))
            ->post(route('admin.ticket.restore', $ticket->id))->assertRedirect(route('admin.dashboard'));
        $this->assertNotNull(Ticket::withTrashed()->find($ticket->id)->deleted_at, 'Ticket muss im Papierkorb bleiben.');
    }

    // ---------------- Endgueltig loeschen ----------------

    public function test_force_delete_requires_admin_and_trash_state(): void
    {
        $ticket = $this->makeTicket($this->makeCustomer());

        // Nicht im Papierkorb -> 404 (zweistufiger Schutz)
        $this->actingAs($this->makeStaff('admin'))
            ->delete(route('admin.ticket.forcedelete', $ticket->id))->assertNotFound();

        $ticket->delete();

        // Manager darf NICHT endgueltig loeschen (role:admin -> Redirect)
        $this->actingAs($this->makeStaff('manager'))
            ->delete(route('admin.ticket.forcedelete', $ticket->id))->assertRedirect(route('admin.dashboard'));
        $this->assertNotNull(Ticket::withTrashed()->find($ticket->id));
    }

    public function test_force_delete_removes_ticket_with_messages_events_and_files(): void
    {
        Storage::fake('local');
        $ticket = $this->makeTicket($this->makeCustomer());
        TicketMessage::create([
            'id' => Str::uuid(), 'ticket_id' => $ticket->id,
            'sender_id' => $this->makeStaff('support')->id, 'body' => 'Antwort', 'is_internal' => false,
        ]);
        Storage::disk('local')->put('tickets/' . $ticket->id . '/datei.pdf', 'PDF');
        TicketAttachment::create([
            'id' => Str::uuid(), 'ticket_id' => $ticket->id, 'uploaded_by' => null,
            'file_name' => 'datei.pdf', 'file_path' => 'tickets/' . $ticket->id . '/datei.pdf', 'disk' => 'local',
        ]);
        $ticket->delete();

        $this->actingAs($this->makeStaff('admin'))
            ->delete(route('admin.ticket.forcedelete', $ticket->id))
            ->assertRedirect(route('admin.tickets', ['status' => 'papierkorb']));

        $this->assertNull(Ticket::withTrashed()->find($ticket->id));
        $this->assertSame(0, TicketMessage::where('ticket_id', $ticket->id)->count());
        $this->assertSame(0, TicketEvent::where('ticket_id', $ticket->id)->count());
        $this->assertSame(0, TicketAttachment::where('ticket_id', $ticket->id)->count());
        Storage::disk('local')->assertMissing('tickets/' . $ticket->id . '/datei.pdf');
    }

    public function test_ticket_numbers_of_trashed_tickets_are_not_reused(): void
    {
        $customer = $this->makeCustomer();
        $a = $this->makeTicket($customer);
        $number = $a->ticket_number;
        $a->delete();

        $b = $this->makeTicket($customer);
        $this->assertNotSame($number, $b->ticket_number);
    }

    // ---------------- Bulk-Aktionen ----------------

    public function test_bulk_status_change_updates_all_selected(): void
    {
        $customer = $this->makeCustomer();
        $a = $this->makeTicket($customer);
        $b = $this->makeTicket($customer);

        // Wie im Browser: unbenutzte Selects der Bulk-Leiste kommen als
        // Leerstring mit und duerfen die Validierung nicht stoeren.
        $this->actingAs($this->makeStaff('admin'))
            ->post(route('admin.tickets.bulk'), [
                'action' => 'status', 'status' => 'resolved', 'ids' => [(string) $a->id, (string) $b->id],
                'assigned_to' => '', 'priority' => '',
            ])->assertRedirect();

        $this->assertSame('resolved', $a->fresh()->status);
        $this->assertSame('resolved', $b->fresh()->status);
        $this->assertNotNull($a->fresh()->resolved_at);
    }

    public function test_bulk_assign_to_me_and_priority_with_sla_recalc(): void
    {
        $customer = $this->makeCustomer();
        $ticket = $this->makeTicket($customer);
        $admin = $this->makeStaff('admin');

        $this->actingAs($admin)->post(route('admin.tickets.bulk'), [
            'action' => 'assign', 'assigned_to' => 'me', 'ids' => [(string) $ticket->id],
        ]);
        $this->assertSame($admin->id, $ticket->fresh()->assigned_to);

        $this->actingAs($admin)->post(route('admin.tickets.bulk'), [
            'action' => 'priority', 'priority' => 'dringend', 'ids' => [(string) $ticket->id],
        ]);
        $fresh = $ticket->fresh();
        $this->assertSame('dringend', $fresh->priority);
        // SLA dringend = 4h ab Erstellung
        $this->assertTrue($fresh->due_at->equalTo($fresh->created_at->copy()->addHours(4)));
    }

    public function test_bulk_delete_moves_to_trash_but_only_for_admin_or_manager(): void
    {
        $customer = $this->makeCustomer();
        $a = $this->makeTicket($customer);
        $b = $this->makeTicket($customer);

        // Mitarbeiter mit Ticket-Recht: Status ja, Loeschen NIE
        $this->actingAs($this->makeStaff('employee', ['can_manage_tickets' => true, 'can_see_all_customers' => true]))
            ->post(route('admin.tickets.bulk'), ['action' => 'delete', 'ids' => [(string) $a->id]])
            ->assertForbidden();
        $this->assertNull($a->fresh()->deleted_at);

        $this->actingAs($this->makeStaff('manager'))
            ->post(route('admin.tickets.bulk'), ['action' => 'delete', 'ids' => [(string) $a->id, (string) $b->id]]);
        $this->assertSoftDeleted('tickets', ['id' => $a->id]);
        $this->assertSoftDeleted('tickets', ['id' => $b->id]);
    }

    public function test_bulk_requires_manage_permission_and_caps_at_30(): void
    {
        $ticket = $this->makeTicket($this->makeCustomer());

        // Mitarbeiter OHNE Ticket-Recht: keine Bulk-Aktionen
        $this->actingAs($this->makeStaff('employee', ['can_manage_tickets' => false, 'can_see_all_customers' => true]))
            ->post(route('admin.tickets.bulk'), ['action' => 'status', 'status' => 'closed', 'ids' => [(string) $ticket->id]])
            ->assertForbidden();

        // Mehr als 30 IDs -> Validierungsfehler (analog Kunden-Bulk-Loeschung)
        $this->actingAs($this->makeStaff('admin'))
            ->from(route('admin.tickets'))
            ->post(route('admin.tickets.bulk'), [
                'action' => 'status', 'status' => 'closed',
                'ids' => array_map(fn ($i) => (string) Str::uuid(), range(1, 31)),
            ])->assertSessionHasErrors('ids');
    }

    public function test_bulk_skips_tickets_outside_own_visibility(): void
    {
        $ticket = $this->makeTicket($this->makeCustomer());
        // Mitarbeiter mit Ticket-Recht, aber OHNE Zugriff auf diesen Kunden
        $employee = $this->makeStaff('employee', ['can_manage_tickets' => true, 'can_see_all_customers' => false]);

        $this->actingAs($employee)->post(route('admin.tickets.bulk'), [
            'action' => 'status', 'status' => 'closed', 'ids' => [(string) $ticket->id],
        ])->assertRedirect();

        $this->assertSame('open', $ticket->fresh()->status, 'Fremdes Ticket darf nicht veraendert werden.');
    }
}
