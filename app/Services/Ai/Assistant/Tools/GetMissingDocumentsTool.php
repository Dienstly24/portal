<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Services\Ai\Assistant\DocumentStatusReader;

/**
 * "Welche Unterlagen fehlen mir?" (Spezifikation Abschnitt 7/10 -
 * getMissingDocuments). Der haeufigste Fall des Assistenten.
 */
class GetMissingDocumentsTool implements AssistantTool
{
    public function __construct(private DocumentStatusReader $reader)
    {
    }

    public function name(): string
    {
        return 'getMissingDocuments';
    }

    public function description(): string
    {
        return 'Welche angeforderten Unterlagen FEHLEN dem angemeldeten Kunden noch '
            .'(inklusive zurueckgewiesener, die erneut benoetigt werden). Nutze das bei '
            .'Fragen wie "welche Unterlagen fehlen mir", "bin ich vollstaendig". '
            .'Achtung: "keine_anforderungen = true" bedeutet, dass NICHTS angefordert '
            .'wurde - das ist nicht dasselbe wie "alles vollstaendig".';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'required' => []];
    }

    public function isWriting(): bool
    {
        return false;
    }

    public function run(array $arguments, AssistantToolContext $context): array
    {
        $overview = $this->reader->overview($context->customer);

        return [
            'fehlt' => $overview['fehlt'],
            'anzahl_fehlend' => count($overview['fehlt']),
            'in_pruefung' => $overview['in_pruefung'],
            'alles_vollstaendig' => $overview['alles_vollstaendig'],
            'keine_anforderungen' => $overview['keine_anforderungen'],
        ];
    }
}
