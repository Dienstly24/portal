<?php

namespace App\Services\Ai\Assistant\Tools\Sales;

use App\Models\AiConversationEvent;
use App\Services\Ai\Assistant\Sales\ConversationJournal;
use App\Services\Ai\Assistant\Sales\ConversationState;
use App\Services\Ai\Assistant\Sales\IntentClassifier;
use App\Services\Ai\Assistant\Sales\RequirementProfile;
use App\Services\Ai\Assistant\Tools\AssistantTool;
use App\Services\Ai\Assistant\Tools\AssistantToolContext;

/**
 * Das Anliegen des Kunden festhalten (Spezifikation Abschnitte 16 und 21).
 *
 * Warum als Werkzeug und nicht als Textausgabe: das Anliegen steuert,
 * welche Angaben ueberhaupt gebraucht werden. Steht es nur im Fliesstext,
 * ist es nach der naechsten Nachricht wieder weg - und der Mitarbeiter
 * sieht bei einer Stoerung nicht, worum es ging.
 */
class SetConversationIntentTool implements AssistantTool
{
    public function __construct(
        private ConversationJournal $journal,
        private IntentClassifier $classifier,
    ) {
    }

    public function name(): string
    {
        return 'setConversationIntent';
    }

    public function description(): string
    {
        return 'Halte fest, worum es dem Kunden geht, sobald du es sicher weisst. '
            .'Erlaubte Werte: NEW_INTERNET (neuer Anschluss), CONTRACT_CHANGE '
            .'(Wechsel/neuer Vertrag beim bestehenden Anschluss), UPGRADE (schnellerer '
            .'Tarif), GENERAL_QUESTION, TECHNICAL_SUPPORT, HUMAN_REQUIRED. '
            .'Rufe das EINMAL je Anliegen auf - nicht bei jeder Nachricht erneut.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'intent' => [
                    'type' => 'string',
                    'enum' => array_keys(RequirementProfile::INTENT_LABELS),
                    'description' => 'Das erkannte Anliegen.',
                ],
            ],
            'required' => ['intent'],
        ];
    }

    public function isWriting(): bool
    {
        return true;
    }

    public function run(array $arguments, AssistantToolContext $context): array
    {
        $intent = (string) ($arguments['intent'] ?? '');
        if (! isset(RequirementProfile::INTENT_LABELS[$intent])) {
            return ['fehler' => 'Unbekanntes Anliegen. Erlaubt: '
                .implode(', ', array_keys(RequirementProfile::INTENT_LABELS))];
        }

        $conversation = $context->conversation;
        $vorher = (string) $conversation->state;

        $conversation->forceFill([
            'intent' => $intent,
            'category' => $this->classifier->category($intent, true),
        ])->save();

        $this->journal->record($conversation, AiConversationEvent::EVENT_INTENT, [
            'anliegen' => RequirementProfile::intentLabel($intent),
        ]);

        // Ein Verkaufsanliegen startet die Bedarfserfassung; alles andere
        // bleibt der normale Kundenservice.
        if (in_array($intent, RequirementProfile::SALES_INTENTS, true)
            && $conversation->moveTo(ConversationState::COLLECTING_REQUIREMENTS, 'Anliegen erkannt')) {
            $this->journal->stateChanged($conversation, $vorher, $conversation->state);
        }

        $context->intent = $intent;
        $context->recordAction('intent_gesetzt', ['anliegen' => $intent]);

        return [
            'gespeichert' => true,
            'anliegen' => RequirementProfile::intentLabel($intent),
            'zustand' => $conversation->state,
        ];
    }
}
