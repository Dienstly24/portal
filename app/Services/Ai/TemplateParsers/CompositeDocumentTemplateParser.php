<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Buendelt mehrere Vorlagen-Parser (CHECK24-Kfz-Protokoll, KKH-Beitritts-
 * erklaerung, ...). Der erste Parser, der das Formular erkennt, gewinnt;
 * erkennt keiner es, liefert der Composite null und die Analyse laeuft normal
 * weiter (Heuristik/KI). So kommt ein neuer Formulartyp ohne Umbau des
 * Analyzers hinzu - nur ein weiterer Parser in der Liste (AppServiceProvider).
 */
class CompositeDocumentTemplateParser implements DocumentTemplateParser
{
    /** @param iterable<DocumentTemplateParser> $parsers */
    public function __construct(private readonly iterable $parsers)
    {
    }

    public function parse(string $text): ?array
    {
        foreach ($this->parsers as $parser) {
            $result = $parser->parse($text);
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }
}
