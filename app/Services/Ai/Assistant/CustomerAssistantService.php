<?php
namespace App\Services\Ai\Assistant;

use App\Models\AiAssistantLog;
use App\Models\AiConversation;
use App\Models\CustomerMessage;
use App\Services\Ai\Assistant\Contracts\AssistantProviderInterface;
use App\Services\Ai\Assistant\Tools\AssistantToolContext;
use App\Services\Ai\Assistant\Tools\AssistantToolRegistry;
use App\Services\CustomerMessageNotifier;
use App\Services\Notifications\NotificationService;
use App\Support\Facades\Notify;
use Illuminate\Support\Facades\Cache;
use App\Models\AiConversationEvent;
use App\Services\Ai\Assistant\Sales\AcceptanceDetector;
use App\Services\Ai\Assistant\Sales\ConversationContext;
use App\Services\Ai\Assistant\Sales\ConversationJournal;
use App\Services\Ai\Assistant\Sales\ConversationState;
use App\Services\Ai\Assistant\Sales\IntentClassifier;
use App\Services\Ai\Assistant\Sales\RequirementProfile;
use App\Services\Ai\Assistant\Sales\SlotExtractor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Der KI-Kundenassistent (Spezifikation Abschnitte 2-25, 30-32).
 *
 * Orchestriert genau EINE Antwort auf EINE Kundennachricht. Der Ablauf ist
 * absichtlich als Kette von Sperren gebaut, die von guenstig nach teuer
 * greifen - jede kann das Gespraech beenden, bevor Kosten entstehen:
 *
 *   1. Schalter des Betreibers (aus = gar nichts)
 *   2. Zustand der Unterhaltung (Mitarbeiter hat uebernommen?)
 *   3. Grenzen (Antworten je Vorgang, Rate je Kunde, Tageslimit)
 *   4. Bereichspruefung + Regel-Umgehung  <- letzte kostenlose Stufe
 *   5. Dialog mit dem Modell + freigegebene Funktionen
 *   6. Antwort schreiben, protokollieren, ggf. uebergeben
 *
 * Grundregeln, die hier technisch erzwungen werden:
 *  - Die Kundenakte kommt aus der Nachricht (= authentifizierte Sitzung),
 *    nie aus einem Modell-Argument.
 *  - Es entsteht IMMER hoechstens eine Antwortnachricht.
 *  - Jeder Fehler endet im Fallback + Uebergabe, nie in Stille.
 */
class CustomerAssistantService
{
    public function __construct(
        private AssistantProviderInterface $provider,
        private AssistantSettings $settings,
        private AssistantScopeGuard $scopeGuard,
        private AssistantToolRegistry $tools,
        private AssistantPrompt $prompt,
        private HandoverService $handover,
        private LanguageDetector $languageDetector,
        private SlotExtractor $slots,
        private IntentClassifier $intents,
        private AcceptanceDetector $acceptance,
        private ConversationJournal $journal,
        private ConversationResumeService $resume,
    ) {
    }

    /**
     * Auf eine Kundennachricht antworten.
     *
     * @return CustomerMessage|null die erzeugte Antwort (null = bewusst
     *         keine Antwort, z.B. KI aus oder Mitarbeiter uebernimmt)
     */
    public function handleCustomerMessage(CustomerMessage $message): ?CustomerMessage
    {
        // Nur echte Kundennachrichten - eine Team-/KI-Nachricht loest nie
        // eine Antwort aus (sonst antwortet die KI sich selbst).
        if ($message->from_staff) {
            return null;
        }

        $customer = $message->customer;
        if (!$customer) {
            return null;
        }

        // Genau EINE Antwort je Kundennachricht: liegt fuer diese Nachricht
        // bereits ein Protokoll vor, wurde sie schon bearbeitet. Schuetzt
        // gegen einen doppelten Anlauf (Queue-Job + Nachlauf-Befehl
        // ai:answer-pending) - der Kunde bekaeme sonst zwei Antworten und
        // moeglicherweise zwei Vorgaenge.
        if (AiAssistantLog::where('customer_message_id', $message->id)->exists()) {
            return null;
        }

        $conversation = AiConversation::forCustomer($customer->id);
        $language = $this->languageDetector->detect((string) $message->body, $customer->preferred_lang);
        $started = microtime(true);

        // --- Stufe 1: Schalter des Betreibers -------------------------------
        if (!$this->settings->enabled() || !$this->settings->autoReply()) {
            $this->log($conversation, $message, AiAssistantLog::OUTCOME_SKIPPED, [
                'grund' => 'Assistent oder automatische Antworten abgeschaltet',
            ]);

            return null;
        }

        // --- Stufe 2: Zustand der Unterhaltung ------------------------------
        // Zuerst die Wiederaufnahme pruefen (Betreiber-Vorgabe 20.08.2026):
        // eine Uebernahme gilt dem VORGANG. Ist der abgeschlossen oder die
        // Ruhefrist abgelaufen, ist die KI wieder zustaendig - sonst bliebe
        // ein Kunde nach einer einzigen Uebergabe fuer immer ohne
        // automatische Antwort. Kostet nichts, entscheidet kein Modell.
        $this->resume->resumeIfDue($customer, $conversation);

        if (!$conversation->canAutoReply()) {
            $this->log($conversation, $message, AiAssistantLog::OUTCOME_SKIPPED, [
                'grund' => $conversation->handover_required
                    ? 'Uebergabe offen - Mitarbeiter ist zustaendig'
                    : 'KI fuer diesen Kunden deaktiviert',
            ]);

            return null;
        }

        // --- Stufe 3: Grenzen (Kostenkontrolle) -----------------------------
        if ($limitReason = $this->limitExceeded($conversation)) {
            $this->handover->handOver(
                $customer,
                $conversation,
                AiConversation::REASON_LIMIT,
                (string) $message->body,
                $limitReason,
            );

            $reply = $this->reply($message, AssistantReplies::pick(AssistantReplies::LIMIT, $language));
            $this->log($conversation, $message, AiAssistantLog::OUTCOME_ESCALATED, [
                'grund' => $limitReason,
            ], handover: true, reply: $reply, duration: $started);

            return $reply;
        }

        // --- Stufe 4: kostenlose Vorpruefung --------------------------------
        $verdict = $this->scopeGuard->check((string) $message->body);
        if ($verdict['verdict'] !== AssistantScopeGuard::VERDICT_ALLOW) {
            return $this->handleGuardVerdict($verdict, $message, $conversation, $language, $started);
        }

        // --- Stufe 4b: sensible Angaben serverseitig herausloesen -----------
        // Muss VOR jedem Modellkontakt passieren: was hier erkannt wird,
        // ist danach im Nachrichtentext ersetzt und erreicht das Modell
        // nie (Abschnitte 9/10/11). Kostet nichts, ist deterministisch.
        $this->collectFromMessage($message, $conversation);
        $this->detectAcceptance($message, $conversation);

        // --- Stufe 4c: erste Einschaetzung des Anliegens --------------------
        // Nur, wenn noch keine vorliegt. Damit hat der Mitarbeiter selbst
        // dann eine Kategorie, wenn das Modell gleich darauf ausfaellt.
        if (!$conversation->intent) {
            $vermutet = $this->intents->classify((string) $message->body);
            $conversation->forceFill([
                'intent' => $vermutet,
                'category' => $this->intents->category($vermutet, true),
            ])->save();
        }

        // --- Stufe 5: Dialog mit dem Modell ---------------------------------
        if (!$this->provider->isEnabled()) {
            return $this->fallback($message, $conversation, $language, $started, 'Kein KI-Anbieter konfiguriert');
        }

        $conversation->forceFill(['current_step' => 'Antwort erstellen'])->save();
        $context = new AssistantToolContext($customer, $conversation, $language);

        try {
            $result = $this->runDialog($message, $context, $language);
        } catch (\Throwable $e) {
            Log::warning('KI-Assistent: Dialog fehlgeschlagen', ['error' => $e->getMessage()]);

            return $this->fallback($message, $conversation, $language, $started, $e->getMessage());
        }

        // --- Stufe 6: Uebergabe (genau einmal) + Antwort --------------------
        $handedOver = false;
        if ($context->wantsHandover()) {
            $this->handover->handOver(
                $customer,
                $conversation,
                (string) $context->handoverReason,
                (string) $message->body,
                $context->handoverSummary,
            );
            $handedOver = true;
        }

        $text = $this->sanitize($result['text']);
        if ($text === '') {
            // Das Modell hat nichts Verwertbares geliefert - lieber die
            // ehrliche Uebergabe als eine leere Blase.
            if (!$handedOver) {
                $this->handover->handOver(
                    $customer,
                    $conversation,
                    AiConversation::REASON_UNCERTAIN,
                    (string) $message->body,
                    'Der Assistent konnte keine Antwort formulieren.',
                );
                $handedOver = true;
            }
            $text = AssistantReplies::pick(AssistantReplies::HANDOVER, $language);
        }

        $reply = $this->reply($message, $text);

        $conversation->forceFill([
            'last_ai_action' => $result['tools'] === [] ? 'antwort' : implode(', ', array_slice($result['tools'], 0, 3)),
            'last_ai_response' => Str::limit($text, 500),
            'last_ai_at' => now(),
            'auto_reply_count' => $conversation->auto_reply_count + 1,
            // Abschnitt 13: eine gelungene Runde raeumt eine fruehere
            // Stoerung ab und haelt den letzten erfolgreichen Schritt fest.
            'status' => AiConversation::STATUS_RUNNING,
            'paused_reason' => null,
            'last_successful_step' => 'Antwort an den Kunden',
            'current_step' => null,
            'next_action' => ConversationState::nextAction($conversation->state),
        ])->save();

        $this->countDailyReply();

        $this->log(
            $conversation,
            $message,
            $handedOver ? AiAssistantLog::OUTCOME_ESCALATED : AiAssistantLog::OUTCOME_ANSWERED,
            ['runden' => $result['rounds']],
            handover: $handedOver,
            reply: $reply,
            duration: $started,
            tools: $result['tools'],
            actions: $context->actions(),
            usage: $result['usage'],
        );

        return $reply;
    }

    /**
     * Der eigentliche Tool-Calling-Dialog.
     *
     * Harte Obergrenzen fuer Runden UND Gesamtzahl der Aufrufe: ein Modell,
     * das sich im Kreis dreht, kostet damit nie mehr als kalkuliert
     * (Abschnitt 32 - keine Endlosschleifen, kein unkontrolliertes Tool
     * Calling).
     *
     * @return array{text: string, tools: list<string>, rounds: int, usage: array}
     */
    private function runDialog(CustomerMessage $message, AssistantToolContext $context, string $language): array
    {
        $maxRounds = max(1, (int) config('services.ai_assistant.max_tool_rounds', 5));
        $maxCalls = max(1, (int) config('services.ai_assistant.max_tool_calls', 10));
        $maxTokens = (int) config('services.openai.max_output_tokens', 700);

        $instructions = $this->prompt->build($context->customer, $language, $context->conversation);
        $history = $this->history($message);
        $schemas = $this->tools->schemas();

        $usedTools = [];
        $calls = 0;
        $usage = ['input' => 0, 'output' => 0, 'provider' => $this->provider->name(), 'model' => $this->provider->model()];
        $text = '';

        for ($round = 1; $round <= $maxRounds; $round++) {
            $turn = $this->provider->turn($instructions, $history, $schemas, $maxTokens);

            $usage['input'] += (int) ($turn->inputTokens ?? 0);
            $usage['output'] += (int) ($turn->outputTokens ?? 0);
            $usage['model'] = $turn->model ?: $usage['model'];

            if ($turn->text !== '') {
                $text = $turn->text;
            }

            if (!$turn->wantsTools()) {
                return ['text' => $text, 'tools' => $usedTools, 'rounds' => $round, 'usage' => $usage];
            }

            // Funktionsaufrufe abarbeiten. Der Aufruf selbst MUSS mit seinem
            // Ergebnis in den Verlauf, sonst weiss das Modell nicht, was
            // seine Funktion geliefert hat.
            $roundCalls = [];
            $roundResults = [];
            foreach ($turn->toolCalls as $index => $call) {
                if ($calls >= $maxCalls) {
                    break;
                }
                $calls++;
                $usedTools[] = $call['name'];

                $result = $this->tools->execute($call['name'], $call['arguments'], $context);

                $raw = $turn->rawCalls[$index] ?? null;
                $roundCalls[] = [
                    'role' => 'tool_call',
                    'call_id' => $call['call_id'],
                    'name' => $call['name'],
                    'arguments' => $raw['arguments'] ?? json_encode($call['arguments'], JSON_UNESCAPED_UNICODE),
                ];
                $roundResults[] = [
                    'role' => 'tool_result',
                    'call_id' => $call['call_id'],
                    'output' => json_encode($result, JSON_UNESCAPED_UNICODE) ?: '{}',
                ];
            }

            // ERST alle Aufrufe, DANN alle Ergebnisse - nicht abwechselnd.
            // Beide Anbieter erwarten diese Gruppierung: bei Anthropic
            // gehoeren alle tool_use in EINE Assistenten-Nachricht und alle
            // tool_result in die EINE darauffolgende Nutzer-Nachricht;
            // aufgeteilt bringt das dem Modell bei, kuenftig keine
            // parallelen Aufrufe mehr zu machen.
            $history = array_merge($history, $roundCalls, $roundResults);

            if ($calls >= $maxCalls) {
                // Grenze erreicht: eine letzte Runde OHNE Funktionen, damit
                // das Modell aus dem Gesammelten antworten muss.
                $turn = $this->provider->turn($instructions, $history, [], $maxTokens);
                $usage['input'] += (int) ($turn->inputTokens ?? 0);
                $usage['output'] += (int) ($turn->outputTokens ?? 0);

                return [
                    'text' => $turn->text !== '' ? $turn->text : $text,
                    'tools' => $usedTools,
                    'rounds' => $round,
                    'usage' => $usage,
                ];
            }
        }

        // Rundengrenze erreicht: abschliessende Antwort ohne Funktionen.
        $final = $this->provider->turn($instructions, $history, [], $maxTokens);
        $usage['input'] += (int) ($final->inputTokens ?? 0);
        $usage['output'] += (int) ($final->outputTokens ?? 0);

        return [
            'text' => $final->text !== '' ? $final->text : $text,
            'tools' => $usedTools,
            'rounds' => $maxRounds,
            'usage' => $usage,
        ];
    }

    /**
     * Gespraechsverlauf fuer das Modell: die letzten Nachrichten dieses
     * Kunden, gekuerzt.
     *
     * Der Kundentext geht als reine Nutzer-Nachricht raus - die Regeln
     * stehen ausschliesslich in `instructions`. Deshalb kann kein
     * Nachrichtentext Systemregeln ersetzen (Abschnitt 20). Zu lange
     * Nachrichten werden gekuerzt (Testfall 14).
     *
     * @return list<array<string,mixed>>
     */
    private function history(CustomerMessage $message): array
    {
        $limit = max(1, (int) config('services.ai_assistant.history_messages', 8));
        $maxChars = max(500, (int) config('services.ai_assistant.max_message_chars', 4000));

        $previous = CustomerMessage::where('customer_id', $message->customer_id)
            ->where('created_at', '<', $message->created_at)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->sortBy('created_at');

        $history = [];
        foreach ($previous as $item) {
            $body = trim((string) $item->body);
            if ($body === '') {
                continue;
            }
            $history[] = [
                'role' => $item->from_staff ? 'assistant' : 'user',
                'text' => Str::limit($body, 800),
            ];
        }

        // Der bereinigte Text (ohne IBAN & Co.), falls die Vorstufe gelaufen
        // ist - sonst der Originaltext.
        $letzte = trim((string) ($message->aiSafeBody ?? $message->body));

        $history[] = [
            'role' => 'user',
            'text' => Str::limit($letzte, $maxChars),
        ];

        return $history;
    }

    /**
     * Sensible Angaben aus der Kundennachricht herausloesen und
     * serverseitig festhalten (Abschnitte 9/10/11).
     *
     * Der Nachrichtentext im Chat bleibt unveraendert - der Kunde soll
     * sehen, was er geschrieben hat. Ersetzt wird nur, was ZUM MODELL
     * geht: dafuer merkt sich diese Methode den bereinigten Text am
     * Nachrichtenobjekt (nicht in der Datenbank).
     */
    private function collectFromMessage(CustomerMessage $message, AiConversation $conversation): void
    {
        $ergebnis = $this->slots->extract((string) $message->body);

        if ($ergebnis['found'] !== []) {
            $conversation->remember($ergebnis['found']);
            $this->journal->collected(
                $conversation,
                array_keys($ergebnis['found']),
                AiConversationEvent::ACTOR_CUSTOMER
            );
        }

        // Nur der Text FUER DAS MODELL wird ersetzt.
        $message->aiSafeBody = $ergebnis['text'];
    }

    /**
     * Sicherheitsnetz fuer die Zustimmung (Abschnitt 4).
     *
     * Zustaendig ist das Modell (es kennt den Zusammenhang). Hat es die
     * Zusage aber uebersehen, obwohl sie eindeutig ist und ein Angebot
     * vorliegt, wird sie hier festgehalten - sonst haengt ein
     * kaufbereiter Kunde im Zustand "wartet auf Entscheidung".
     */
    private function detectAcceptance(CustomerMessage $message, AiConversation $conversation): void
    {
        if (!in_array($conversation->state, [
            ConversationState::OFFER_PRESENTED,
            ConversationState::WAITING_FOR_CUSTOMER_DECISION,
        ], true)) {
            return;
        }

        $angebote = $conversation->offers()->get();
        if ($angebote->isEmpty()) {
            return;
        }

        $ergebnis = $this->acceptance->check(
            (string) $message->body,
            $angebote->pluck('label')->all()
        );
        if (!$ergebnis['accepted']) {
            return;
        }

        // Ohne benanntes Angebot nur dann uebernehmen, wenn es genau EINES
        // gibt - bei zwei Angeboten waere jede Wahl geraten.
        $gewaehlt = $ergebnis['label']
            ? $angebote->first(fn ($a) => mb_strtoupper($a->label) === mb_strtoupper($ergebnis['label']))
            : ($angebote->count() === 1 ? $angebote->first() : null);

        if (!$gewaehlt) {
            return;
        }

        $vorher = (string) $conversation->state;
        $gewaehlt->forceFill(['selected_at' => now()])->save();
        $conversation->forceFill(['selected_offer_id' => $gewaehlt->id])->save();

        if ($conversation->moveTo(ConversationState::CUSTOMER_ACCEPTED, 'Zustimmung erkannt')) {
            $this->journal->stateChanged($conversation, $vorher, $conversation->state);
        }
        $this->journal->record($conversation, AiConversationEvent::EVENT_OFFER_SELECTED, [
            'angebot' => $gewaehlt->label,
            'erkannt_durch' => 'Sicherheitsnetz',
        ], AiConversationEvent::ACTOR_SYSTEM);
    }

    /**
     * Ergebnis der kostenlosen Vorpruefung umsetzen - ohne jeden
     * API-Aufruf.
     */
    private function handleGuardVerdict(
        array $verdict,
        CustomerMessage $message,
        AiConversation $conversation,
        string $language,
        float $started,
    ): CustomerMessage {
        [$reason, $texts, $outcome] = match ($verdict['verdict']) {
            AssistantScopeGuard::VERDICT_OUT_OF_SCOPE => [
                AiConversation::REASON_OUT_OF_SCOPE,
                AssistantReplies::OUT_OF_SCOPE,
                AiAssistantLog::OUTCOME_OUT_OF_SCOPE,
            ],
            AssistantScopeGuard::VERDICT_INJECTION => [
                AiConversation::REASON_INJECTION,
                AssistantReplies::OUT_OF_SCOPE,
                AiAssistantLog::OUTCOME_ESCALATED,
            ],
            default => [
                AiConversation::REASON_CUSTOMER_REQUEST,
                AssistantReplies::HANDOVER,
                AiAssistantLog::OUTCOME_ESCALATED,
            ],
        };

        $this->handover->handOver(
            $message->customer,
            $conversation,
            $reason,
            (string) $message->body,
            // Der erkannte Auslöser hilft dem Mitarbeiter beim Einordnen;
            // die Kundennachricht selbst steht sowieso im Chat.
            $verdict['hint'] ? 'Erkannt an: "' . $verdict['hint'] . '"' : null,
        );

        $reply = $this->reply($message, AssistantReplies::pick($texts, $language));

        $this->log($conversation, $message, $outcome, [
            'verdict' => $verdict['verdict'],
            'hint' => $verdict['hint'],
        ], handover: true, reply: $reply, duration: $started, inScope: false);

        return $reply;
    }

    /**
     * Fallback, wenn der KI-Dienst nicht verfuegbar ist (Abschnitt 31):
     * der Kunde bekommt eine ehrliche Nachricht, das Team eine Glocke, und
     * die Anfrage liegt als Uebergabe vor. Der Kundenservice faellt nicht
     * aus.
     */
    private function fallback(
        CustomerMessage $message,
        AiConversation $conversation,
        string $language,
        float $started,
        string $error,
    ): CustomerMessage {
        // Abschnitt 13: die Stoerung wird SICHTBAR - Grund, letzter
        // erfolgreicher Schritt und der Schritt, an dem es scheiterte,
        // stehen am Gespraech. Nie wieder "es passiert einfach nichts".
        $conversation->pause($error, $conversation->current_step ?: 'Antwort erstellen');
        $this->journal->record($conversation, AiConversationEvent::EVENT_ERROR, [
            'fehler' => Str::limit($error, 200),
            'schritt' => $conversation->current_step,
        ], AiConversationEvent::ACTOR_SYSTEM);

        $this->handover->handOver(
            $message->customer,
            $conversation,
            AiConversation::REASON_SERVICE_DOWN,
            (string) $message->body,
            'Der KI-Dienst war nicht verfügbar.',
        );

        // Zusaetzliche technische Glocke an die Verwaltung: eine Stoerung
        // muss sichtbar werden, nicht nur der Einzelfall.
        Notify::pushMany(
            \App\Models\User::whereIn('role', ['admin', 'manager'])->pluck('id'),
            [
                'type' => NotificationService::TYPE_SYSTEM,
                'title' => '⚠️ KI-Service nicht verfügbar',
                'body' => 'Der KI-Kundenassistent konnte nicht antworten: ' . Str::limit($error, 160),
                'link' => route('admin.settings'),
                // Eine Stoerung = eine Glocke, nicht je Kundennachricht.
                'dedup_key' => 'ai-assistant-down',
            ]
        );

        $reply = $this->reply($message, AssistantReplies::pick(AssistantReplies::FALLBACK, $language));

        $this->log($conversation, $message, AiAssistantLog::OUTCOME_FALLBACK, [
            'fehler' => Str::limit($error, 300),
        ], handover: true, reply: $reply, duration: $started);

        return $reply;
    }

    /**
     * Grenzen pruefen (Abschnitt 30/32). Liefert den Grund als Text, wenn
     * eine Grenze erreicht ist, sonst null.
     */
    private function limitExceeded(AiConversation $conversation): ?string
    {
        $maxPerCase = $this->settings->maxRepliesPerCase();
        if ($maxPerCase > 0 && $conversation->auto_reply_count >= $maxPerCase) {
            return 'Grenze automatischer Antworten je Vorgang erreicht (' . $maxPerCase . ').';
        }

        $perHour = max(1, (int) config('services.ai_assistant.rate_per_hour', 20));
        $key = 'ai-assistant:' . $conversation->customer_id;
        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            return 'Zu viele Anfragen dieses Kunden in kurzer Zeit (' . $perHour . '/Stunde).';
        }
        RateLimiter::hit($key, 3600);

        $dailyLimit = (int) config('services.ai_assistant.daily_reply_limit', 500);
        if ($dailyLimit > 0 && (int) Cache::get($this->dailyKey(), 0) >= $dailyLimit) {
            return 'Tagesgrenze der KI-Antworten erreicht (' . $dailyLimit . ').';
        }

        return null;
    }

    private function countDailyReply(): void
    {
        $key = $this->dailyKey();
        // Erst anlegen (mit Ablauf), dann erhoehen - increment() auf einem
        // fehlenden Schluessel legt in manchen Cache-Treibern keinen an.
        Cache::add($key, 0, now()->endOfDay());
        Cache::increment($key);
    }

    private function dailyKey(): string
    {
        return 'ai-assistant:replies:' . now()->format('Y-m-d');
    }

    /**
     * Antwort als normale Chat-Nachricht schreiben - gekennzeichnet als
     * KI-Antwort (Abschnitt 26/27). Kein eigener Kanal, keine
     * Sonderbehandlung im Frontend.
     */
    private function reply(CustomerMessage $message, string $text): CustomerMessage
    {
        $reply = CustomerMessage::create([
            'customer_id' => $message->customer_id,
            // Kein sender_id: es war kein Mensch. Die Anzeige nennt den
            // Assistenten beim Namen, statt einen Kollegen zu behaupten.
            'sender_id' => null,
            'body' => $text,
            'from_staff' => true,
            'ai_generated' => true,
            'email_mode' => 'none',
        ]);

        // Portal-Glocke, aber bewusst KEINE E-Mail: der Kunde ist gerade im
        // Chat, eine Mail je Assistenten-Antwort waere Belaestigung.
        CustomerMessageNotifier::notifyCustomer($reply, 'none');

        return $reply;
    }

    /**
     * Antworttext saeubern: Laenge begrenzen und interne Spuren entfernen.
     * Der Kunde soll nie Funktionsnamen oder Rollenmarkierungen sehen.
     */
    private function sanitize(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // Etwaige Rollen-/Systemmarkierungen am Anfang entfernen.
        $text = preg_replace('/^(system|assistant|user)\s*:\s*/i', '', $text) ?? $text;

        return Str::limit(trim($text), 2000);
    }

    /**
     * Audit-Eintrag (Abschnitt 22). Bewusst OHNE Nachrichtentext und ohne
     * Prompt - nur Absicht, Tools, Aktionen, Ergebnis.
     *
     * @param array<string,mixed> $detail
     * @param list<string> $tools
     * @param list<array<string,mixed>> $actions
     */
    private function log(
        AiConversation $conversation,
        CustomerMessage $message,
        string $outcome,
        array $detail = [],
        bool $handover = false,
        ?CustomerMessage $reply = null,
        ?float $duration = null,
        array $tools = [],
        array $actions = [],
        array $usage = [],
        bool $inScope = true,
    ): void {
        try {
            AiAssistantLog::create([
                'conversation_id' => $conversation->id,
                'customer_id' => $conversation->customer_id,
                'customer_message_id' => $message->id,
                'reply_message_id' => $reply?->id,
                'intent' => $detail['verdict'] ?? null,
                'in_scope' => $inScope,
                'outcome' => $outcome,
                'handover' => $handover,
                'employee_id' => $conversation->assigned_employee_id,
                'tools' => array_values(array_unique($tools)),
                'actions' => $actions,
                'detail' => $detail,
                'provider' => $usage['provider'] ?? null,
                'model' => $usage['model'] ?? null,
                'input_tokens' => $usage['input'] ?? null,
                'output_tokens' => $usage['output'] ?? null,
                'duration_ms' => $duration ? (int) round((microtime(true) - $duration) * 1000) : null,
            ]);
        } catch (\Throwable $e) {
            // Ein fehlgeschlagenes Protokoll darf die Kundenantwort nie
            // verhindern (gleiche Haltung wie im NotificationService).
            Log::warning('KI-Assistent: Protokoll konnte nicht geschrieben werden: ' . $e->getMessage());
        }
    }
}
