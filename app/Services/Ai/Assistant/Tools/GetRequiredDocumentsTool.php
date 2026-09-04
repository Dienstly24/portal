<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Services\Ai\Assistant\DocumentStatusReader;

/**
 * Vollstaendige Dokumentenlage: benoetigt / vorhanden / fehlt / in Pruefung
 * (Spezifikation Abschnitt 6/10 - getRequiredDocuments).
 */
class GetRequiredDocumentsTool implements AssistantTool
{
    public function __construct(private DocumentStatusReader $reader)
    {
    }

    public function name(): string
    {
        return 'getRequiredDocuments';
    }

    public function description(): string
    {
        return 'Alle fuer den angemeldeten Kunden angeforderten Unterlagen mit ihrem Stand: '
            .'vorhanden, in Pruefung, fehlend. Nutze das, wenn der Kunde einen Gesamt-'
            .'ueberblick moechte ("welche Unterlagen brauchen Sie von mir").';
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
        return $this->reader->overview($context->customer);
    }
}
