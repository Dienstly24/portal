<?php
namespace App\Services\Ai\Assistant\Sales\Offers;

use App\Models\AiConversation;
use App\Models\AiLead;
use Illuminate\Support\Collection;

/**
 * Woher kommen die Angebote? (Spezifikation Abschnitte 6 und 25)
 *
 * PHASE 1: `ManualOfferSource` - der Mitarbeiter hinterlegt sie.
 * PHASE 2: eine zweite Implementierung fragt die Angebots-/Verfuegbarkeits-
 * API und legt dieselben `ai_offers`-Zeilen an.
 *
 * Genau deshalb existiert diese Schnittstelle schon jetzt: der Wechsel auf
 * die automatische Suche darf spaeter EINE Bindung in AppServiceProvider
 * sein und nicht ein Umbau von Zustandsmaschine, Werkzeugen und
 * Oberflaeche.
 */
interface OfferSourceInterface
{
    /** Kurzname fuer Protokoll und Anzeige ('manuell', 'api'). */
    public function name(): string;

    /**
     * Kann diese Quelle selbst Angebote beschaffen? Phase 1: nein - dann
     * wartet das Gespraech im Zustand WAITING_FOR_OFFER auf den
     * Mitarbeiter.
     */
    public function canSearch(): bool;

    /**
     * Angebote fuer dieses Gespraech beschaffen. Phase 1 gibt schlicht
     * zurueck, was bereits hinterlegt ist.
     *
     * @return Collection<int,\App\Models\AiOffer>
     */
    public function offersFor(?AiConversation $conversation, ?AiLead $lead = null): Collection;
}
