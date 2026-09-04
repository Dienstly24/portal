<?php

namespace App\Services\Ai\Assistant\Tools\Sales;

use App\Models\AiConversationEvent;
use App\Services\Ai\Assistant\Sales\ConversationJournal;
use App\Services\Ai\Assistant\Sales\ConversationState;
use App\Services\Ai\Assistant\Tools\AssistantTool;
use App\Services\Ai\Assistant\Tools\AssistantToolContext;

/**
 * Die Entscheidung des Kunden festhalten (Spezifikation Abschnitte 4 und 8).
 *
 * Das Modell erkennt die Zustimmung im Zusammenhang ("passt so", "das
 * nehme ich", "خلاص نكمل") - hier wird sie zu einem Fakt: Angebot
 * ausgewaehlt, Zustand CUSTOMER_ACCEPTED, Ereignis im Protokoll.
 *
 * WICHTIG: das ist KEIN Vertragsabschluss. Verbindlich wird es erst durch
 * den Mitarbeiter (Regel des Projekts: die KI entscheidet nichts
 * Verbindliches). Der Kunde bekommt entsprechend nur die Bestaetigung,
 * dass sein Wunsch aufgenommen wurde.
 */
class RecordOfferSelectionTool implements AssistantTool
{
    public function __construct(private ConversationJournal $journal)
    {
    }

    public function name(): string
    {
        return 'recordOfferSelection';
    }

    public function description(): string
    {
        return 'Halte fest, dass der Kunde einem Angebot zugestimmt hat. Nutze das, '
            .'sobald die Zustimmung aus dem Zusammenhang klar ist - auch bei '
            .'Formulierungen wie "passt so", "das nehme ich", "einverstanden". '
            .'Bei Unklarheit frage lieber nach, statt zu raten. Die Kennung ist die '
            .'Angebots-Kennung aus getOffers (z.B. "A").';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kennung' => [
                    'type' => 'string',
                    'description' => 'Kennung des gewaehlten Angebots, z.B. "A".',
                ],
            ],
            'required' => ['kennung'],
        ];
    }

    public function isWriting(): bool
    {
        return true;
    }

    public function run(array $arguments, AssistantToolContext $context): array
    {
        $kennung = trim((string) ($arguments['kennung'] ?? ''));
        $conversation = $context->conversation;

        $angebot = $conversation->offers()
            ->whereRaw('UPPER(label) = ?', [mb_strtoupper($kennung)])
            ->first();

        if (! $angebot) {
            return [
                'fehler' => 'Kein Angebot mit dieser Kennung. Nenne dem Kunden die '
                    .'vorhandenen Angebote erneut und frage nach.',
            ];
        }

        $vorher = (string) $conversation->state;
        $angebot->forceFill(['selected_at' => now()])->save();
        $conversation->forceFill(['selected_offer_id' => $angebot->id])->save();

        if ($conversation->moveTo(ConversationState::CUSTOMER_ACCEPTED, 'Kunde hat zugestimmt')) {
            $this->journal->stateChanged($conversation, $vorher, $conversation->state);
        }
        $this->journal->record($conversation, AiConversationEvent::EVENT_OFFER_SELECTED, [
            'angebot' => $angebot->label,
            'produkt' => $angebot->product,
        ]);
        $context->recordAction('angebot_gewaehlt', ['angebot' => $angebot->label]);

        // Direkt weiter zur Vertragsdatenerfassung - der Kunde soll nicht
        // auf eine zweite Nachricht warten muessen.
        $vorher = (string) $conversation->state;
        if ($conversation->moveTo(ConversationState::COLLECTING_CONTRACT_DATA, 'Vertragsdaten werden erfasst')) {
            $this->journal->stateChanged($conversation, $vorher, $conversation->state);
        }

        return [
            'gespeichert' => true,
            'angebot' => $angebot->label,
            'zustand' => $conversation->state,
            'hinweis' => 'Bestaetige dem Kunden die Auswahl und erklaere, dass ein '
                .'Mitarbeiter den Abschluss vornimmt. Frage danach die noch fehlenden '
                .'Vertragsangaben ab (getConversationState).',
        ];
    }
}
