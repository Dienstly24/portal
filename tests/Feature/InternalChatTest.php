<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\InternalMessage;
use App\Models\InternalNotification;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalChatTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5((string) $user->id), 0, 8)),
        ]);
    }

    private function staff(string $role, bool $seesAll = false): User
    {
        return User::factory()->create(['role' => $role, 'can_see_all_customers' => $seesAll]);
    }

    // ---------------------------------------------------------------
    // Erstellen: Mitarbeiter A kann interne Nachricht erstellen
    // ---------------------------------------------------------------

    public function test_assigned_employee_can_create_internal_message(): void
    {
        $employee = $this->staff('employee');
        $customer = $this->makeCustomer();
        $employee->assignedCustomers()->attach((string) $customer->id);

        $this->actingAs($employee)
            ->post(route('admin.internal.store', $customer->id), [
                'message' => 'Bitte prüfen, ob der Bonus bereits ausgezahlt wurde.',
                'type' => 'chat',
            ])->assertSessionHas('success');

        $this->assertDatabaseHas('internal_messages', [
            'customer_id' => (string) $customer->id,
            'sender_id' => $employee->id,
            'type' => 'chat',
        ]);

        // Erstellen wird im Audit-Log protokolliert
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $employee->id,
            'action' => 'internal_message_created',
        ]);
    }

    public function test_internal_note_type_is_stored(): void
    {
        $admin = $this->staff('admin');
        $customer = $this->makeCustomer();

        $this->actingAs($admin)->post(route('admin.internal.store', $customer->id), [
            'message' => 'Kunde bevorzugt Kontakt per WhatsApp.',
            'type' => 'note',
        ]);

        $this->assertDatabaseHas('internal_messages', ['type' => 'note', 'customer_id' => (string) $customer->id]);
    }

    // ---------------------------------------------------------------
    // Rollen: admin/manager sehen alles, support/employee nur Zuweisung
    // ---------------------------------------------------------------

    public function test_admin_and_manager_can_post_to_any_customer(): void
    {
        $customer = $this->makeCustomer();

        foreach (['admin', 'manager'] as $role) {
            $this->actingAs($this->staff($role))
                ->post(route('admin.internal.store', $customer->id), ['message' => 'Test ' . $role, 'type' => 'chat'])
                ->assertSessionHas('success');
        }

        $this->assertSame(2, InternalMessage::count());
    }

    public function test_assigned_support_user_can_post_and_unassigned_cannot(): void
    {
        $customer = $this->makeCustomer();

        $assigned = $this->staff('support');
        $assigned->assignedCustomers()->attach((string) $customer->id);
        $this->actingAs($assigned)
            ->post(route('admin.internal.store', $customer->id), ['message' => 'Support hier.', 'type' => 'chat'])
            ->assertSessionHas('success');

        $unassigned = $this->staff('support');
        $this->actingAs($unassigned)
            ->post(route('admin.internal.store', $customer->id), ['message' => 'Darf nicht.', 'type' => 'chat'])
            ->assertForbidden();

        $this->assertSame(1, InternalMessage::count());
    }

    // ---------------------------------------------------------------
    // Mitarbeiter ohne Berechtigung kann den internen Bereich nicht öffnen
    // ---------------------------------------------------------------

    public function test_unassigned_employee_cannot_open_or_post_internal_area(): void
    {
        $employee = $this->staff('employee'); // keine Zuweisung, kein sees-all
        $customer = $this->makeCustomer();

        // Kundenprofil (enthält den Intern-Tab) -> 403
        $this->actingAs($employee)
            ->get(route('admin.customer', $customer->id))
            ->assertForbidden();

        // Direkter POST -> 403, nichts gespeichert
        $this->actingAs($employee)
            ->post(route('admin.internal.store', $customer->id), ['message' => 'x', 'type' => 'chat'])
            ->assertForbidden();

        $this->assertSame(0, InternalMessage::count());
    }

    // ---------------------------------------------------------------
    // Kunde kann interne Nachrichten NIEMALS sehen
    // ---------------------------------------------------------------

    public function test_customer_cannot_reach_any_internal_endpoint(): void
    {
        $customer = $this->makeCustomer();
        InternalMessage::create([
            'customer_id' => $customer->id,
            'sender_id' => $this->staff('admin')->id,
            'message' => 'STRENG-INTERN-GEHEIM',
            'type' => 'chat',
        ]);

        // Kunde (der Betroffene selbst!) wird von allen Endpunkten weggeleitet
        $this->actingAs($customer->user)->get(route('admin.customer', $customer->id))
            ->assertRedirect(route('portal.dashboard'));
        $this->actingAs($customer->user)->post(route('admin.internal.store', $customer->id), ['message' => 'x', 'type' => 'chat'])
            ->assertRedirect(route('portal.dashboard'));
        $this->actingAs($customer->user)->get(route('admin.notifications'))
            ->assertRedirect(route('portal.dashboard'));

        $this->assertSame(1, InternalMessage::count());
    }

    public function test_internal_content_never_appears_in_customer_portal(): void
    {
        $customer = $this->makeCustomer();
        InternalMessage::create([
            'customer_id' => $customer->id,
            'sender_id' => $this->staff('admin')->id,
            'message' => 'STRENG-INTERN-GEHEIM',
            'type' => 'note',
        ]);

        foreach (['portal.dashboard', 'portal.profile', 'portal.tickets', 'portal.contracts'] as $routeName) {
            $this->actingAs($customer->user)->get(route($routeName))
                ->assertOk()
                ->assertDontSee('STRENG-INTERN-GEHEIM');
        }
    }

    public function test_internal_ticket_messages_stay_hidden_from_customer(): void
    {
        $customer = $this->makeCustomer();
        $admin = $this->staff('admin');
        $ticket = Ticket::create([
            'customer_id' => $customer->id,
            'type' => 'other',
            'status' => 'open',
            'subject' => 'Adressänderung',
            'description' => 'Ich habe meine Adresse geändert.',
        ]);
        TicketMessage::create(['ticket_id' => $ticket->id, 'sender_id' => $admin->id, 'body' => 'Vielen Dank, wir kümmern uns.', 'is_internal' => false]);
        TicketMessage::create(['ticket_id' => $ticket->id, 'sender_id' => $admin->id, 'body' => 'INTERN: Adresse auch bei allen Verträgen aktualisieren.', 'is_internal' => true]);

        $this->actingAs($customer->user)
            ->get(route('portal.tickets.show', $ticket->id))
            ->assertOk()
            ->assertSee('Vielen Dank, wir kümmern uns.')
            ->assertDontSee('INTERN: Adresse auch bei allen Verträgen aktualisieren.');
    }

    // ---------------------------------------------------------------
    // Mentions & Benachrichtigungen
    // ---------------------------------------------------------------

    public function test_name_mention_notifies_mentioned_user_but_not_sender(): void
    {
        $sender = $this->staff('admin');
        $anna = User::factory()->create(['role' => 'employee', 'name' => 'Anna Schmidt', 'can_see_all_customers' => true]);
        $customer = $this->makeCustomer();

        $this->actingAs($sender)->post(route('admin.internal.store', $customer->id), [
            'message' => '@Anna Kannst du den Kunden zurückrufen?',
            'type' => 'chat',
        ]);

        $this->assertSame(1, InternalNotification::count());
        $this->assertDatabaseHas('internal_notifications', ['user_id' => $anna->id]);
        $this->assertDatabaseMissing('internal_notifications', ['user_id' => $sender->id]);
    }

    public function test_role_mention_notifies_all_active_users_of_that_role(): void
    {
        $sender = $this->staff('admin');
        $s1 = $this->staff('support');
        $s2 = $this->staff('support');
        $inactive = User::factory()->create(['role' => 'support', 'is_active' => false]);
        $customer = $this->makeCustomer();

        $this->actingAs($sender)->post(route('admin.internal.store', $customer->id), [
            'message' => '@Support Bitte prüfen, warum die DEVK Police noch nicht aktiviert wurde.',
            'type' => 'chat',
        ]);

        $this->assertSame(2, InternalNotification::count());
        $this->assertDatabaseHas('internal_notifications', ['user_id' => $s1->id]);
        $this->assertDatabaseHas('internal_notifications', ['user_id' => $s2->id]);
        $this->assertDatabaseMissing('internal_notifications', ['user_id' => $inactive->id]);
    }

    public function test_notifications_are_scoped_to_their_owner(): void
    {
        $owner = $this->staff('admin');
        $other = $this->staff('admin');
        $customer = $this->makeCustomer();
        $msg = InternalMessage::create(['customer_id' => $customer->id, 'sender_id' => $other->id, 'message' => 'x', 'type' => 'chat']);
        $notification = InternalNotification::create(['user_id' => $owner->id, 'message_id' => $msg->id]);

        // Fremder Nutzer kann fremde Benachrichtigung nicht als gelesen markieren
        $this->actingAs($other)
            ->post(route('admin.notifications.read', $notification->id))
            ->assertNotFound();

        $this->actingAs($owner)
            ->post(route('admin.notifications.read', $notification->id))
            ->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);
    }

    // ---------------------------------------------------------------
    // Löschen & Audit-Log
    // ---------------------------------------------------------------

    public function test_author_can_soft_delete_with_audit_log_but_colleague_cannot(): void
    {
        $author = $this->staff('employee', seesAll: true);
        $colleague = $this->staff('employee', seesAll: true);
        $customer = $this->makeCustomer();
        $msg = InternalMessage::create(['customer_id' => $customer->id, 'sender_id' => $author->id, 'message' => 'Zum Löschen', 'type' => 'chat']);

        // Kollege (gleicher Kundenzugriff, aber nicht Autor/admin/manager) -> 403
        $this->actingAs($colleague)->delete(route('admin.internal.destroy', $msg->id))->assertForbidden();

        // Autor -> Soft-Delete + deleted_by + Audit-Log
        $this->actingAs($author)->delete(route('admin.internal.destroy', $msg->id))->assertSessionHas('success');
        $this->assertSoftDeleted('internal_messages', ['id' => $msg->id]);
        $this->assertSame($author->id, $msg->fresh()->deleted_by);
        $this->assertDatabaseHas('activity_logs', ['action' => 'internal_message_deleted', 'user_id' => $author->id]);
    }

    public function test_admin_can_delete_any_internal_message(): void
    {
        $author = $this->staff('employee', seesAll: true);
        $admin = $this->staff('admin');
        $customer = $this->makeCustomer();
        $msg = InternalMessage::create(['customer_id' => $customer->id, 'sender_id' => $author->id, 'message' => 'x', 'type' => 'chat']);

        $this->actingAs($admin)->delete(route('admin.internal.destroy', $msg->id))->assertSessionHas('success');
        $this->assertSoftDeleted('internal_messages', ['id' => $msg->id]);
    }

    // ---------------------------------------------------------------
    // Support-Rolle: kann den Admin-Bereich überhaupt betreten (Fix)
    // ---------------------------------------------------------------

    public function test_support_role_can_access_admin_dashboard(): void
    {
        $support = $this->staff('support');

        $this->actingAs($support)
            ->get(route('admin.dashboard'))
            ->assertOk();
    }
}
