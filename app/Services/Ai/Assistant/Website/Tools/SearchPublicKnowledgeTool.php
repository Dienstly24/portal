<?php
namespace App\Services\Ai\Assistant\Website\Tools;

use App\Models\AiKnowledgeGap;
use App\Services\Ai\Assistant\KnowledgeBase;
use App\Services\Ai\Assistant\Website\LeadContext;
use App\Services\Ai\Assistant\Website\LeadTool;

/**
 * Wissensbasis fuer NICHT ANGEMELDETE Besucher (Abschnitte 18 und 19).
 *
 * Gleicher Bestand wie im Portal, aber nur die oeffentlich geeigneten
 * Kategorien (Haeufige Fragen, benoetigte Unterlagen, Produkte). Interne
 * Ablaeufe, Eskalationsregeln und Gespraechsleitfaeden bleiben aussen vor
 * - sie sind Arbeitsanweisungen fuer Mitarbeiter.
 */
class SearchPublicKnowledgeTool implements LeadTool
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
        return 'Suche in den oeffentlichen Informationen von Dienstly24 (Leistungen, '
            . 'haeufige Fragen, benoetigte Unterlagen). Nutze das IMMER, bevor du eine '
            . 'allgemeine Frage beantwortest. Kein Treffer bedeutet: du darfst die '
            . 'Frage NICHT aus eigenem Wissen beantworten, sondern nutzt '
            . 'requestHumanContact.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'suchbegriff' => [
                    'type' => 'string',
                    'description' => 'Thema der Frage in Stichworten.',
                ],
            ],
            'required' => ['suchbegriff'],
        ];
    }

    public function run(array $arguments, LeadContext $context): array
    {
        $query = trim((string) ($arguments['suchbegriff'] ?? ''));
        if ($query === '') {
            return ['treffer' => 0, 'eintraege' => []];
        }

        $treffer = $this->knowledgeBase->search($query, $context->language, 4, publicOnly: true);

        // Auch die Fragen der Website-Besucher sind eine Rueckmeldung
        // ueber unsere Wissensbasis - getrennt gezaehlt, weil hier nur
        // die oeffentlichen Kategorien durchsucht werden.
        if ($treffer->isEmpty()) {
            AiKnowledgeGap::record($query, AiKnowledgeGap::SCOPE_WEBSITE, $context->language);
        }

        return [
            'treffer' => $treffer->count(),
            'eintraege' => $treffer->map(fn ($e) => [
                'titel' => $e->title,
                'inhalt' => $e->content,
            ])->values()->all(),
            'hinweis' => $treffer->isEmpty()
                ? 'Kein Treffer - beantworte die Frage NICHT aus eigenem Wissen.'
                : null,
        ];
    }
}
