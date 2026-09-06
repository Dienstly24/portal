<?php

namespace App\Services\Messaging;

use App\Events\Messaging\ConversationAssigned;
use App\Models\ActivityLog;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\User;

/**
 * Wer ist fuer DIESE Unterhaltung zustaendig?
 *
 * DIE ENTSCHEIDENDE TRENNUNG (Betreiber-Vorgabe): der Betreuer gehoert
 * zum KUNDEN, die Zustaendigkeit gehoert zur UNTERHALTUNG. Uebernimmt
 * der Support einen Vorgang, wechselt die Zustaendigkeit - der Betreuer
 * bleibt, wer er war. Beides zu verschmelzen hiesse, dass jede
 * Support-Aushilfe das Kundenverhaeltnis umhaengt.
 *
 * Einzige Schreibstelle fuer `assigned_employee_id`. Jede Aenderung
 * schreibt Historie - ohne sie ist nach einer Uebernahme nicht mehr
 * belegbar, wer wann verantwortlich war.
 */
class AssignmentService
{
    /**
     * Automatische Zuweisung an den Betreuer beim Eingang einer
     * Nachricht.
     *
     * WICHTIG: sie greift NUR, wenn niemand zustaendig ist. Bei jeder
     * Nachricht neu an den Betreuer zu geben wuerde eine bewusste
     * Uebernahme durch den Support bei der naechsten Kundenantwort
     * stillschweigend rueckgaengig machen - der Support saehe seine
     * Faelle einfach verschwinden.
     */
    public function autoAssign(Conversation $conversation): ?User
    {
        if ($conversation->assigned_employee_id) {
            return $conversation->assignee;
        }

        $betreuer = $conversation->customer?->betreuerPrimary();
        if (! $betreuer) {
            return null;
        }

        $this->apply($conversation, $betreuer, ConversationAssignment::ACTION_AUTO_BETREUER, null, null);

        return $betreuer;
    }

    /** Uebernahme durch den handelnden Mitarbeiter (typisch: Support). */
    public function takeOver(Conversation $conversation, User $actor, ?string $reason = null): void
    {
        $this->apply($conversation, $actor, ConversationAssignment::ACTION_TAKEOVER, $actor, $reason);
    }

    /** Weitergabe an einen anderen Mitarbeiter. */
    public function reassign(Conversation $conversation, User $target, User $actor, ?string $reason = null): void
    {
        $this->apply($conversation, $target, ConversationAssignment::ACTION_REASSIGN, $actor, $reason);
    }

    /** Zurueck an den Betreuer des Kunden. */
    public function assignToBetreuer(Conversation $conversation, User $actor, ?string $reason = null): ?User
    {
        $betreuer = $conversation->customer?->betreuerPrimary();
        if (! $betreuer) {
            return null;
        }
        $this->apply($conversation, $betreuer, ConversationAssignment::ACTION_REASSIGN, $actor, $reason);

        return $betreuer;
    }

    public function unassign(Conversation $conversation, User $actor, ?string $reason = null): void
    {
        $this->apply($conversation, null, ConversationAssignment::ACTION_UNASSIGN, $actor, $reason);
    }

    /**
     * Der EINE Schreibweg. Eine Zuweisung auf denselben Mitarbeiter ist
     * kein Ereignis und erzeugt deshalb auch keinen Historieneintrag -
     * sonst stuende nach einer Woche hundertmal dieselbe Zeile da und
     * die echten Wechsel gingen darin unter.
     */
    private function apply(
        Conversation $conversation,
        ?User $target,
        string $action,
        ?User $actor,
        ?string $reason,
    ): void {
        $vorher = $conversation->assigned_employee_id;
        $nachher = $target?->id;

        if ($vorher === $nachher) {
            return;
        }

        $conversation->assigned_employee_id = $nachher;
        $conversation->save();

        ConversationAssignment::create([
            'conversation_id' => $conversation->id,
            'from_employee_id' => $vorher,
            'to_employee_id' => $nachher,
            'changed_by_employee_id' => $actor?->id,
            'action' => $action,
            'reason' => $reason,
        ]);

        ActivityLog::record('conversation_'.$action, 'conversation', $conversation->id, [
            'from' => $vorher,
            'to' => $nachher,
        ], $actor?->id);

        event(new ConversationAssigned($conversation, $vorher, $nachher, $action));
    }
}
