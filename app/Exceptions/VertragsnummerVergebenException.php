<?php

namespace App\Exceptions;

/**
 * Ein Dokument nennt eine Vertragsnummer, die bereits an der Akte eines
 * ANDEREN Kunden steht (KI-135, 25.11.2026). `contracts.contract_number`
 * ist eindeutig - frueher endete die Anlage dann mit einem 500er
 * (UniqueConstraintViolation), im Stapel-Weg sogar NACH dem Anlegen des
 * Kunden. Es wird nichts geraten: kein zweiter Vertrag, keine Nummer
 * weglassen, kein Umhaengen des fremden Vertrags. Die Meldung ist fuer
 * Mitarbeiter geschrieben und nennt den anderen Kunden bewusst NICHT
 * (er kann ausserhalb des eigenen Portfolios liegen).
 */
class VertragsnummerVergebenException extends \DomainException
{
    public static function fuer(string $nummer): self
    {
        return new self(
            'Vertrag nicht angelegt: die Vertragsnummer '.$nummer.' ist bereits an einer anderen '
            .'Kundenakte erfasst. Bitte prüfen, ob das Dokument zum richtigen Kunden gehört.'
        );
    }
}
