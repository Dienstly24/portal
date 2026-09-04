<?php

namespace App\Services\Ai\Assistant;

use App\Models\AiConversation;
use App\Models\AiConversationEvent;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\Ticket;
use Illuminate\Support\Carbon;

/**
 * Wiederaufnahme des Assistenten nach einer Uebernahme
 * (Betreiber-Vorgabe 20.08.2026).
 *
 * DAS PROBLEM: eine Uebernahme galt bisher dem KUNDEN und galt fuer
 * immer. Der Mitarbeiter erledigte den Fall, und die naechste Frage
 * desselben Kunden - Tage spaeter, voellig anderes Thema - blieb ohne
 * automatische Antwort. Niemand sah das, weil im Panel nur "KI
 * deaktiviert" stand.
 *
 * DIE REGEL: eine Uebernahme gilt dem VORGANG. Die KI kommt von selbst
 * zurueck, sobald einer dieser beiden Punkte erfuellt ist -
 *   1. der Vorgang, wegen dem uebergeben wurde, ist abgeschlossen
 *      (resolved/closed) - die ausdrueckliche Aussage des Mitarbeiters
 *      "ich bin fertig";
 *   2. seit der letzten Mitarbeiter-Nachricht an den Kunden ist die
 *      Ruhefrist verstrichen (Standard 24 h) - das Netz fuer den Fall,
 *      dass niemand den Vorgang schliesst.
 *
 * UND NIE, WENN:
 *   - der Betreiber die Wiederaufnahme abgeschaltet hat;
 *   - jemand "KI deaktivieren" gedrueckt hat (auto_resume = false);
 *   - der Uebergabegrund eine BESCHWERDE war (NO_AUTO_RESUME_REASONS);
 *   - noch ein anderer Vorgang dieses Kunden offen ist, an dem gerade
 *     gearbeitet wird und die Ruhefrist noch laeuft.
 *
 * Alles daran ist deterministisch und kostenlos: kein Modellaufruf
 * entscheidet, ob ein Mensch zustaendig bleibt.
 */
class ConversationResumeService
{
    public function __construct(private AssistantSettings $settings)
    {
    }

    /**
     * Prueft und vollzieht die Wiederaufnahme. Gibt true zurueck, wenn die
     * KI JETZT wieder zustaendig ist (Aufrufer kann normal weiterarbeiten).
     */
    public function resumeIfDue(Customer $customer, AiConversation $conversation): bool
    {
        if ($conversation->canAutoReply()) {
            return true; // laeuft ohnehin
        }

        if (! $this->settings->autoResume() || ! $conversation->mayAutoResume()) {
            return false;
        }

        $grund = $this->dueReason($customer, $conversation);
        if ($grund === null) {
            return false;
        }

        $conversation->reactivate(automatisch: true);

        AiConversationEvent::create([
            'conversation_id' => $conversation->id,
            'event' => AiConversationEvent::EVENT_RESUMED,
            'actor' => AiConversationEvent::ACTOR_SYSTEM,
            'detail' => $grund,
        ]);

        return true;
    }

    /**
     * Ist die KI fuer diesen Kunden zustaendig - jetzt oder mit der
     * naechsten Nachricht? Reine LESEPRUEFUNG (aendert nichts), fuer die
     * Kennzeichnung im Portal-Chat: der Kunde soll nicht "Sie schreiben
     * mit dem Team" lesen, wenn die Wiederaufnahme faellig ist.
     */
    public function isAiOnDuty(Customer $customer, ?AiConversation $conversation): bool
    {
        if ($conversation === null) {
            return true;
        }
        if ($conversation->canAutoReply()) {
            return true;
        }

        return $this->settings->autoResume()
            && $conversation->mayAutoResume()
            && $this->dueReason($customer, $conversation) !== null;
    }

    /**
     * Warum darf die KI zurueck? null = sie darf (noch) nicht.
     * Bewusst als Text, weil genau dieser Satz im Panel und im
     * Ereignisprotokoll steht - der Mitarbeiter soll nie raten muessen,
     * warum die KI wieder antwortet.
     */
    public function dueReason(Customer $customer, AiConversation $conversation): ?string
    {
        if ($conversation->resume_ticket_id) {
            $ticket = Ticket::find($conversation->resume_ticket_id);
            // Vergleich als TEXT: ein frisch angelegtes Modell traegt die
            // UUID noch als Objekt, erst nach dem Neuladen als String -
            // ein strikter Vergleich waere hier zufaellig falsch.
            $gehoertZumKunden = (string) $ticket?->customer_id === (string) $customer->id;
            if ($ticket && $gehoertZumKunden && $ticket->isFinished()) {
                return 'Vorgang #'.$ticket->ticket_number.' abgeschlossen';
            }
        }

        $frist = $conversation->resume_not_before;
        if ($frist === null) {
            // Uebernahme aus der Zeit vor dieser Funktion: die Ruhefrist
            // laeuft ab der letzten Mitarbeiter-Nachricht, ersatzweise ab
            // der Uebergabe. Ohne beides bleibt es beim Menschen.
            $bezug = $this->lastStaffMessageAt($customer) ?? $conversation->handover_at;
            if ($bezug === null) {
                return null;
            }
            $frist = $bezug->copy()->addHours($this->settings->resumeQuietHours());
        }

        if (now()->lt($frist)) {
            return null;
        }

        // Auch ohne den urspruenglichen Vorgang: wird an einem anderen
        // Vorgang gerade gearbeitet, bleibt der Mensch zustaendig, solange
        // seine letzte Nachricht innerhalb der Ruhefrist liegt.
        $letzte = $this->lastStaffMessageAt($customer);
        if ($letzte && $letzte->copy()->addHours($this->settings->resumeQuietHours())->isFuture()) {
            return null;
        }

        return 'Ruhefrist von '.$this->settings->resumeQuietHours().' Stunden ohne Mitarbeiter-Nachricht abgelaufen';
    }

    /** Zeitpunkt der letzten ECHTEN Mitarbeiter-Nachricht (nie einer KI-Antwort). */
    private function lastStaffMessageAt(Customer $customer): ?Carbon
    {
        $zeit = CustomerMessage::where('customer_id', $customer->id)
            ->fromStaff()
            ->where('ai_generated', false)
            ->max('created_at');

        return $zeit ? Carbon::parse($zeit) : null;
    }
}
