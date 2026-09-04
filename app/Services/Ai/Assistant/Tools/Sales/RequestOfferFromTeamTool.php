<?php

namespace App\Services\Ai\Assistant\Tools\Sales;

use App\Models\AiConversationEvent;
use App\Services\Ai\Assistant\HandoverService;
use App\Services\Ai\Assistant\Sales\ConversationContext;
use App\Services\Ai\Assistant\Sales\ConversationJournal;
use App\Services\Ai\Assistant\Sales\ConversationState;
use App\Services\Ai\Assistant\Tools\AssistantTool;
use App\Services\Ai\Assistant\Tools\AssistantToolContext;

/**
 * Angebot beim Team anfordern (Spezifikation Abschnitt 5).
 *
 * Der Uebergabepunkt der Phase 1: die Bedarfsdaten sind vollstaendig, die
 * KI kann und darf kein Angebot suchen - also uebernimmt der Mitarbeiter
 * die Auswahl. Er bekommt dabei den fertigen Vorgang mit allen Angaben,
 * nicht nur den Hinweis "Kunde will ein Angebot".
 *
 * Die Unterhaltung wird dabei NICHT stummgeschaltet: sobald das Angebot
 * hinterlegt ist, fuehrt die KI das Gespraech weiter. Deshalb keine
 * vollstaendige Uebergabe, sondern nur der Vorgang plus Glocke.
 */
class RequestOfferFromTeamTool implements AssistantTool
{
    public function __construct(
        private ConversationJournal $journal,
        private HandoverService $handover,
    ) {
    }

    public function name(): string
    {
        return 'requestOfferFromTeam';
    }

    public function description(): string
    {
        return 'Fordere ein Angebot beim Team an, wenn alle noetigen Angaben vorliegen '
            .'und noch kein Angebot hinterlegt ist. Sage dem Kunden anschliessend, '
            .'dass ein Mitarbeiter das passende Angebot heraussucht und er es hier im '
            .'Chat erhaelt. Nenne KEINE Preise oder Tarife.';
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
        $offen = $sicht->missing('bedarf');

        if ($offen !== []) {
            return [
                'fehler' => 'Es fehlen noch Angaben.',
                'noch_offen' => array_map(fn ($f) => $f['label'], $offen),
            ];
        }

        $vorher = (string) $conversation->state;
        if ($conversation->moveTo(ConversationState::WAITING_FOR_OFFER, 'Angebot angefordert')) {
            $this->journal->stateChanged($conversation, $vorher, $conversation->state);
        }

        // Vorgang + Glocke mit dem vollstaendigen Bedarf - der Mitarbeiter
        // soll ohne Rueckfrage arbeiten koennen.
        $vorgang = $this->handover->requestOffer($context->customer, $conversation, $sicht);

        $this->journal->record($conversation, AiConversationEvent::EVENT_HANDOVER, [
            'grund' => 'Angebot vom Mitarbeiter angefordert',
        ]);
        $context->recordAction('angebot_angefordert', ['ticket' => $vorgang?->ticket_number]);

        return [
            'angefordert' => true,
            'zustand' => $conversation->state,
            'hinweis' => 'Ein Mitarbeiter waehlt das Angebot aus. Nenne dem Kunden '
                .'keine Preise und keine Tarife.',
        ];
    }
}
