<?php
namespace App\Services\Ai\Support;

/**
 * Provider-unabhaengige Antwort eines LLM. Ein Adapter fuellt sie aus
 * seiner rohen Antwort; die hoeheren Dienste (IntentDetector,
 * FieldExtractor, ReplyGenerator) arbeiten nur mit dieser Klasse.
 */
final class AiResponse
{
    public function __construct(
        public readonly string $text,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?string $stopReason = null,
        public readonly string $provider = '',
        public readonly string $model = '',
    ) {
    }

    /**
     * Erstes JSON-Objekt aus dem Antworttext herausloesen und dekodieren.
     * Zentralisiert das ueberall genutzte Muster "peel first {...}".
     * Liefert null, wenn kein gueltiges Objekt gefunden wird - der Aufrufer
     * validiert das Ergebnis danach immer noch hart gegen Whitelists.
     */
    public function json(): ?array
    {
        if (!preg_match('/\{.*\}/s', $this->text, $m)) {
            return null;
        }
        $decoded = json_decode($m[0], true);
        return is_array($decoded) ? $decoded : null;
    }
}
