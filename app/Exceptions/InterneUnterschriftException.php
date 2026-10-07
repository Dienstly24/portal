<?php

namespace App\Exceptions;

/**
 * Eine interne Unterschrift kam nicht zustande - mit einer Meldung, die der
 * Mitarbeiter lesen kann. `brauchtCode` sagt der Oberflaeche, dass nur der
 * Zwei-Faktor-Code fehlt (das Zeitfenster ist abgelaufen oder es gab nie
 * eins): dann fragt der Dialog den Code ab, statt eine Fehlermeldung zu
 * zeigen.
 */
class InterneUnterschriftException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $brauchtCode = false)
    {
        parent::__construct($message);
    }
}
