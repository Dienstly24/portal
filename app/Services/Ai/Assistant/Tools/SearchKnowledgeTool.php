<?php
namespace App\Services\Ai\Assistant\Tools;

use App\Models\AiKnowledgeEntry;
use App\Services\Ai\Assistant\KnowledgeBase;

/**
 * Zugriff auf die freigegebene Wissensbasis (Spezifikation Abschnitt 19).
 *
 * Das ist die EINZIGE erlaubte Quelle fuer allgemeine Auskuenfte zu
 * Dienstly24-Dienstleistungen und Abläufen. Kein Treffer = keine Auskunft:
 * der Assistent darf dann nicht aus eigenem Weltwissen antworten, sondern
 * uebergibt an das Team (Abschnitt 4).
 */
class SearchKnowledgeTool implements AssistantTool
{
    public function __construct(private KnowledgeBase $knowledgeBase)
    {
    }

    public function name(): string
    {
        return 'searchKnowledge';
    }

    public function description(): string
    {
        return 'Suche in der internen, freigegebenen Wissensbasis von Dienstly24 '
            . '(Abläufe, haeufige Fragen, benoetigte Unterlagen, Produkte, '
            . 'Voraussetzungen). Nutze das IMMER, bevor du eine allgemeine Frage zu '
            . 'Dienstly24-Leistungen oder Abläufen beantwortest. Kein Treffer bedeutet: '
            . 'du darfst die Frage NICHT aus eigenem Wissen beantworten, sondern musst '
            . 'escalateToTeam nutzen.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'suchbegriff' => [
                    'type' => 'string',
                    'description' => 'Thema der Kundenfrage in Stichworten, z.B. '
                        . '"Unterlagen Adressaenderung" oder "Kuendigungsfrist Kfz".',
                ],
            ],
            'required' => ['suchbegriff'],
        ];
    }

    public function isWriting(): bool
    {
        return false;
    }

    public function run(array $arguments, AssistantToolContext $context): array
    {
        $query = trim((string) ($arguments['suchbegriff'] ?? ''));
        if ($query === '') {
            return ['treffer' => 0, 'eintraege' => [], 'hinweis' => 'Kein Suchbegriff angegeben.'];
        }

        $entries = $this->knowledgeBase->search($query, $context->language);

        return [
            'treffer' => $entries->count(),
            'eintraege' => $entries->map(fn (AiKnowledgeEntry $e) => [
                'titel' => $e->title,
                'kategorie' => $e->categoryLabel(),
                'inhalt' => $e->content,
            ])->values()->all(),
            'hinweis' => $entries->isEmpty()
                ? 'Kein Eintrag gefunden. Beantworte die Frage NICHT aus eigenem Wissen - '
                    . 'nutze escalateToTeam mit dem Grund "uncertain".'
                : null,
        ];
    }
}
