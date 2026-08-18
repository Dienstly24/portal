<?php
namespace App\Services\Ai\Assistant\Sales;

use App\Models\AiConversation;
use App\Models\AiConversationEvent;
use App\Models\AiLead;

/**
 * Schreibt das Ereignisprotokoll (Spezifikation Abschnitt 23).
 *
 * Eine einzige Stelle, damit jedes Ereignis gleich aussieht und nirgends
 * versehentlich ein Rohwert einer sensiblen Angabe im Protokoll landet:
 * erfasst werden FELDNAMEN und Ergebnisse, nie Werte.
 */
class ConversationJournal
{
    public function record(
        ?AiConversation $conversation,
        string $event,
        array $detail = [],
        string $actor = AiConversationEvent::ACTOR_AI,
        ?int $userId = null,
        ?string $fromState = null,
        ?string $toState = null,
        ?AiLead $lead = null,
    ): AiConversationEvent {
        return AiConversationEvent::create([
            'conversation_id' => $conversation?->id,
            'lead_id' => $lead?->id,
            'event' => $event,
            'actor' => $actor,
            'user_id' => $userId,
            'from_state' => $fromState,
            'to_state' => $toState,
            'detail' => $detail === [] ? null : $detail,
        ]);
    }

    /**
     * Zustandswechsel protokollieren - und zwar NUR, wenn wirklich
     * gewechselt wurde. Ein abgelehnter Uebergang ist kein Ereignis,
     * sonst steht das Protokoll voll mit Nicht-Ereignissen.
     */
    public function stateChanged(AiConversation $conversation, string $from, string $to, string $actor = AiConversationEvent::ACTOR_AI, ?int $userId = null): void
    {
        if ($from === $to) {
            return;
        }

        $this->record(
            $conversation,
            AiConversationEvent::EVENT_STATE,
            [],
            $actor,
            $userId,
            $from,
            $to,
        );
    }

    /** Erfasste Angaben: nur die Feldnamen, nie die Werte. */
    public function collected(AiConversation $conversation, array $keys, string $actor = AiConversationEvent::ACTOR_AI): void
    {
        $keys = array_values(array_filter($keys));
        if ($keys === []) {
            return;
        }

        $this->record($conversation, AiConversationEvent::EVENT_COLLECTED, [
            'felder' => array_map(fn ($k) => RequirementProfile::label($k), $keys),
        ], $actor);
    }
}
