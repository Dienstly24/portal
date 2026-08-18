<?php
namespace App\Services\Ai\Assistant;

use App\Services\Ai\Assistant\Contracts\AssistantProviderInterface;
use App\Services\Ai\Assistant\Support\AssistantTurn;
use Illuminate\Support\Facades\Http;

/**
 * Claude/Anthropic-Adapter des KI-Kundenassistenten.
 *
 * Warum es diesen Adapter gibt (Betreiber-Entscheidung 17.08.2026): das
 * System nutzt fuer die Dokumentanalyse bereits einen ANTHROPIC_API_KEY.
 * Anthropic und OpenAI sind getrennte Anbieter - ein Anthropic-Schluessel
 * kann sich NIE gegen api.openai.com anmelden. Statt einen zweiten
 * Vertrag/Zugang zu eroeffnen, spricht der Assistent hier ueber die
 * Messages API von Anthropic und nutzt DENSELBEN vorhandenen Schluessel.
 * Auswahl per AI_ASSISTANT_PROVIDER=claude.
 *
 * Genutzt wird Tool Use der Messages API: Funktionen werden als `tools`
 * uebergeben, das Modell antwortet mit `tool_use`-Bloecken, die Ergebnisse
 * gehen als `tool_result` zurueck.
 *
 * SICHERHEIT / KOSTEN:
 *  - Schluessel nur aus der Server-Konfiguration, immer als `x-api-key`-
 *    HEADER (nie Query/Body - sonst steht er in Fehlermeldungen).
 *  - KEINE Sampling-Parameter (temperature/top_p/top_k): die aktuellen
 *    Modelle lehnen sie mit HTTP 400 ab.
 *  - `max_tokens` deckelt Denk- UND Antwort-Tokens gemeinsam. Der Wert ist
 *    deshalb bewusst grosszuegig, obwohl die Antwort kurz sein soll -
 *    sonst bricht die Antwort mitten im Satz ab.
 *  - Gedacht wird bewusst mit geringem Aufwand (`effort`): eine
 *    Kundenservice-Auskunft ist eine Nachschlage-Aufgabe, keine
 *    Grundsatzanalyse. Das Denken ganz abzuschalten waere die schlechtere
 *    Wahl - ohne Denken schreiben die Modelle Funktionsaufrufe
 *    gelegentlich als FLIESSTEXT (der Aufruf laeuft dann nie, ohne
 *    Fehlermeldung), was fuer einen Assistenten mit Tools riskant ist.
 */
class ClaudeAssistantProvider implements AssistantProviderInterface
{
    public function isEnabled(): bool
    {
        return trim((string) config('services.anthropic.key')) !== '';
    }

    public function name(): string
    {
        return 'claude';
    }

    public function model(): string
    {
        return (string) config(
            'services.anthropic.assistant_model',
            config('services.anthropic.model', 'claude-opus-5')
        );
    }

    public function turn(string $instructions, array $history, array $tools, int $maxOutputTokens = 700): AssistantTurn
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException('KI-Assistent ist nicht konfiguriert (ANTHROPIC_API_KEY fehlt).');
        }

        // Die Obergrenze deckelt Denken UND Antwort. Der vom Aufrufer
        // gewuenschte Antwortrahmen ist die Untergrenze, der konfigurierte
        // Wert schafft den Kopfraum fuers Denken.
        $maxTokens = max($maxOutputTokens, (int) config('services.anthropic.assistant_max_tokens', 4096));

        $payload = [
            'model' => $this->model(),
            'max_tokens' => $maxTokens,
            'system' => $instructions,
            'messages' => $this->toMessages($history),
            // Denken an, aber sparsam - siehe Klassenkommentar.
            'thinking' => ['type' => 'adaptive'],
            'output_config' => ['effort' => (string) config('services.anthropic.assistant_effort', 'low')],
        ];

        if ($tools !== []) {
            $payload['tools'] = array_map(fn ($t) => [
                'name' => $t['name'],
                'description' => $t['description'] ?? '',
                'input_schema' => $t['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ], $tools);
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('services.anthropic.key'),
                'anthropic-version' => '2023-06-01',
            ])
                ->timeout((int) config('services.anthropic.assistant_timeout', 45))
                ->connectTimeout((int) config('services.anthropic.assistant_connect_timeout', 10))
                ->post($this->endpoint(), $payload);
        } catch (\Throwable $e) {
            throw new \RuntimeException('KI-Dienst nicht erreichbar: ' . $e->getMessage());
        }

        if (!$response->successful()) {
            throw new \RuntimeException(
                'KI-Dienst antwortete mit HTTP ' . $response->status()
                . ' (' . substr((string) $response->json('error.message', ''), 0, 200) . ')'
            );
        }

        return $this->parse($response->json() ?? []);
    }

    /**
     * Endpunkt aus der Basis-URL.
     *
     * KONVENTION (wie bei den offiziellen Anthropic-SDKs): ANTHROPIC_BASE_URL
     * ist die HOST-Wurzel ohne Versionspfad, der Pfad `/v1/messages` kommt
     * vom Client. Genau so setzen Hosting-Umgebungen die Variable - eine
     * Basis ohne `/v1` haette hier sonst zu `.../messages` gefuehrt und
     * jeden Aufruf ins Leere laufen lassen.
     *
     * Eine Basis, die `/v1` bereits mitbringt, wird toleriert (frueherer
     * Standardwert dieses Projekts), damit bestehende Konfigurationen nicht
     * plötzlich `/v1/v1/messages` ansprechen.
     */
    private function endpoint(): string
    {
        $base = rtrim((string) config('services.anthropic.base_url', 'https://api.anthropic.com'), '/');
        if (str_ends_with($base, '/v1')) {
            $base = substr($base, 0, -3);
        }

        return rtrim($base, '/') . '/v1/messages';
    }

    /**
     * Normalisierten Verlauf in Anthropic-Nachrichten uebersetzen.
     *
     * Regel der Messages API: `tool_use` gehoert in eine ASSISTENTEN-
     * Nachricht, die zugehoerigen `tool_result` in die DARAUFFOLGENDE
     * Nutzer-Nachricht - und zwar ALLE Ergebnisse einer Runde in EINER
     * Nachricht. Aufteilen bringt das Modell dazu, kuenftig keine
     * parallelen Aufrufe mehr zu machen. Der Orchestrator liefert die
     * Runde bereits in dieser Reihenfolge (erst alle Aufrufe, dann alle
     * Ergebnisse); hier werden aufeinanderfolgende gleichartige Eintraege
     * zu je einer Nachricht gebuendelt.
     *
     * @return list<array<string,mixed>>
     */
    private function toMessages(array $history): array
    {
        $messages = [];
        /** @var list<array<string,mixed>> $pendingCalls */
        $pendingCalls = [];
        /** @var list<array<string,mixed>> $pendingResults */
        $pendingResults = [];

        $flushCalls = function () use (&$messages, &$pendingCalls) {
            if ($pendingCalls !== []) {
                $messages[] = ['role' => 'assistant', 'content' => $pendingCalls];
                $pendingCalls = [];
            }
        };
        $flushResults = function () use (&$messages, &$pendingResults) {
            if ($pendingResults !== []) {
                $messages[] = ['role' => 'user', 'content' => $pendingResults];
                $pendingResults = [];
            }
        };

        foreach ($history as $item) {
            switch ($item['role'] ?? 'user') {
                case 'tool_call':
                    // Ein neuer Aufruf beendet eine offene Ergebnis-Gruppe.
                    $flushResults();
                    $arguments = json_decode((string) ($item['arguments'] ?? '{}'), true);
                    $pendingCalls[] = [
                        'type' => 'tool_use',
                        'id' => (string) $item['call_id'],
                        'name' => (string) $item['name'],
                        // Anthropic erwartet ein Objekt, keinen JSON-String.
                        'input' => is_array($arguments) && $arguments !== [] ? $arguments : new \stdClass(),
                    ];
                    break;

                case 'tool_result':
                    $flushCalls();
                    $pendingResults[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => (string) $item['call_id'],
                        'content' => (string) ($item['output'] ?? ''),
                    ];
                    break;

                case 'assistant':
                    $flushCalls();
                    $flushResults();
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => [['type' => 'text', 'text' => (string) ($item['text'] ?? '')]],
                    ];
                    break;

                default:
                    $flushCalls();
                    $flushResults();
                    $messages[] = [
                        'role' => 'user',
                        'content' => [['type' => 'text', 'text' => (string) ($item['text'] ?? '')]],
                    ];
            }
        }

        $flushCalls();
        $flushResults();

        return $messages;
    }

    /** Rohe Antwort in die normalisierte AssistantTurn uebersetzen. */
    private function parse(array $json): AssistantTurn
    {
        $text = '';
        $toolCalls = [];
        $rawCalls = [];

        foreach ($json['content'] ?? [] as $block) {
            switch ($block['type'] ?? '') {
                case 'text':
                    $text .= (string) ($block['text'] ?? '');
                    break;

                case 'tool_use':
                    $input = $block['input'] ?? [];
                    $toolCalls[] = [
                        'call_id' => (string) ($block['id'] ?? ''),
                        'name' => (string) ($block['name'] ?? ''),
                        'arguments' => is_array($input) ? $input : [],
                    ];
                    $rawCalls[] = [
                        'call_id' => (string) ($block['id'] ?? ''),
                        'name' => (string) ($block['name'] ?? ''),
                        'arguments' => json_encode(is_array($input) ? $input : [], JSON_UNESCAPED_UNICODE) ?: '{}',
                    ];
                    break;

                // 'thinking'-Bloecke werden bewusst verworfen: sie gehen den
                // Kunden nichts an und landen auch nicht im Protokoll.
            }
        }

        // Sicherheits-Ablehnung des Modells: es gibt keine verwertbare
        // Antwort. Leerer Text -> der Orchestrator uebergibt an das Team,
        // statt dem Kunden eine leere Blase zu schicken.
        if (($json['stop_reason'] ?? '') === 'refusal') {
            $text = '';
            $toolCalls = [];
            $rawCalls = [];
        }

        return new AssistantTurn(
            text: trim($text),
            toolCalls: $toolCalls,
            inputTokens: $json['usage']['input_tokens'] ?? null,
            outputTokens: $json['usage']['output_tokens'] ?? null,
            provider: $this->name(),
            model: (string) ($json['model'] ?? $this->model()),
            rawCalls: $rawCalls,
        );
    }
}
