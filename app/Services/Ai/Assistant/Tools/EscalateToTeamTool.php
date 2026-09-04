<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Models\AiConversation;

/**
 * Uebergabe an einen Mitarbeiter (Spezifikation Abschnitt 12 -
 * escalateToTeam / notifyEmployee).
 *
 * Das WICHTIGSTE Tool: es ist der zulaessige Ausweg aus jeder Unsicherheit.
 * Der Assistent soll es nutzen, statt zu raten (Abschnitt 4).
 *
 * Das Tool fuehrt die Uebergabe hier NICHT selbst aus - es vermerkt sie nur
 * im Kontext. Ausgefuehrt wird sie EINMAL vom Orchestrator am Ende der
 * Runde (CustomerAssistantService). So kann ein Modell, das das Tool
 * mehrfach aufruft, nicht mehrere Vorgaenge und Glocken erzeugen.
 */
class EscalateToTeamTool implements AssistantTool
{
    public function name(): string
    {
        return 'escalateToTeam';
    }

    public function description(): string
    {
        return 'Uebergibt die Anfrage an das zustaendige Dienstly24-Team. Nutze das IMMER, '
            .'wenn du unsicher bist, eine Information NICHT in den Kundendaten oder der '
            .'Wissensbasis steht, die Frage rechtlich oder vertraglich verbindlich ist '
            .'(Kuendigung, Genehmigung, Geld, Deckungsfragen), der Kunde sich beschwert, '
            .'einen Mitarbeiter verlangt oder die Daten widersprüchlich sind. Raten ist '
            .'nie erlaubt - Uebergeben ist immer richtig.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'grund' => [
                    'type' => 'string',
                    'enum' => [
                        AiConversation::REASON_UNCERTAIN,
                        AiConversation::REASON_SENSITIVE,
                        AiConversation::REASON_COMPLAINT,
                        AiConversation::REASON_CUSTOMER_REQUEST,
                        AiConversation::REASON_OUT_OF_SCOPE,
                    ],
                    'description' => 'uncertain = unsicher/Information fehlt, sensitive = rechtlich/'
                        .'vertraglich verbindlich, complaint = Beschwerde, customer_request = Kunde '
                        .'will einen Mitarbeiter, out_of_scope = ausserhalb des Kundenservice.',
                ],
                'zusammenfassung' => [
                    'type' => 'string',
                    'description' => 'Ein bis drei Saetze fuer den Mitarbeiter: worum es geht und was '
                        .'unklar ist. Nur Angaben des Kunden bzw. aus den Tools - nichts erfinden.',
                ],
            ],
            'required' => ['grund', 'zusammenfassung'],
        ];
    }

    public function isWriting(): bool
    {
        return true;
    }

    public function run(array $arguments, AssistantToolContext $context): array
    {
        // Grund gegen die Whitelist pruefen; alles Unbekannte gilt als
        // "unsicher" (der harmlose, immer zulaessige Grund).
        $reason = (string) ($arguments['grund'] ?? AiConversation::REASON_UNCERTAIN);
        $allowed = [
            AiConversation::REASON_UNCERTAIN,
            AiConversation::REASON_SENSITIVE,
            AiConversation::REASON_COMPLAINT,
            AiConversation::REASON_CUSTOMER_REQUEST,
            AiConversation::REASON_OUT_OF_SCOPE,
        ];
        if (! in_array($reason, $allowed, true)) {
            $reason = AiConversation::REASON_UNCERTAIN;
        }

        $context->handoverReason = $reason;
        $context->handoverSummary = trim((string) ($arguments['zusammenfassung'] ?? ''));
        $context->recordAction('handover_requested', ['reason' => $reason]);

        return [
            'uebergeben' => true,
            'grund' => $reason,
            'hinweis' => 'Die Anfrage ist an das Team uebergeben. Teile dem Kunden freundlich mit, '
                .'dass das zustaendige Team seine Anfrage prueft und sich bei ihm meldet. '
                .'Gib KEINE eigene Einschaetzung zur Sachfrage ab.',
        ];
    }
}
