<?php
namespace App\Services\Ai\Assistant\Tools\Sales;

use App\Models\AiConversationEvent;
use App\Services\Ai\Assistant\Sales\ConversationJournal;
use App\Services\Ai\Assistant\Sales\ConversationState;
use App\Services\Ai\Assistant\Sales\Offers\OfferSourceInterface;
use App\Services\Ai\Assistant\Tools\AssistantTool;
use App\Services\Ai\Assistant\Tools\AssistantToolContext;

/**
 * Die hinterlegten Angebote holen (Spezifikation Abschnitte 5 und 7).
 *
 * Phase 1: es gibt nur, was ein Mitarbeiter eingetragen hat. Liegt nichts
 * vor, sagt dieses Werkzeug das ausdruecklich - dann darf die KI KEIN
 * Angebot nennen, sondern erklaert, dass ein Mitarbeiter das passende
 * Angebot heraussucht.
 */
class GetOffersTool implements AssistantTool
{
    public function __construct(
        private OfferSourceInterface $offers,
        private ConversationJournal $journal,
    ) {
    }

    public function name(): string
    {
        return 'getOffers';
    }

    public function description(): string
    {
        return 'Die fuer diesen Kunden hinterlegten Angebote. Nenne dem Kunden NUR '
            . 'Angebote aus dieser Liste - erfinde niemals Preise, Geschwindigkeiten '
            . 'oder Laufzeiten. Ist die Liste leer, teile mit, dass ein Mitarbeiter '
            . 'das passende Angebot heraussucht und sich meldet.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    public function isWriting(): bool
    {
        return false;
    }

    public function run(array $arguments, AssistantToolContext $context): array
    {
        $conversation = $context->conversation;
        $liste = $this->offers->offersFor($conversation);

        if ($liste->isEmpty()) {
            return [
                'angebote' => [],
                'hinweis' => 'Es liegt noch kein Angebot vor. Ein Mitarbeiter waehlt es aus.',
            ];
        }

        // Das Vorstellen wird festgehalten: der Mitarbeiter sieht damit,
        // was der Kunde bereits kennt.
        $vorher = (string) $conversation->state;
        $liste->each(fn ($angebot) => $angebot->presented_at
            ?: $angebot->forceFill(['presented_at' => now()])->save());

        if ($conversation->moveTo(ConversationState::OFFER_PRESENTED, 'Angebot vorgestellt')) {
            $this->journal->stateChanged($conversation, $vorher, $conversation->state);
            $this->journal->record($conversation, AiConversationEvent::EVENT_OFFER_PRESENTED, [
                'anzahl' => $liste->count(),
            ]);
        }

        return [
            'angebote' => $liste->map(fn ($a) => [
                'kennung' => $a->label,
                'anbieter' => $a->provider,
                'produkt' => $a->product,
                'geschwindigkeit' => $a->speed,
                'preis' => $a->price !== null ? (float) $a->price : null,
                'preis_zeitraum' => $a->price_period,
                'laufzeit_monate' => $a->duration_months,
                'bedingungen' => $a->terms,
            ])->values()->all(),
        ];
    }
}
