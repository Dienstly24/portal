<?php

namespace App\Services\Ai\Assistant\Website;

/**
 * Vertrag eines Werkzeugs des Website-Assistenten.
 *
 * Getrennt von AssistantTool, damit ein Kunden-Werkzeug technisch NIE in
 * den Website-Assistenten geraten kann: die Typen passen schlicht nicht
 * zueinander. Das ist die staerkste Form der Trennung - sie kann nicht
 * durch eine vergessene Pruefung ausgehebelt werden.
 */
interface LeadTool
{
    public function name(): string;

    public function description(): string;

    public function parameters(): array;

    /** @return array<string,mixed> */
    public function run(array $arguments, LeadContext $context): array;
}
