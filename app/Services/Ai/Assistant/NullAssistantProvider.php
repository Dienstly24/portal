<?php

namespace App\Services\Ai\Assistant;

use App\Services\Ai\Assistant\Contracts\AssistantProviderInterface;
use App\Services\Ai\Assistant\Support\AssistantTurn;

/**
 * Ausdrueckliches "kein KI-Anbieter" (AI_ASSISTANT_PROVIDER=none).
 * isEnabled() = false -> der Assistent antwortet gar nicht, das Team
 * bearbeitet die Anfrage wie vor der Integration. Kein Aufruf, keine
 * Kosten, kein stiller Fehler.
 */
class NullAssistantProvider implements AssistantProviderInterface
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'none';
    }

    public function model(): string
    {
        return '';
    }

    public function turn(string $instructions, array $history, array $tools, int $maxOutputTokens = 700): AssistantTurn
    {
        throw new \RuntimeException('Es ist kein KI-Anbieter fuer den Kundenassistenten konfiguriert.');
    }
}
