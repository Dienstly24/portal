<?php

namespace Tests\Feature\Messaging;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\User;
use App\Services\Messaging\AssignmentService;
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\Dto\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Betreuer und Zustaendigkeit (Auftrag Abschnitte 7-11).
 *
 * DIE ENTSCHEIDENDE TRENNUNG: der Betreuer gehoert zum KUNDEN, die
 * Zustaendigkeit zur UNTERHALTUNG. Uebernimmt der Support einen
 * Vorgang, wechselt die Zustaendigkeit - der Betreuer bleibt. Diese
 * Faelle halten genau das fest.
 */
class ConversationAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => 'k'.uniqid().'@example.de']);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
            'phone' => '0170 5550001',
        ]);
    }

    private function mitarbeiter(string $rolle = 'employee'): User
    {
        return User::factory()->create(['role' => $rolle, 'email' => $rolle.uniqid().'@dienstly24.de']);
    }

    private function eingang(Customer $kunde, string $msg = 'm1'): ?CustomerMessage
    {
        return app(ConversationEngine::class)->handleInbound(
            new InboundMessage(
                externalUserId: '491705550001',
                externalMessageId: $msg,
                text: 'Ich habe eine Frage.',
                senderPhone: '0170 5550001',
            ),
            Channel::where('key', Channel::PORTAL)->firstOrFail()
        );
    }

    /**
     * Fall 1: Der Betreuer ist der PRIMAERE Eintrag - `employee_customers`
     * bleibt N:M, damit Sichtbarkeit und Vertretung unveraendert
     * funktionieren.
     */
    public function test_primaerer_eintrag_ist_der_betreuer(): void
    {
        $kunde = $this->kunde();
        $ahmed = $this->mitarbeiter();
        $sara = $this->mitarbeiter();

        $kunde->betreuer()->attach($sara->id, ['is_primary' => false]);
        $kunde->betreuer()->attach($ahmed->id, ['is_primary' => true]);

        $this->assertSame($ahmed->id, $kunde->fresh()->betreuerPrimary()->id);
        // Sichtbarkeit bleibt fuer BEIDE bestehen.
        $this->assertCount(2, $kunde->fresh()->betreuer);
    }

    /**
     * Fall 2: Der Bestand ist nicht markiert - dort gilt der aelteste
     * Eintrag. "Kein Betreuer" waere fuer den Altbestand schlicht falsch.
     */
    public function test_ohne_markierung_gilt_der_aelteste_eintrag(): void
    {
        $kunde = $this->kunde();
        $erster = $this->mitarbeiter();
        $zweiter = $this->mitarbeiter();
        $kunde->betreuer()->attach($erster->id);
        $kunde->betreuer()->attach($zweiter->id);

        $this->assertSame($erster->id, $kunde->fresh()->betreuerPrimary()->id);
    }

    /** Fall 3: Eine eingehende Nachricht landet automatisch beim Betreuer. */
    public function test_eingehende_nachricht_wird_dem_betreuer_zugewiesen(): void
    {
        $kunde = $this->kunde();
        $ahmed = $this->mitarbeiter();
        $kunde->betreuer()->attach($ahmed->id, ['is_primary' => true]);

        $this->eingang($kunde);

        $unterhaltung = Conversation::firstOrFail();
        $this->assertSame($ahmed->id, $unterhaltung->assigned_employee_id);
        $this->assertDatabaseHas('conversation_assignments', [
            'conversation_id' => $unterhaltung->id,
            'to_employee_id' => $ahmed->id,
            'action' => ConversationAssignment::ACTION_AUTO_BETREUER,
        ]);
    }

    /**
     * Fall 4: DER KERN. Eine Uebernahme durch den Support aendert die
     * Zustaendigkeit - der BETREUER des Kunden bleibt unveraendert.
     */
    public function test_uebernahme_aendert_nie_den_betreuer(): void
    {
        $kunde = $this->kunde();
        $ahmed = $this->mitarbeiter();
        $sara = $this->mitarbeiter('support');
        $kunde->betreuer()->attach($ahmed->id, ['is_primary' => true]);

        $this->eingang($kunde);
        $unterhaltung = Conversation::firstOrFail();

        app(AssignmentService::class)->takeOver($unterhaltung, $sara, 'Ahmed im Urlaub');

        $this->assertSame($sara->id, $unterhaltung->fresh()->assigned_employee_id);
        // Das Kundenverhaeltnis bleibt, wo es war.
        $this->assertSame($ahmed->id, $kunde->fresh()->betreuerPrimary()->id);
    }

    /**
     * Fall 5: Die automatische Zuweisung greift NUR bei fehlender
     * Zustaendigkeit. Sonst wuerde die naechste Kundenantwort eine
     * bewusste Uebernahme stillschweigend rueckgaengig machen - der
     * Support saehe seine Faelle einfach verschwinden.
     */
    public function test_naechste_nachricht_nimmt_die_uebernahme_nicht_zurueck(): void
    {
        $kunde = $this->kunde();
        $ahmed = $this->mitarbeiter();
        $sara = $this->mitarbeiter('support');
        $kunde->betreuer()->attach($ahmed->id, ['is_primary' => true]);

        $this->eingang($kunde, 'm1');
        $unterhaltung = Conversation::firstOrFail();
        app(AssignmentService::class)->takeOver($unterhaltung, $sara);

        $this->eingang($kunde, 'm2');

        $this->assertSame($sara->id, $unterhaltung->fresh()->assigned_employee_id);
    }

    /** Fall 6: Jede Aenderung steht in der Historie - mit Handelndem und Grund. */
    public function test_jede_aenderung_steht_in_der_historie(): void
    {
        $kunde = $this->kunde();
        $ahmed = $this->mitarbeiter();
        $sara = $this->mitarbeiter('support');
        $kunde->betreuer()->attach($ahmed->id, ['is_primary' => true]);
        $this->eingang($kunde);
        $unterhaltung = Conversation::firstOrFail();
        $dienst = app(AssignmentService::class);

        $dienst->takeOver($unterhaltung, $sara, 'Eskalation');
        $dienst->assignToBetreuer($unterhaltung->fresh(), $sara, 'zurueck an den Betreuer');

        $historie = ConversationAssignment::where('conversation_id', $unterhaltung->id)
            ->orderBy('id')->get();

        $this->assertCount(3, $historie);
        $this->assertSame(ConversationAssignment::ACTION_TAKEOVER, $historie[1]->action);
        $this->assertSame($ahmed->id, $historie[1]->from_employee_id);
        $this->assertSame($sara->id, $historie[1]->to_employee_id);
        $this->assertSame($sara->id, $historie[1]->changed_by_employee_id);
        $this->assertSame('Eskalation', $historie[1]->reason);
        $this->assertSame($ahmed->id, $historie[2]->to_employee_id);
    }

    /**
     * Fall 7: Eine Zuweisung auf DENSELBEN Mitarbeiter ist kein
     * Ereignis. Sonst stuende nach einer Woche hundertmal dieselbe
     * Zeile da und die echten Wechsel gingen darin unter.
     */
    public function test_gleiche_zuweisung_erzeugt_keinen_historieneintrag(): void
    {
        $kunde = $this->kunde();
        $sara = $this->mitarbeiter('support');
        $unterhaltung = Conversation::create([
            'customer_id' => $kunde->id,
            'channel_id' => Channel::idFor(Channel::PORTAL),
            'status' => Conversation::STATUS_OPEN,
        ]);
        $dienst = app(AssignmentService::class);

        $dienst->takeOver($unterhaltung, $sara);
        $dienst->takeOver($unterhaltung->fresh(), $sara);

        $this->assertSame(1, ConversationAssignment::where('conversation_id', $unterhaltung->id)->count());
    }

    /** Fall 8: Ohne Betreuer bleibt die Unterhaltung unzugewiesen - nie geraten. */
    public function test_ohne_betreuer_bleibt_die_unterhaltung_offen(): void
    {
        $kunde = $this->kunde();
        $this->mitarbeiter();

        $this->eingang($kunde);

        $this->assertNull(Conversation::firstOrFail()->assigned_employee_id);
        $this->assertSame(0, ConversationAssignment::count());
    }

    /**
     * Fall 9: Eine Bearbeitungs-Markierung gilt nur kurz. Ein
     * abgestuerzter Browser darf eine Unterhaltung nicht dauerhaft
     * blockieren - lieber zwei Mitarbeiter, die einander sehen, als
     * eine Unterhaltung, die niemand mehr oeffnen kann.
     */
    public function test_bearbeitungs_markierung_laeuft_ab(): void
    {
        $kunde = $this->kunde();
        $ahmed = $this->mitarbeiter();
        $sara = $this->mitarbeiter('support');
        $unterhaltung = Conversation::create([
            'customer_id' => $kunde->id,
            'channel_id' => Channel::idFor(Channel::PORTAL),
            'status' => Conversation::STATUS_OPEN,
        ]);

        $unterhaltung->touchLock($ahmed->id);
        $frisch = $unterhaltung->fresh();

        // Sara sieht, dass Ahmed gerade schreibt ...
        $this->assertSame($ahmed->id, $frisch->lockedByOther($sara->id)?->id);
        // ... Ahmed sich selbst natuerlich nicht.
        $this->assertNull($frisch->lockedByOther($ahmed->id));

        $frisch->forceFill(['locked_at' => now()->subMinutes(Conversation::LOCK_MINUTES + 1)])->saveQuietly();
        $this->assertNull($frisch->fresh()->lockedByOther($sara->id));
    }

    /**
     * Fall 10: Eine Kundenantwort holt eine GESCHLOSSENE Unterhaltung
     * zurueck - der Kunde schreibt weiter, also ist der Vorgang nicht
     * erledigt. Ein ARCHIV bleibt dagegen Archiv: es ist eine bewusste
     * Entscheidung eines Menschen.
     */
    public function test_kundenantwort_oeffnet_geschlossene_unterhaltung_wieder(): void
    {
        $kunde = $this->kunde();
        $this->eingang($kunde, 'm1');
        $unterhaltung = Conversation::firstOrFail();
        $unterhaltung->forceFill([
            'status' => Conversation::STATUS_CLOSED,
            'closed_at' => now(),
        ])->save();

        $this->eingang($kunde, 'm2');

        $frisch = $unterhaltung->fresh();
        $this->assertSame(Conversation::STATUS_OPEN, $frisch->status);
        $this->assertNotNull($frisch->reopened_at);
    }
}
