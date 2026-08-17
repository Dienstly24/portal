<?php
namespace App\Services\Ai\Assistant\Support;

/**
 * Ergebnis EINER Dialog-Runde, anbieter-unabhaengig normalisiert.
 *
 * Entweder das Modell will Funktionen aufrufen ($toolCalls nicht leer)
 * oder es liefert die fertige Antwort ($text). Beides gleichzeitig ist
 * moeglich; der Orchestrator arbeitet dann zuerst die Funktionen ab.
 */
final class AssistantTurn
{
    /**
     * @param list<array{call_id: string, name: string, arguments: array<string,mixed>}> $toolCalls
     */
    public function __construct(
        public readonly string $text = '',
        public readonly array $toolCalls = [],
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly string $provider = '',
        public readonly string $model = '',
        /** Rohe Anbieter-Elemente der Funktionsaufrufe (fuer die naechste Runde). */
        public readonly array $rawCalls = [],
    ) {
    }

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }
}
