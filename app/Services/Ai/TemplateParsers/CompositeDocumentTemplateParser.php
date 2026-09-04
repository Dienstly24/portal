<?php

namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Contracts\DocumentTemplateParser;
use Illuminate\Support\Facades\Log;

/**
 * Buendelt mehrere Vorlagen-Parser (CHECK24-Kfz-Protokoll, KKH-Beitritts-
 * erklaerung, ...). Der erste Parser, der das Formular erkennt, gewinnt;
 * erkennt keiner es, liefert der Composite null und die Analyse laeuft normal
 * weiter (Heuristik/KI). So kommt ein neuer Formulartyp ohne Umbau des
 * Analyzers hinzu - nur ein weiterer Parser in der Liste (AppServiceProvider).
 */
class CompositeDocumentTemplateParser implements DocumentTemplateParser
{
    /** @param array<DocumentTemplateParser> $parsers */
    public function __construct(private readonly array $parsers)
    {
    }

    public function parse(string $text): ?array
    {
        foreach ($this->parsers as $parser) {
            // Ein einzelner fehlerhafter Parser darf NICHT die gesamte
            // (kostenlose) Analyse eines JEDEN Dokuments zum Absturz bringen -
            // alle Parser laufen auf jedem Text, und ein Wurf hier wuerde das
            // Dokument auf 'failed' setzen und sogar die KI-Eskalation
            // verhindern. Deshalb jeden Parser isolieren: bei einem Fehler
            // protokollieren und mit dem naechsten Parser weitermachen.
            try {
                $result = $parser->parse($text);
            } catch (\Throwable $e) {
                Log::warning('Vorlagen-Parser '.$parser::class.' fehlgeschlagen: '.$e->getMessage());
                continue;
            }
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }
}
