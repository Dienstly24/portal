<?php
namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Support\AiRequest;
use App\Services\Ai\Support\AiResponse;
use Illuminate\Support\Facades\Http;

/**
 * Claude/Anthropic-Adapter der provider-unabhaengigen AI-Schicht.
 * Uebersetzt eine AiRequest in die Anthropic Messages API und normalisiert
 * die Antwort zu einer AiResponse. Gleiches HTTP-/Sicherheitsmuster wie
 * AiEmailClassifier und ClaudeDocumentAiProvider (untrusted content =
 * Daten, nie Anweisung; harte Validierung erst beim Aufrufer).
 */
class ClaudeTextProvider implements AiProviderInterface
{
    public function isEnabled(): bool
    {
        return (string) config('services.anthropic.key') !== '';
    }

    public function name(): string
    {
        return 'claude';
    }

    public function model(): string
    {
        return (string) config('services.anthropic.model', 'claude-sonnet-5');
    }

    public function complete(AiRequest $request): AiResponse
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException('KI-Anbieter (Claude) ist nicht konfiguriert (ANTHROPIC_API_KEY fehlt).');
        }

        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
            'model' => $this->model(),
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'system' => $request->system,
            'messages' => [[
                'role' => 'user',
                'content' => $this->toContentBlocks($request->parts),
            ]],
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('KI-Dienst (Claude) antwortete mit HTTP ' . $response->status());
        }

        return new AiResponse(
            text: (string) ($response->json('content.0.text') ?? ''),
            inputTokens: $response->json('usage.input_tokens'),
            outputTokens: $response->json('usage.output_tokens'),
            stopReason: $response->json('stop_reason'),
            provider: $this->name(),
            model: $this->model(),
        );
    }

    /** Normalisierte Parts in Anthropic-Content-Bloecke uebersetzen. */
    private function toContentBlocks(array $parts): array
    {
        $blocks = [];
        foreach ($parts as $part) {
            $blocks[] = match ($part['type'] ?? 'text') {
                'document' => [
                    'type' => 'document',
                    'source' => ['type' => 'base64', 'media_type' => $part['mime'], 'data' => base64_encode($part['data'])],
                ],
                'image' => [
                    'type' => 'image',
                    'source' => ['type' => 'base64', 'media_type' => $part['mime'], 'data' => base64_encode($part['data'])],
                ],
                default => ['type' => 'text', 'text' => (string) ($part['text'] ?? '')],
            };
        }
        return $blocks;
    }
}
