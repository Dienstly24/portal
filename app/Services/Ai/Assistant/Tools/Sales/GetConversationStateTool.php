<?php

namespace App\Services\Ai\Assistant\Tools\Sales;

use App\Services\Ai\Assistant\Sales\ConversationContext;
use App\Services\Ai\Assistant\Sales\ConversationState;
use App\Services\Ai\Assistant\Sales\RequirementProfile;
use App\Services\Ai\Assistant\Tools\AssistantTool;
use App\Services\Ai\Assistant\Tools\AssistantToolContext;

/**
 * "Wo stehen wir?" (Spezifikation Abschnitte 12 und 14).
 *
 * Nach einer Stoerung, nach Tagen Pause oder nach einer Mitarbeiter-
 * Uebernahme braucht das Modell den Stand als FAKT - nicht aus dem
 * Nachrichtenverlauf gedeutet.
 *
 * Sensible Angaben erscheinen ausschliesslich als "liegt vor".
 */
class GetConversationStateTool implements AssistantTool
{
    public function name(): string
    {
        return 'getConversationState';
    }

    public function description(): string
    {
        return 'Aktueller Stand des Vorgangs: Anliegen, Zustand, bereits bekannte '
            .'Angaben, noch fehlende Angaben und vorliegende Angebote. Nutze das zu '
            .'Beginn, wenn du unsicher bist, was schon besprochen wurde - und frage '
            .'niemals nach etwas, das hier als bekannt ausgewiesen ist.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'required' => []];
    }

    public function isWriting(): bool
    {
        return false;
    }

    public function run(array $arguments, AssistantToolContext $context): array
    {
        $conversation = $context->conversation;
        $sicht = new ConversationContext($conversation, $context->customer);
        $bekannt = $sicht->known();

        $bekanntAusgabe = [];
        foreach (RequirementProfile::fields($conversation->intent) as $feld) {
            $key = $feld['key'];
            if (! isset($bekannt[$key]) || trim((string) $bekannt[$key]) === '') {
                continue;
            }
            $bekanntAusgabe[$feld['label']] = RequirementProfile::isSensitive($key)
                ? 'liegt vor'
                : (string) $bekannt[$key];
        }

        $stufe = $sicht->stage();

        return [
            'anliegen' => RequirementProfile::intentLabel($conversation->intent),
            'zustand' => $conversation->state,
            'zustand_klartext' => ConversationState::label($conversation->state),
            'wartet_auf_mitarbeiter' => ConversationState::waitsForStaff($conversation->state),
            'bereits_bekannt' => $bekanntAusgabe,
            'noch_offen' => array_map(fn ($f) => $f['label'], $sicht->missing($stufe)),
            'stufe' => $stufe,
            'angebote_vorhanden' => $conversation->offers()->count(),
            'pruefung' => $conversation->verification_status,
        ];
    }
}
