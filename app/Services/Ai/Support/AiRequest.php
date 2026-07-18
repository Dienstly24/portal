<?php
namespace App\Services\Ai\Support;

/**
 * Provider-unabhaengige Anfrage an ein LLM. Ein Adapter (Claude, spaeter
 * OpenAI/Gemini/Azure) uebersetzt sie in sein eigenes Request-Format.
 *
 * $parts ist eine normalisierte Liste von Inhaltsteilen:
 *   ['type' => 'text',     'text' => '...']
 *   ['type' => 'document', 'mime' => 'application/pdf', 'data' => <binary>]
 *   ['type' => 'image',    'mime' => 'image/jpeg',      'data' => <binary>]
 * So bleibt die Vision-Faehigkeit erhalten, ohne die Engine an einen
 * Anbieter zu binden.
 */
final class AiRequest
{
    /** @param list<array<string,mixed>> $parts */
    public function __construct(
        public readonly string $system,
        public readonly array $parts,
        public readonly int $maxTokens = 1024,
        public readonly float $temperature = 0.0,
    ) {
    }

    /** Reine Text-Anfrage (System-Prompt + eine Nutzer-Nachricht). */
    public static function text(string $system, string $prompt, int $maxTokens = 1024): self
    {
        return new self($system, [['type' => 'text', 'text' => $prompt]], $maxTokens);
    }

    /** Kopie mit vorangestelltem Dokument/Bild (fuer Vision-Analyse). */
    public function withBinary(string $binary, string $mime): self
    {
        $type = str_starts_with($mime, 'image/') ? 'image' : 'document';
        return new self(
            $this->system,
            [['type' => $type, 'mime' => $mime, 'data' => $binary], ...$this->parts],
            $this->maxTokens,
            $this->temperature,
        );
    }
}
