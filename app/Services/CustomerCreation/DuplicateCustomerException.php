<?php
namespace App\Services\CustomerCreation;

use App\Services\Matching\MatchResult;

/**
 * Wird geworfen, wenn CustomerAutoCreationService trotz "kein Match"-
 * Annahme des Aufrufers doch noch einen Kandidaten findet
 * (Duplikatsschutz, Architekturplan Abschnitt 6). Der Aufrufer muss
 * dann auf den Bestätigungs-/Matching-Workflow zurückfallen statt
 * blind einen neuen Kunden anzulegen.
 */
class DuplicateCustomerException extends \RuntimeException
{
    public function __construct(public readonly MatchResult $matchResult)
    {
        parent::__construct(sprintf(
            'Automatische Kundenanlage abgebrochen: es existiert bereits ein Kandidat mit Score %d (%s).',
            $matchResult->score,
            $matchResult->tier()
        ));
    }
}
