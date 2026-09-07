<?php

namespace App\Listeners\Messaging;

use App\Events\Messaging\InboundMessageReceived;
use App\Jobs\AnswerCustomerMessageJob;
use App\Models\ActivityLog;
use App\Models\CustomerMessage;
use App\Services\Ai\Assistant\AiSettingsResolver;
use App\Services\Ai\Assistant\AssistantTexts;
use App\Services\Ai\Assistant\LanguageDetector;
use App\Support\AiMode;
use App\Support\BusinessHours;

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
    /** Hoechstens EIN Abwesenheitshinweis je Unterhaltung in diesem Zeitraum. */
    private const OUT_OF_OFFICE_QUIET_HOURS = 12;

    public function __construct(
        private readonly AiSettingsResolver $resolver,
        private readonly BusinessHours $hours,
        private readonly AssistantTexts $texts,
        private readonly LanguageDetector $language,
    ) {}

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

        // GESCHAEFTSZEITEN (Abschnitt 64). Ausserhalb antwortet die KI
        // nicht inhaltlich - der Kunde bekommt aber eine ehrliche
        // Eingangsbestaetigung statt Stille, und der Vorgang liegt am
        // Morgen im Posteingang. Ohne Hauptschalter greift das nie.
        if ($this->hours->closed()) {
            $this->outOfOffice($event, $conversation);
            $this->note($conversation, 'ausserhalb_geschaeftszeiten');

            return;
        }

        // Die weiteren Sperren (Notbremse, Uebernahme, Grenzen, kostenlose
        // Vorpruefung) bleiben im CustomerAssistantService. Sie hier zu
        // wiederholen hiesse, dieselbe Entscheidung an zwei Stellen zu
        // treffen - und die zweite altert.
        AnswerCustomerMessageJob::dispatch($event->message->id);
    }

    /**
     * Abwesenheitshinweis - hoechstens einmal je Unterhaltung und
     * Ruhefrist.
     *
     * OHNE diese Bremse bekaeme ein Kunde, der abends fuenf Nachrichten
     * schreibt, fuenfmal denselben Baustein. Das liest sich wie eine
     * kaputte Maschine und ist schlimmer als gar keine Antwort.
     */
    private function outOfOffice(InboundMessageReceived $event, $conversation): void
    {
        $bereitsGesendet = CustomerMessage::where('conversation_id', $conversation->id)
            ->where('message_type', 'system')
            ->where('created_at', '>=', now()->subHours(self::OUT_OF_OFFICE_QUIET_HOURS))
            ->exists();

        if ($bereitsGesendet) {
            return;
        }

        $sprache = $this->language->detect(
            (string) $event->message->body,
            $conversation->customer?->preferred_lang
        );

        CustomerMessage::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'body' => $this->texts->get(AssistantTexts::OUT_OF_OFFICE, $sprache),
            'from_staff' => true,
            'ai_generated' => true,
            // Als SYSTEM-Nachricht gekennzeichnet: sie stammt nicht vom
            // Modell, sondern aus einem hinterlegten Baustein - der
            // Mitarbeiter soll das unterscheiden koennen.
            'message_type' => 'system',
            'sender_type' => CustomerMessage::SENDER_SYSTEM,
        ]);
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
