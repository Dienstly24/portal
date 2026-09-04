<?php

namespace App\Services\Ai\Assistant\Tools\Sales;

use App\Services\Ai\Assistant\Sales\ConversationContext;
use App\Services\Ai\Assistant\Sales\ConversationJournal;
use App\Services\Ai\Assistant\Sales\ConversationState;
use App\Services\Ai\Assistant\Sales\InternalVerificationService;
use App\Services\Ai\Assistant\Tools\AssistantTool;
use App\Services\Ai\Assistant\Tools\AssistantToolContext;

/**
 * Vertragsangaben zur PRUEFUNG einreichen
 * (Spezifikation Abschnitte 9, 10 und 11).
 *
 * Hier laeuft die stille Verifikation: das Ergebnis ist ausschliesslich
 * VERIFICATION_PASSED / VERIFICATION_FAILED / VERIFICATION_PENDING.
 * WELCHE Angabe abweicht, erfaehrt weder das Modell noch der Kunde -
 * sonst liesse sich der Chat als Orakel benutzen, um gespeicherte Daten
 * zu erraten.
 *
 * Das Werkzeug nimmt KEINE Werte entgegen: die Angaben liegen bereits
 * serverseitig (SlotExtractor). So kann das Modell weder Werte
 * einschleusen noch welche zurueckschreiben.
 */
class SubmitContractDataTool implements AssistantTool
{
    public function __construct(
        private InternalVerificationService $verification,
        private ConversationJournal $journal,
    ) {
    }

    public function name(): string
    {
        return 'submitContractData';
    }

    public function description(): string
    {
        return 'Reiche die erfassten Vertragsangaben zur internen Pruefung ein, sobald '
            .'alle Pflichtangaben vorliegen. Du bekommst NUR ein Gesamtergebnis - '
            .'nenne dem Kunden niemals Einzelheiten der Pruefung und bestaetige '
            .'niemals, ob eine seiner Angaben mit unseren Daten uebereinstimmt.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'required' => []];
    }

    public function isWriting(): bool
    {
        return true;
    }

    public function run(array $arguments, AssistantToolContext $context): array
    {
        $conversation = $context->conversation;
        $sicht = new ConversationContext($conversation, $context->customer);
        $offen = $sicht->missing('vertrag');

        if ($offen !== []) {
            return [
                'eingereicht' => false,
                'noch_offen' => array_map(fn ($f) => $f['label'], $offen),
                'hinweis' => 'Frage die fehlenden Angaben ab, hoechstens zwei je Nachricht.',
            ];
        }

        $vorher = (string) $conversation->state;
        if ($conversation->moveTo(ConversationState::VERIFYING_DATA, 'Vertragsdaten vollstaendig')) {
            $this->journal->stateChanged($conversation, $vorher, $conversation->state);
        }

        $ergebnis = $this->verification->verify($conversation, $context->customer);
        $context->recordAction('pruefung', ['ergebnis' => $ergebnis['status']]);

        // Nur bei bestandener Pruefung geht es automatisch weiter. Alles
        // andere ist Mitarbeiter-Sache - eine fehlgeschlagene Pruefung
        // darf die KI nicht selbst "klaeren".
        if ($ergebnis['status'] === InternalVerificationService::PASSED) {
            $vorher = (string) $conversation->state;
            if ($conversation->moveTo(ConversationState::VERIFICATION_PASSED, 'Pruefung bestanden')) {
                $this->journal->stateChanged($conversation, $vorher, $conversation->state);
            }
            $vorher = (string) $conversation->state;
            if ($conversation->moveTo(ConversationState::CONTRACT_READY, 'Bereit zum Abschluss')) {
                $this->journal->stateChanged($conversation, $vorher, $conversation->state);
            }
        }

        return [
            'eingereicht' => true,
            // Bewusst nur der Gesamtstatus - keine Pruefpunkte.
            'ergebnis' => $ergebnis['status'],
            'zustand' => $conversation->fresh()->state,
            'hinweis' => 'Bestaetige dem Kunden nur den Eingang seiner Angaben und dass '
                .'ein Mitarbeiter den Abschluss uebernimmt. Nenne KEINE Einzelheiten '
                .'der Pruefung.',
        ];
    }
}
