<?php

namespace App\Exceptions;

/**
 * Das Unterschreiben ist an einer technischen Huerde gescheitert (Platte
 * voll, Datenbank weg, Bild unlesbar).
 *
 * Warum eine EIGENE Ausnahme: der Unterzeichner ist ein Fremder ohne Konto,
 * mitten in einem Vorgang, den er nicht wiederholen kann, wenn er ihn nicht
 * versteht. Ein roher HTTP 500 ist fuer ihn eine Sackgasse - er weiss nicht,
 * ob seine Unterschrift angekommen ist, und der Betrieb erfaehrt es nur aus
 * der Logdatei. Diese Ausnahme traegt deshalb ZWEI Texte: die technische
 * Ursache fuer Log und Protokoll, und einen Satz, den man dem Unterzeichner
 * zeigen kann.
 */
class SignatureSigningException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $userMessage = 'Ihre Unterschrift konnte gerade nicht gespeichert werden. Bitte versuchen Sie es in einem Moment erneut.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Der Satz fuer den Unterzeichner - nie eine technische Meldung. */
    public function userMessage(): string
    {
        return $this->userMessage;
    }
}
