<?php

namespace App\Services\Ai\Assistant\Sales\Offers;

use App\Models\AiConversation;
use App\Models\AiLead;
use App\Models\AiOffer;
use Illuminate\Support\Collection;

/**
 * Angebote der Phase 1: ein MENSCH hinterlegt sie in der Beraterwelt
 * (Spezifikation Abschnitt 5).
 *
 * Die KI beschafft hier bewusst nichts. Ein erfundenes oder geratenes
 * Angebot waere der teuerste denkbare Fehler dieses Systems - der Kunde
 * wuerde auf einen Preis vertrauen, den es nicht gibt.
 */
class ManualOfferSource implements OfferSourceInterface
{
    public function name(): string
    {
        return 'manuell';
    }

    public function canSearch(): bool
    {
        return false;
    }

    public function offersFor(?AiConversation $conversation, ?AiLead $lead = null): Collection
    {
        if (! $conversation && ! $lead) {
            return collect();
        }

        return AiOffer::query()
            ->when($conversation, fn ($q) => $q->where('conversation_id', $conversation->id))
            ->when(! $conversation && $lead, fn ($q) => $q->where('lead_id', $lead->id))
            ->orderBy('label')
            ->get();
    }
}
