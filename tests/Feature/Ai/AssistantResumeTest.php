<?php

namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiConversationEvent;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\SystemSetting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\Assistant\ConversationResumeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wiederaufnahme des Assistenten nach einer Uebernahme
 * (Betreiber-Vorgabe 20.08.2026).
 *
 * Gemeldeter Fall: die KI uebergibt, der Mitarbeiter erledigt den Fall -
 * und der Kunde bekommt danach NIE wieder eine automatische Antwort, auch
 * nicht auf eine voellig neue Frage Tage spaeter. Diese Tests halten die
 * neue Regel fest: eine Uebernahme gilt dem VORGANG, nicht dem Kunden.
 */
class AssistantResumeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set('ai_assistant_auto_reply', '1');
        SystemSetting::set('ai_assistant_auto_resume', '1');
        SystemSetting::set('ai_assistant_resume_quiet_hours', '24');
    }

    private function resume(): ConversationResumeService
    {
        return app(ConversationResumeService::class);
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'email' => 'kunde'.uniqid().'@example.de',
            'name' => 'Naem Alawad',
        ]);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
        ]);
    }

    private function makeTicket(Customer $customer, string $status = 'open'): Ticket
    {
        return Ticket::create([
            'customer_id' => $customer->id,
            'type' => 'question',
            'status' => $status,
            'subject' => 'Keine Stromrechnung erhalten',
            'description' => 'Seit 8 Monaten keine Rechnung.',
            'priority' => 'mittel',
            'source' => 'portal',
        ]);
    }

    // ------------------------------------------------------------------

    public function test_waehrend_der_ruhefrist_bleibt_der_mitarbeiter_zustaendig(): void
    {
        $employee = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $ticket = $this->makeTicket($customer);

        $conversation = AiConversation::forCustomer($customer->id);
        $conversation->takeOver($employee->id, $ticket->id, 24);

        $this->assertFalse($this->resume()->resumeIfDue($customer, $conversation));
        $this->assertFalse($conversation->fresh()->ai_active);
    }

    public function test_abgeschlossener_vorgang_gibt_die_ki_sofort_wieder_frei(): void
    {
        $employee = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $ticket = $this->makeTicket($customer);

        $conversation = AiConversation::forCustomer($customer->id);
        $conversation->takeOver($employee->id, $ticket->id, 24);

        // Der Mitarbeiter erklaert den Fall fuer erledigt.
        $ticket->update(['status' => 'closed']);

        $this->assertTrue($this->resume()->resumeIfDue($customer, $conversation));

        $frisch = $conversation->fresh();
        $this->assertTrue($frisch->ai_active);
        $this->assertFalse($frisch->handover_required);
        $this->assertNotNull($frisch->resumed_at, 'Die automatische Wiederaufnahme muss erkennbar bleiben.');

        // Und sie steht im Ereignisprotokoll - nicht im Chattext.
        $this->assertDatabaseHas('ai_conversation_events', [
            'conversation_id' => $conversation->id,
            'event' => AiConversationEvent::EVENT_RESUMED,
            'actor' => AiConversationEvent::ACTOR_SYSTEM,
        ]);
    }

    public function test_abgelaufene_ruhefrist_holt_die_ki_zurueck_auch_ohne_geschlossenen_vorgang(): void
    {
        $employee = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $ticket = $this->makeTicket($customer);

        $conversation = AiConversation::forCustomer($customer->id);
        $conversation->takeOver($employee->id, $ticket->id, 24);
        // Niemand hat den Vorgang geschlossen, aber es ist zwei Tage still.
        $conversation->forceFill(['resume_not_before' => now()->subDay()])->save();

        $this->assertTrue($this->resume()->resumeIfDue($customer, $conversation->fresh()));
        $this->assertTrue($conversation->fresh()->ai_active);
    }

    public function test_jede_mitarbeiter_nachricht_verlaengert_die_ruhefrist(): void
    {
        $employee = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();

        $conversation = AiConversation::forCustomer($customer->id);
        $conversation->takeOver($employee->id, null, 24);
        $conversation->forceFill(['resume_not_before' => now()->subHour()])->save();

        // Der Mitarbeiter schreibt dem Kunden - die Frist beginnt neu.
        CustomerMessage::create([
            'customer_id' => $customer->id,
            'sender_id' => $employee->id,
            'body' => 'Ich klaere das mit dem Versorger und melde mich.',
            'from_staff' => true,
        ]);

        $frisch = $conversation->fresh();
        $this->assertTrue($frisch->resume_not_before->isFuture());
        $this->assertFalse($this->resume()->resumeIfDue($customer, $frisch));
        $this->assertFalse($conversation->fresh()->ai_active);
    }

    public function test_eine_ki_antwort_verlaengert_die_frist_nicht(): void
    {
        $employee = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();

        $conversation = AiConversation::forCustomer($customer->id);
        $conversation->takeOver($employee->id, null, 24);
        $conversation->forceFill(['resume_not_before' => now()->subHour()])->save();

        CustomerMessage::create([
            'customer_id' => $customer->id,
            'sender_id' => null,
            'body' => 'Automatische Antwort.',
            'from_staff' => true,
            'ai_generated' => true,
        ]);

        $this->assertTrue($conversation->fresh()->resume_not_before->isPast());
    }

    public function test_beschwerde_bleibt_dauerhaft_beim_team(): void
    {
        $customer = $this->makeCustomer();
        $conversation = AiConversation::forCustomer($customer->id);
        $conversation->markHandover(AiConversation::REASON_COMPLAINT, 'Kunde ist veraergert.');
        $conversation->forceFill([
            'ai_active' => false,
            'resume_not_before' => now()->subWeek(),
        ])->save();

        $this->assertFalse($conversation->fresh()->mayAutoResume());
        $this->assertFalse($this->resume()->resumeIfDue($customer, $conversation->fresh()));
        $this->assertFalse($conversation->fresh()->ai_active);
    }

    public function test_bewusstes_deaktivieren_wirkt_dauerhaft(): void
    {
        $customer = $this->makeCustomer();
        $conversation = AiConversation::forCustomer($customer->id);
        $conversation->deactivate();

        $this->assertFalse($conversation->fresh()->auto_resume);
        $this->assertFalse($this->resume()->resumeIfDue($customer, $conversation->fresh()));

        // Nur der bewusste Knopf holt sie zurueck.
        $conversation->fresh()->reactivate();
        $this->assertTrue($conversation->fresh()->ai_active);
        $this->assertTrue($conversation->fresh()->auto_resume);
    }

    public function test_abgeschalteter_schalter_haelt_das_alte_verhalten(): void
    {
        SystemSetting::set('ai_assistant_auto_resume', '0');

        $employee = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $ticket = $this->makeTicket($customer);

        $conversation = AiConversation::forCustomer($customer->id);
        $conversation->takeOver($employee->id, $ticket->id, 24);
        $ticket->update(['status' => 'closed']);

        $this->assertFalse($this->resume()->resumeIfDue($customer, $conversation->fresh()));
        $this->assertFalse($conversation->fresh()->ai_active);
    }

    public function test_fremdes_ticket_gibt_die_ki_nie_frei(): void
    {
        $employee = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $fremder = $this->makeCustomer();
        $fremdesTicket = $this->makeTicket($fremder, 'closed');

        $conversation = AiConversation::forCustomer($customer->id);
        $conversation->takeOver($employee->id, $fremdesTicket->id, 24);

        $this->assertNull($this->resume()->dueReason($customer, $conversation->fresh()));
    }

    public function test_panel_nennt_den_zeitpunkt_der_wiederaufnahme(): void
    {
        // Die Seite MUSS sagen, ab wann die KI wieder einspringt - "KI
        // deaktiviert" ohne Zeitangabe war die Ursache der Meldung. Der
        // Test rendert die echte Seite und faengt damit auch Blade-Fehler.
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $ticket = $this->makeTicket($customer);

        CustomerMessage::create([
            'customer_id' => $customer->id,
            'sender_id' => $customer->user_id,
            'body' => 'Ich habe eine Frage zum Strom.',
            'from_staff' => false,
        ]);

        AiConversation::forCustomer($customer->id)->takeOver($admin->id, $ticket->id, 24);

        $this->actingAs($admin)
            ->get(route('admin.customer_chat', ['kunde' => (string) $customer->id]))
            ->assertOk()
            ->assertSee('Die KI übernimmt wieder', false)
            ->assertSee('sobald der Vorgang abgeschlossen ist', false);
    }

    public function test_panel_zeigt_dauerhafte_abschaltung_als_solche(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        CustomerMessage::create([
            'customer_id' => $customer->id,
            'sender_id' => $customer->user_id,
            'body' => 'Frage.',
            'from_staff' => false,
        ]);

        AiConversation::forCustomer($customer->id)->deactivate();

        $this->actingAs($admin)
            ->get(route('admin.customer_chat', ['kunde' => (string) $customer->id]))
            ->assertOk()
            ->assertSee('Dauerhaft beim Team', false);
    }

    public function test_alte_uebernahme_ohne_frist_wird_nach_der_ruhefrist_freigegeben(): void
    {
        // Bestand aus der Zeit vor dieser Funktion: ai_active = false,
        // keine resume_not_before. Massstab ist dann die letzte
        // Mitarbeiter-Nachricht bzw. der Uebergabezeitpunkt.
        $employee = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();

        $conversation = AiConversation::forCustomer($customer->id);
        $conversation->forceFill([
            'ai_active' => false,
            'handover_reason' => AiConversation::REASON_STAFF,
            'handover_at' => now()->subDays(5),
            'assigned_employee_id' => $employee->id,
            'resume_not_before' => null,
        ])->save();

        $this->assertTrue($this->resume()->resumeIfDue($customer, $conversation->fresh()));
        $this->assertTrue($conversation->fresh()->ai_active);
    }
}
