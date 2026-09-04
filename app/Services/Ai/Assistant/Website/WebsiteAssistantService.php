<?php

namespace App\Services\Ai\Assistant\Website;

use App\Models\AiLead;
use App\Services\Ai\Assistant\AssistantReplies;
use App\Services\Ai\Assistant\AssistantScopeGuard;
use App\Services\Ai\Assistant\AssistantSettings;
use App\Services\Ai\Assistant\Contracts\AssistantProviderInterface;
use App\Services\Ai\Assistant\LanguageDetector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Der Website-Assistent fuer nicht angemeldete Besucher
 * (Spezifikation Abschnitte 19 und 20).
 *
 * Gleiche Bauart wie der Kundenassistent, aber mit HARTER Trennung:
 * eigener Kontext, eigene Werkzeug-Whitelist, eigener Prompt. Ein
 * Besucher kann strukturell keine Kundendaten erreichen - es gibt kein
 * Werkzeug dafuer, und die Typen der Kunden-Werkzeuge passen hier nicht
 * einmal hinein.
 *
 * Auch hier gilt die Reihenfolge "kostenlos zuerst": die deterministische
 * Vorpruefung lehnt Themenfremdes und Regel-Umgehungen ab, bevor ein
 * bezahlter Aufruf entsteht.
 */
class WebsiteAssistantService
{
    public function __construct(
        private AssistantProviderInterface $provider,
        private AssistantSettings $settings,
        private AssistantScopeGuard $scopeGuard,
        private LeadToolRegistry $tools,
        private LeadService $leads,
        private LanguageDetector $languageDetector,
    ) {
    }

    /**
     * Eine Besuchernachricht beantworten.
     *
     * @return array{antwort: string, uebergeben: bool, zustand: string}
     */
    public function handle(AiLead $lead, string $message): array
    {
        $language = $this->languageDetector->detect($message, null);
        $message = Str::limit(trim($message), (int) config('services.ai_assistant.max_message_chars', 4000), '');

        if (! $this->settings->enabled()) {
            return $this->handOver($lead, 'frage', $language, AssistantReplies::FALLBACK);
        }

        // Kostenlose Vorpruefung - erreicht das Modell gar nicht erst.
        $verdict = $this->scopeGuard->check($message);
        if ($verdict['verdict'] === AssistantScopeGuard::VERDICT_INJECTION
            || $verdict['verdict'] === AssistantScopeGuard::VERDICT_OUT_OF_SCOPE) {
            return $this->antwort($lead, AssistantReplies::pick(AssistantReplies::OUT_OF_SCOPE, $language), false);
        }
        if ($verdict['verdict'] === AssistantScopeGuard::VERDICT_WANTS_HUMAN) {
            return $this->handOver($lead, 'mitarbeiter_gewuenscht', $language, AssistantReplies::HANDOVER);
        }

        if (! $this->provider->isEnabled()) {
            return $this->handOver($lead, 'frage', $language, AssistantReplies::FALLBACK);
        }

        $lead->appendTranscript('besucher', $message);
        $context = new LeadContext($lead, $language);

        try {
            $text = $this->runDialog($lead, $message, $context, $language);
        } catch (\Throwable $e) {
            Log::warning('Website-Assistent: Dialog fehlgeschlagen', ['fehler' => $e->getMessage()]);

            return $this->handOver($lead, 'frage', $language, AssistantReplies::FALLBACK);
        }

        if (trim($text) === '') {
            return $this->handOver($lead, 'frage', $language, AssistantReplies::HANDOVER);
        }

        return $this->antwort($lead, $text, $context->wantsHuman);
    }

    /**
     * Werkzeug-Dialog mit harten Obergrenzen - dieselbe Kostenkontrolle
     * wie im Portal.
     */
    private function runDialog(AiLead $lead, string $message, LeadContext $context, string $language): string
    {
        $maxRounds = max(1, (int) config('services.ai_assistant.max_tool_rounds', 4));
        $maxCalls = max(1, (int) config('services.ai_assistant.max_tool_calls', 8));

        $history = $this->history($lead, $message);
        $schemas = $this->tools->schemas();
        $calls = 0;

        for ($round = 0; $round < $maxRounds; $round++) {
            $turn = $this->provider->turn(
                (new WebsitePrompt)->build($lead, $language),
                $history,
                $schemas,
                (int) config('services.ai_assistant.max_output_tokens', 700),
            );

            if (! $turn->wantsTools()) {
                return $turn->text;
            }

            $roundCalls = [];
            $roundResults = [];

            foreach ($turn->toolCalls as $index => $call) {
                if (++$calls > $maxCalls) {
                    break 2;
                }

                $result = $this->tools->execute((string) $call['name'], (array) $call['arguments'], $context);

                $roundCalls[] = [
                    'role' => 'tool_call',
                    'call_id' => (string) $call['call_id'],
                    'name' => (string) $call['name'],
                    'arguments' => $turn->rawCalls[$index]['arguments'] ?? '{}',
                ];
                $roundResults[] = [
                    'role' => 'tool_result',
                    'call_id' => (string) $call['call_id'],
                    'output' => json_encode($result, JSON_UNESCAPED_UNICODE) ?: '{}',
                ];
            }

            // ERST alle Aufrufe, DANN alle Ergebnisse (Anthropic verlangt
            // die Gruppierung; aufgeteilt verlernt das Modell parallele
            // Aufrufe).
            $history = array_merge($history, $roundCalls, $roundResults);
        }

        return '';
    }

    /** Verlauf aus den gespeicherten Nachrichten des Leads. */
    private function history(AiLead $lead, string $message): array
    {
        $history = [];
        foreach (array_slice($lead->transcriptData(), -8) as $eintrag) {
            $history[] = [
                'role' => ($eintrag['rolle'] ?? 'user') === 'ai' ? 'assistant' : 'user',
                'text' => (string) ($eintrag['text'] ?? ''),
            ];
        }

        $history[] = ['role' => 'user', 'text' => $message];

        return $history;
    }

    /** Antwort festhalten (Verlauf am Lead) und zurueckgeben. */
    private function antwort(AiLead $lead, string $text, bool $uebergeben): array
    {
        $lead->appendTranscript('ai', $text);

        return [
            'antwort' => $text,
            'uebergeben' => $uebergeben,
            'zustand' => $lead->fresh()->state,
        ];
    }

    /** Uebergabe an das Team samt fester Antwort an den Besucher. */
    private function handOver(AiLead $lead, string $reason, string $language, array $texte): array
    {
        $this->leads->handOver($lead, $reason);

        return $this->antwort($lead, AssistantReplies::pick($texte, $language), true);
    }
}
