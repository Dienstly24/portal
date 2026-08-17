<?php
namespace App\Services\Ai\Assistant;

use App\Services\Ai\Assistant\Contracts\AssistantProviderInterface;
use App\Services\Ai\Assistant\Support\AssistantTurn;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI-Adapter des KI-Kundenassistenten (Spezifikation Abschnitt 28:
 * "aktuell empfohlene API verwenden, keine veraltete Integration").
 *
 * Genutzt wird die RESPONSES API (POST /v1/responses) - OpenAIs aktuelle
 * Schnittstelle; Funktionsaufrufe stehen dort als eigene Elemente im
 * `output`-Array und werden fuer die naechste Runde unveraendert
 * zurueckgegeben (`function_call` + `function_call_output`).
 *
 * SICHERHEIT:
 *  - Der API-Key kommt AUSSCHLIESSLICH aus der Server-Konfiguration
 *    (config/services.php -> env OPENAI_API_KEY). Er geht als
 *    Bearer-HEADER raus (nie Query/Body - sonst steht er in
 *    Fehlermeldungen) und wird NIE geloggt oder an das Frontend gegeben.
 *  - Systemregeln reisen in `instructions`, Kundentext ausschliesslich als
 *    Nutzer-Inhalt. Der Kundentext kann damit die Regeln nicht ersetzen
 *    (Abschnitt 20).
 *  - Harte Zeitgrenze; jeder Fehler wird zur RuntimeException, damit der
 *    Aufrufer auf den Fallback umschalten kann (Abschnitt 31).
 */
class OpenAiAssistantProvider implements AssistantProviderInterface
{
    public function isEnabled(): bool
    {
        return trim((string) config('services.openai.key')) !== '';
    }

    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return (string) config('services.openai.model', 'gpt-5');
    }

    public function turn(string $instructions, array $history, array $tools, int $maxOutputTokens = 700): AssistantTurn
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException('KI-Assistent ist nicht konfiguriert (OPENAI_API_KEY fehlt).');
        }

        $payload = [
            'model' => $this->model(),
            'instructions' => $instructions,
            'input' => $this->toInput($history),
            'max_output_tokens' => $maxOutputTokens,
            // Kundenservice-Auskunft: moeglichst wenig Streuung. Manche
            // Modelle akzeptieren nur den Standardwert - deshalb
            // konfigurierbar und bei null gar nicht mitsenden.
            'store' => (bool) config('services.openai.store', false),
        ];

        if ($tools !== []) {
            $payload['tools'] = array_map(fn ($t) => [
                'type' => 'function',
                'name' => $t['name'],
                'description' => $t['description'] ?? '',
                'parameters' => $t['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ], $tools);
            $payload['tool_choice'] = 'auto';
        }

        $temperature = config('services.openai.temperature');
        if ($temperature !== null && $temperature !== '') {
            $payload['temperature'] = (float) $temperature;
        }

        try {
            $response = Http::withToken((string) config('services.openai.key'))
                ->timeout((int) config('services.openai.timeout', 45))
                ->connectTimeout((int) config('services.openai.connect_timeout', 10))
                ->post($this->endpoint(), $payload);
        } catch (\Throwable $e) {
            // Netzfehler/Timeout: bewusst OHNE Anbieter-Details nach aussen.
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

    private function endpoint(): string
    {
        $base = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');

        return $base . '/responses';
    }

    /**
     * Normalisierten Verlauf in Responses-API-Elemente uebersetzen.
     *
     * Funktionsaufrufe muessen in der Folge-Runde MIT ihrem Ergebnis
     * wieder mitgeschickt werden - sonst weiss das Modell nicht, was seine
     * Funktion geliefert hat.
     */
    private function toInput(array $history): array
    {
        $input = [];
        foreach ($history as $item) {
            switch ($item['role'] ?? 'user') {
                case 'assistant':
                    $input[] = [
                        'role' => 'assistant',
                        'content' => [['type' => 'output_text', 'text' => (string) ($item['text'] ?? '')]],
                    ];
                    break;

                case 'tool_call':
                    $input[] = [
                        'type' => 'function_call',
                        'call_id' => (string) $item['call_id'],
                        'name' => (string) $item['name'],
                        'arguments' => (string) ($item['arguments'] ?? '{}'),
                    ];
                    break;

                case 'tool_result':
                    $input[] = [
                        'type' => 'function_call_output',
                        'call_id' => (string) $item['call_id'],
                        'output' => (string) ($item['output'] ?? ''),
                    ];
                    break;

                default:
                    $input[] = [
                        'role' => 'user',
                        'content' => [['type' => 'input_text', 'text' => (string) ($item['text'] ?? '')]],
                    ];
            }
        }

        return $input;
    }

    /** Rohe Antwort in die normalisierte AssistantTurn uebersetzen. */
    private function parse(array $json): AssistantTurn
    {
        $text = '';
        $toolCalls = [];
        $rawCalls = [];

        foreach ($json['output'] ?? [] as $item) {
            $type = $item['type'] ?? '';

            if ($type === 'function_call') {
                $arguments = json_decode((string) ($item['arguments'] ?? '{}'), true);
                $toolCalls[] = [
                    'call_id' => (string) ($item['call_id'] ?? $item['id'] ?? ''),
                    'name' => (string) ($item['name'] ?? ''),
                    'arguments' => is_array($arguments) ? $arguments : [],
                ];
                $rawCalls[] = [
                    'call_id' => (string) ($item['call_id'] ?? $item['id'] ?? ''),
                    'name' => (string) ($item['name'] ?? ''),
                    'arguments' => (string) ($item['arguments'] ?? '{}'),
                ];
                continue;
            }

            if ($type === 'message') {
                foreach ($item['content'] ?? [] as $part) {
                    if (($part['type'] ?? '') === 'output_text') {
                        $text .= (string) ($part['text'] ?? '');
                    }
                }
            }
        }

        // Bequemlichkeitsfeld der API; falls vorhanden und wir sonst nichts
        // gefunden haben, nutzen wir es.
        if (trim($text) === '' && isset($json['output_text']) && is_string($json['output_text'])) {
            $text = $json['output_text'];
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
