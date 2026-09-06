<?php

namespace App\Listeners\Messaging;

use App\Events\Messaging\InboundMessageReceived;
use App\Jobs\AnswerCustomerMessageJob;
use App\Models\ActivityLog;
use App\Services\Ai\Assistant\AiSettingsResolver;
use App\Support\AiMode;

/**
 * DIE STELLE, an der die KI auf eine eingegangene Nachricht trifft
 * (Auftrag Abschnitte 79/80/93).
 *
 * WARUM HIER UND NIRGENDWO SONST: bisher stiess der PORTAL-Controller
 * die KI an. Der Anstoss stand damit im Kanal-Weg - kaeme WhatsApp dazu,
 * muesste er dort noch einmal stehen, bei Instagram ein drittes Mal.
 * Beim vierten Kanal vergisst ihn jemand, und der Ausfall sieht aus wie
 * gar nichts: der Kunde schreibt, und es passiert einfach nichts.
 *
 * Am EREIGNIS des Kerns gilt die KI fuer jeden Kanal, auch fuer jeden
 * zukuenftigen, ohne eine einzige Zeile im Adapter. Umgekehrt gilt:
 * KEIN Adapter darf die KI aufrufen - `MessagingArchitectureTest` haelt
 * fest, dass im Kanal-Ordner nichts KI-Artiges steht.
 */
class TriggerAiAssistant
{
    public function __construct(private readonly AiSettingsResolver $resolver) {}

    public function handle(InboundMessageReceived $event): void
    {
        $conversation = $event->conversation;

        // OHNE KUNDENAKTE ANTWORTET DIE KI NIE (Betreiber-Entscheidung
        // 06.09.2026). Eine Nachricht von einer unbekannten Nummer hat
        // keine Daten, aus denen sich etwas belegen liesse, und keine
        // dokumentierte Einwilligung. Die Unterhaltung ist trotzdem da
        // und liegt im Posteingang - sie geht an einen Menschen, nicht
        // ins Leere.
        if (! $conversation->customer_id) {
            $this->note($conversation, 'ohne_kundenakte');

            return;
        }

        $mode = $this->resolver->modeFor($conversation);

        // Nur die beiden sendenden Betriebsarten stossen den Job an. Der
        // Vorschlags-Modus laeuft NICHT hier: dort holt sich der
        // Mitarbeiter den Entwurf im Panel, wenn er ihn braucht - ein
        // Modellaufruf auf Vorrat kostet Geld fuer eine Antwort, die
        // niemand angefordert hat.
        if (! AiMode::sendsAutomatically($mode)) {
            $this->note($conversation, $mode);

            return;
        }

        // Die weiteren Sperren (Notbremse, Uebernahme, Grenzen, kostenlose
        // Vorpruefung) bleiben im CustomerAssistantService. Sie hier zu
        // wiederholen hiesse, dieselbe Entscheidung an zwei Stellen zu
        // treffen - und die zweite altert.
        AnswerCustomerMessageJob::dispatch($event->message->id);
    }

    /**
     * Warum die KI geschwiegen hat. Ohne diesen Eintrag ist "die KI
     * antwortet nicht" wieder die Meldung, bei der jede Ursache gleich
     * aussieht (Lehre 18.08.2026).
     */
    private function note($conversation, string $grund): void
    {
        try {
            ActivityLog::record('ai_skipped', 'conversation', $conversation->id, [
                'grund' => $grund,
                'channel_id' => $conversation->channel_id,
            ], null);
        } catch (\Throwable) {
            // Protokollieren darf den Nachrichtenempfang nie scheitern
            // lassen - dieselbe Regel wie beim ErrorRecorder.
        }
    }
}
