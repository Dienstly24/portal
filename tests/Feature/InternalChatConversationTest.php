<?php

namespace Tests\Feature;

use App\Models\InternalConversation;
use App\Models\InternalConversationMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalChatConversationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    // Mitarbeiter A kann Support kontaktieren
    public function test_employee_can_start_conversation_with_support(): void
    {
        $employee = $this->staff('employee');
        $support = $this->staff('support');

        $this->actingAs($employee)->post(route('admin.chat.store'), [
            'subject' => 'DEVK Police prüfen',
            'participants' => [$support->id],
            'body' => 'Kannst du bitte die Aktivierung prüfen?',
        ])->assertRedirect();

        $conversation = InternalConversation::first();
        $this->assertNotNull($conversation);
        $this->assertTrue($conversation->hasParticipant($employee->id));
        $this->assertTrue($conversation->hasParticipant($support->id));
        $this->assertDatabaseHas('internal_conversation_messages', ['body' => 'Kannst du bitte die Aktivierung prüfen?']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'internal_conversation_created']);
    }

    // Support kann antworten
    public function test_support_participant_can_reply(): void
    {
        $employee = $this->staff('employee');
        $support = $this->staff('support');
        $this->actingAs($employee)->post(route('admin.chat.store'), [
            'subject' => 'Rückruf', 'participants' => [$support->id], 'body' => 'Bitte Kunde zurückrufen.',
        ]);
        $conversation = InternalConversation::first();

        $this->actingAs($support)->post(route('admin.chat.reply', $conversation->id), [
            'body' => 'Erledigt, Kunde wurde informiert.',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('internal_conversation_messages', [
            'conversation_id' => $conversation->id,
            'sender_id' => $support->id,
            'body' => 'Erledigt, Kunde wurde informiert.',
        ]);
    }

    // Mitarbeiter ohne Rechte (Nicht-Teilnehmer) bekommt keinen Zugriff
    public function test_non_participant_cannot_view_or_reply(): void
    {
        $employee = $this->staff('employee');
        $support = $this->staff('support');
        $outsider = $this->staff('employee');
        $this->actingAs($employee)->post(route('admin.chat.store'), [
            'subject' => 'Vertraulich', 'participants' => [$support->id], 'body' => 'Nur für uns.',
        ]);
        $conversation = InternalConversation::first();

        $this->actingAs($outsider)->get(route('admin.chat.show', $conversation->id))->assertForbidden();
        $this->actingAs($outsider)->post(route('admin.chat.reply', $conversation->id), ['body' => 'Reinquatschen'])->assertForbidden();
        $this->assertDatabaseMissing('internal_conversation_messages', ['body' => 'Reinquatschen']);
    }

    // Kunde sieht nichts / hat keinen Zugriff
    public function test_customer_cannot_access_internal_chat_at_all(): void
    {
        $employee = $this->staff('employee');
        $support = $this->staff('support');
        $this->actingAs($employee)->post(route('admin.chat.store'), [
            'subject' => 'GEHEIM-INTERN', 'participants' => [$support->id], 'body' => 'STRENG-INTERN-INHALT',
        ]);
        $conversation = InternalConversation::first();

        $customerUser = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customerUser)->get(route('admin.chat.index'))->assertRedirect(route('portal.dashboard'));
        $this->actingAs($customerUser)->get(route('admin.chat.show', $conversation->id))->assertRedirect(route('portal.dashboard'));
        $this->actingAs($customerUser)->post(route('admin.chat.reply', $conversation->id), ['body' => 'x'])->assertRedirect(route('portal.dashboard'));
    }

    // Team-Auswahl fügt nur Staff hinzu, niemals Kunden
    public function test_team_selection_adds_only_staff_never_customers(): void
    {
        $admin = $this->staff('admin');
        $s1 = $this->staff('support');
        $s2 = $this->staff('support');
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($admin)->post(route('admin.chat.store'), [
            'subject' => 'Team-Broadcast',
            'participants' => [$s1->id],
            'team' => 'support',
            'body' => 'An den ganzen Support.',
        ]);

        $conversation = InternalConversation::first();
        $this->assertTrue($conversation->hasParticipant($s1->id));
        $this->assertTrue($conversation->hasParticipant($s2->id));
        $this->assertFalse($conversation->hasParticipant($customer->id));
    }

    // Ticketantworten sind immer kundensichtbar - keine is_internal-Falle mehr
    public function test_ticket_reply_is_never_internal(): void
    {
        $admin = $this->staff('admin');
        $customerUser = User::factory()->create(['role' => 'customer']);
        $customer = \App\Models\Customer::create([
            'user_id' => $customerUser->id,
            'customer_number' => 'C-TCK1',
        ]);
        $ticket = \App\Models\Ticket::create([
            'customer_id' => $customer->id, 'type' => 'other', 'status' => 'open',
            'subject' => 'Frage', 'description' => 'Test',
        ]);

        // Auch wenn ein Angreifer is_internal mitschickt: es wird ignoriert
        $this->actingAs($admin)->post(route('admin.ticket.reply', $ticket->id), [
            'body' => 'Antwort an Kunde', 'status' => 'open', 'is_internal' => '1',
        ]);

        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'body' => 'Antwort an Kunde',
            'is_internal' => false,
        ]);
    }
}
