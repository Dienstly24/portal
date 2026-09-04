<?php

namespace App\Services\Commission;

use Carbon\CarbonInterface;

/**
 * ARCH-3: die Filter, die jede Quelle versteht.
 *
 * Bewusst klein gehalten. Was nur EINE Quelle kann (Storno-Grund des
 * Vermittlers, Faelligkeitsstufe des Maklerpools), gehoert nicht hierher -
 * dafuer gibt es die Fachseiten der jeweiligen Quelle. Dieser Weg
 * beantwortet die Fragen, die ALLE Quellen gemeinsam haben.
 */
final readonly class CommissionQuery
{
    public function __construct(
        public ?CarbonInterface $from = null,
        public ?CarbonInterface $to = null,
        public ?string $contractId = null,
        public ?string $customerId = null,
        /** Nur diese Quellen lesen (Schluessel); null = alle. */
        public ?array $sources = null,
        /** Sicherheitsnetz gegen unbegrenzte Berichte. */
        public int $limit = 1000,
    ) {}

    public function wantsSource(string $key): bool
    {
        return $this->sources === null || in_array($key, $this->sources, true);
    }
}
