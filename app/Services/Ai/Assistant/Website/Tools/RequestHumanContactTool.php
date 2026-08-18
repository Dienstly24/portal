<?php
namespace App\Services\Ai\Assistant\Website\Tools;

use App\Models\AiConversationEvent;
use App\Services\Ai\Assistant\Sales\ConversationJournal;
use App\Services\Ai\Assistant\Sales\ConversationState;
use App\Services\Ai\Assistant\Website\LeadContext;
use App\Services\Ai\Assistant\Website\LeadService;
use App\Services\Ai\Assistant\Website\LeadTool;

/**
 * Den Interessenten an das Team uebergeben (Abschnitte 19 und 20).
 *
 * Der Website-Assistent kann nichts entscheiden und nichts zusagen. Immer
 * wenn es konkret wird - Preis, Verfuegbarkeit, Termin, Beschwerde oder
 * schlicht Wunsch nach einem Menschen - endet sein Teil hier: der Lead
 * wird an das Team gemeldet.
 */
class RequestHumanContactTool implements LeadTool
{
    public function __construct(
        private LeadService $leads,
        private ConversationJournal $journal,
    ) {
    }

    public function name(): string
    {
        return 'requestHumanContact';
    }

    public function description(): string
    {
        return 'Melde den Interessenten an das Team, wenn er einen Mitarbeiter '
            . 'wuenscht, wenn du seine Frage nicht aus der Wissensbasis beantworten '
            . 'kannst oder wenn die noetigen Angaben fuer ein Angebot vorliegen. '
            . 'Sage ihm anschliessend, dass sich ein Mitarbeiter meldet - nenne KEINE '
            . 'Preise, Tarife oder Termine.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'grund' => [
                    'type' => 'string',
                    'enum' => ['angebot', 'frage', 'mitarbeiter_gewuenscht', 'beschwerde'],
                    'description' => 'Weshalb das Team uebernehmen soll.',
                ],
                'zusammenfassung' => [
                    'type' => 'string',
                    'description' => 'Ein bis drei Saetze fuer den Mitarbeiter, nur aus '
                        . 'Angaben des Interessenten - nichts erfinden.',
                ],
            ],
            'required' => ['grund'],
        ];
    }

    public function run(array $arguments, LeadContext $context): array
    {
        $grund = (string) ($arguments['grund'] ?? 'frage');
        $lead = $context->lead;

        $lead->forceFill([
            'state' => ConversationState::HUMAN_REQUIRED,
            'next_action' => ConversationState::nextAction(ConversationState::HUMAN_REQUIRED),
        ])->save();

        $ticket = $this->leads->handOver($lead, $grund, (string) ($arguments['zusammenfassung'] ?? ''));

        $this->journal->record(null, AiConversationEvent::EVENT_HANDOVER, [
            'grund' => $grund,
        ], AiConversationEvent::ACTOR_AI, null, null, null, $lead);

        $context->wantsHuman = true;
        $context->recordAction('lead_uebergeben', ['grund' => $grund]);

        return [
            'gemeldet' => true,
            'vorgang' => $ticket?->ticket_number,
            'hinweis' => 'Sage dem Interessenten, dass sich ein Mitarbeiter meldet.',
        ];
    }
}
