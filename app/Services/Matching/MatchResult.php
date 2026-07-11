<?php
namespace App\Services\Matching;

use App\Models\Customer;

/**
 * Ergebnis eines Kunden-/Partner-Matching-Laufs (Architekturplan
 * Abschnitt 5/13). `tier` ist die generalisierte Freigabestufe:
 * auto (>90), confirm (70-90), manual (<70).
 */
final class MatchResult
{
    /** @param array<string, array{points: int, max: int, reason: string}> $breakdown */
    public function __construct(
        public readonly ?Customer $customer,
        public readonly int $score,
        public readonly array $breakdown = [],
    ) {
    }

    public function tier(): string
    {
        return match (true) {
            $this->score > 90 => 'auto',
            $this->score >= 70 => 'confirm',
            default => 'manual',
        };
    }

    public function hasMatch(): bool
    {
        return $this->customer !== null;
    }
}
