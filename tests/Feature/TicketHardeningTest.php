<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InternalNotification;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Haertung des Ticketsystems nach dem Code-Audit: private Uploads,
 * Gast-Ticket-Zugriff, Badge-Semantik, Benachrichtigungs-Luecken,
 * Workflow-Randfaelle, Validierung und Datenintegritaet.
 */
class TicketHardeningTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function makeCustomer(): Customer
    {
        $n = ++self::$seq;
        $user = User::factory()->create(['role' => 'customer', 'email' => "kunde{$n}@haertung.de", 'name' => "Kunde {$n}"]);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26002' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
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

    private function makeGuestTicket(): Ticket
    {
        return Ticket::create([
            'customer_id' => null,
            'type' => 'other',
            'status' => 'open',
            'subject' => 'Lead-Anfrage',
            'description' => 'Bitte um Kontakt.',
            'source' => 'website',
            'guest_name' => 'Lena Lead',
            'guest_email' => 'lena@lead.de',
            'guest_phone' => '+49 111 222',
        ]);
    }

    // ---------------- HOCH: private Uploads ----------------

    public function test_portal_created_attachments_are_stored_privately(): void
    {
        Storage::fake('local');
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)->post(route('portal.tickets.store'), [
            'type' => 'damage',
            'priority' => 'mittel',
            'subject' => 'Schaden mit Foto',
            'description' => 'Siehe Anhang.',
            'attachments' => [UploadedFile::fake()->create('unfall.pdf', 100, 'application/pdf')],
        ])->assertRedirect(route('portal.tickets'));

        $att = TicketAttachment::first();
        $this->assertNotNull($att);
        $this->assertSame('local', $att->disk, 'Kunden-Uploads muessen auf der privaten Disk liegen.');
        Storage::disk('local')->assertExists($att->file_path);
    }

    public function test_move_command_relocates_public_attachments(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $ticket = $this->makeTicket($this->makeCustomer());
        Storage::disk('public')->put('tickets/' . $ticket->id . '/alt.pdf', 'INHALT');
        $att = TicketAttachment::create([
            'id' => (string) Str::uuid(),
            'ticket_id' => $ticket->id,
            'file_name' => 'alt.pdf',
            'file_path' => 'tickets/' . $ticket->id . '/alt.pdf',
            'disk' => 'public',
        ]);

        $this->artisan('tickets:attachments-private')->assertExitCode(0);

        $att->refresh();
        $this->assertSame('local', $att->disk);
        Storage::disk('local')->assertExists($att->file_path);
        Storage::disk('public')->assertMissing($att->file_path);
    }

    // ---------------- HOCH: Gast-Tickets nur admin/manager/support ----------------

    public function test_employee_cannot_open_guest_ticket_or_inquiries(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_manage_tickets' => true]);
        $ticket = $this->makeGuestTicket();

        $this->actingAs($employee)->get(route('admin.ticket', $ticket->id))->assertForbidden();
        $this->actingAs($employee)->post(route('admin.ticket.status', $ticket->id), ['status' => 'closed'])->assertForbidden();
        // Anfragen-Liste: Rollen-Middleware leitet um
        $this->actingAs($employee)->get(route('admin.inquiries'))->assertRedirect(route('admin.dashboard'));
        $this->assertSame('open', $ticket->refresh()->status);
    }

    public function test_support_can_still_work_on_guest_tickets(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $ticket = $this->makeGuestTicket();

        $this->actingAs($support)->get(route('admin.ticket', $ticket->id))->assertOk();
        $this->actingAs($support)->get(route('admin.inquiries'))->assertOk()->assertSee('Lena Lead');
    }

    public function test_employee_cannot_download_guest_ticket_attachment(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_manage_tickets' => true]);
        $ticket = $this->makeGuestTicket();
        $att = TicketAttachment::create([
            'id' => (string) Str::uuid(),
            'ticket_id' => $ticket->id,
            'file_name' => 'lead.pdf',
            'file_path' => 'tickets/' . $ticket->id . '/lead.pdf',
            'disk' => 'local',
        ]);

        $this->actingAs($employee)->get(route('admin.attachment.download', $att->id))->assertForbidden();
    }

    // ---------------- Badge: nur NEUE Kundentickets ----------------

    public function test_nav_badge_counts_only_new_customer_tickets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $this->makeTicket($customer);                                  // open -> zaehlt
        $this->makeTicket($customer, ['status' => 'in_progress']);     // uebernommen -> zaehlt nicht
        $this->makeGuestTicket();                                      // Gast -> zaehlt nicht

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('nav-badge">1</span>', false);
    }

    // ---------------- Benachrichtigungen ----------------

    public function test_double_status_submit_sends_only_one_customer_bell(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->makeTicket($this->makeCustomer());

        $this->actingAs($admin)->post(route('admin.ticket.status', $ticket->id), ['status' => 'closed']);
        $this->actingAs($admin)->post(route('admin.ticket.status', $ticket->id), ['status' => 'closed']);

        $this->assertSame(1, InternalNotification::where('user_id', $ticket->customer->user_id)
            ->where('title', 'Status Ihrer Anfrage')->count());
    }

    public function test_first_take_notification_does_not_say_wieder(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->makeTicket($this->makeCustomer());

        $this->actingAs($admin)->post(route('admin.ticket.status', $ticket->id), ['status' => 'in_progress']);

        $note = InternalNotification::where('user_id', $ticket->customer->user_id)
            ->where('title', 'Status Ihrer Anfrage')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('jetzt in Bearbeitung', $note->body);
        $this->assertStringNotContainsString('wieder', $note->body);
    }

    public function test_reopening_notification_says_wieder(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->makeTicket($this->makeCustomer());
        $ticket->transitionTo('closed', $admin->id);

        $this->actingAs($admin)->post(route('admin.ticket.status', $ticket->id), ['status' => 'open']);

        $note = InternalNotification::where('user_id', $ticket->customer->user_id)
            ->where('title', 'Status Ihrer Anfrage')->latest()->first();
        $this->assertStringContainsString('wieder geöffnet', $note->body);
    }

    public function test_waiting_status_notifies_customer(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->makeTicket($this->makeCustomer());

        $this->actingAs($admin)->post(route('admin.ticket.status', $ticket->id), ['status' => 'waiting']);

        $note = InternalNotification::where('user_id', $ticket->customer->user_id)
            ->where('title', 'Status Ihrer Anfrage')->first();
        $this->assertNotNull($note, 'Kunde muss bei "Wartet auf Kunde" benachrichtigt werden.');
        $this->assertStringContainsString('Rückmeldung benötigt', $note->body);
    }

    public function test_customer_reply_notifies_assigned_agent(): void
    {
        // Support-Mitarbeiter ist zugewiesen, aber NICHT Betreuer des Kunden
        $agent = User::factory()->create(['role' => 'support', 'name' => 'Sam Support']);
        $customer = $this->makeCustomer();
        $ticket = $this->makeTicket($customer, ['assigned_to' => $agent->id]);

        $this->actingAs($customer->user)->post(route('portal.tickets.reply', $ticket->id), [
            'body' => 'Hier meine Rueckmeldung.',
        ]);

        $this->assertNotNull(InternalNotification::where('user_id', $agent->id)
            ->where('title', '💬 Neue Ticket-Antwort')->first(),
            'Der zugewiesene Bearbeiter muss die Kundenantwort mitbekommen.');
    }

    public function test_website_inquiry_creates_team_bell(): void
    {
        config(['services.inquiry.token' => 'geheim123']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->postJson('/api/website-inquiry', [
            'name' => 'Willi Web',
            'email' => 'willi@web.de',
            'message' => 'Bitte Angebot senden.',
        ], ['X-Inquiry-Token' => 'geheim123'])->assertOk();

        $this->assertNotNull(InternalNotification::where('user_id', $admin->id)
            ->where('title', '🎫 Neue Support-Anfrage')->first(),
            'Website-Leads muessen eine Team-Glocke erzeugen.');
    }

    // ---------------- Workflow-Randfaelle ----------------

    public function test_closed_to_resolved_clears_closing_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->makeTicket($this->makeCustomer());
        $ticket->transitionTo('closed', $admin->id);

        $this->actingAs($admin)->post(route('admin.ticket.status', $ticket->id), ['status' => 'resolved']);

        $ticket->refresh();
        $this->assertSame('resolved', $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertNull($ticket->closed_at, 'closed_at darf nach Verlassen von "geschlossen" nicht stehen bleiben.');
        $this->assertNull($ticket->closed_by);
    }

    public function test_admin_cannot_reply_to_closed_ticket(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->makeTicket($this->makeCustomer());
        $ticket->transitionTo('closed', $admin->id);

        $this->actingAs($admin)->post(route('admin.ticket.reply', $ticket->id), [
            'body' => 'Nachtrag', 'status' => 'closed',
        ]);

        $this->assertSame(0, TicketMessage::where('ticket_id', $ticket->id)->count());
    }

    // ---------------- Validierung ----------------

    public function test_portal_rejects_unknown_ticket_type(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)->from(route('portal.tickets.create'))
            ->post(route('portal.tickets.store'), [
                'type' => 'kaputt',
                'priority' => 'mittel',
                'subject' => 'x',
                'description' => 'y',
            ])->assertSessionHasErrors('type');

        $this->assertSame(0, Ticket::count());
    }

    public function test_manual_inquiry_rejects_unknown_priority(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->from(route('admin.inquiries.create'))
            ->post(route('admin.inquiries.store'), [
                'name' => 'Max', 'subject' => 'Test', 'message' => 'Inhalt',
                'priority' => 'superduperdringend',
            ])->assertSessionHasErrors('priority');
    }

    // ---------------- Hilfe-Formular ----------------

    public function test_thanks_page_shows_unified_ticket_number(): void
    {
        $customer = $this->makeCustomer();

        $response = $this->post('/hilfe', [
            't' => \App\Http\Controllers\SupportFormController::tokenFor($customer),
            'leistung' => 'login',
            'message' => 'Frage zum Login.',
        ])->assertOk();

        $response->assertSee(Ticket::first()->ticket_number);
    }

    public function test_thanks_page_does_not_reveal_customer_match_to_guests(): void
    {
        $customer = $this->makeCustomer();
        $email = $customer->user->email;

        // Gast reicht mit der E-Mail eines Bestandskunden ein: intern wird
        // zugeordnet, aber die Antwortseite darf es nicht verraten.
        $response = $this->post('/hilfe', [
            'name' => 'Anonymer Gast',
            'email' => $email,
            'leistung' => 'angebot',
            'message' => 'Bitte Angebot.',
        ])->assertOk();

        $this->assertSame((string) $customer->id, (string) Ticket::first()->customer_id, 'Interne Zuordnung bleibt bestehen.');
        $response->assertSee('Wir melden uns per E-Mail bei Ihnen.');
        $response->assertDontSee('im Kundenportal unter');
    }

    // ---------------- Datenintegritaet & Zuweisung ----------------

    public function test_deleting_employee_keeps_ticket_replies(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $worker = User::factory()->create(['role' => 'employee']);
        $customer = $this->makeCustomer();
        $ticket = $this->makeTicket($customer);
        TicketMessage::create([
            'id' => (string) Str::uuid(),
            'ticket_id' => $ticket->id,
            'sender_id' => $worker->id,
            'body' => 'Wichtige Antwort an den Kunden',
            'is_internal' => false,
        ]);

        $this->actingAs($admin)->delete(route('admin.employees.destroy', $worker->id));

        $msg = TicketMessage::where('ticket_id', $ticket->id)->first();
        $this->assertNotNull($msg, 'Antworten duerfen beim Loeschen des Mitarbeiters nicht verschwinden.');
        $this->assertNull($msg->sender_id);
        // Portal-Ansicht rendert mit Fallback-Namen
        $this->actingAs($customer->user)->get(route('portal.tickets.show', $ticket->id))
            ->assertOk()->assertSee('Dienstly24 Team');
    }

    public function test_inactive_staff_cannot_be_assigned(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $inactive = User::factory()->create(['role' => 'employee', 'name' => 'Ivo Inaktiv', 'is_active' => false]);
        $ticket = $this->makeTicket($this->makeCustomer());

        $this->actingAs($admin)->post(route('admin.ticket.update', $ticket->id), ['assigned_to' => $inactive->id]);

        $this->assertNull($ticket->refresh()->assigned_to);
        // ... und taucht im Zuweisungs-Dropdown nicht auf
        $this->actingAs($admin)->get(route('admin.ticket', $ticket->id))->assertDontSee('Ivo Inaktiv');
    }
}
