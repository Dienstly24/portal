<?php

namespace App\Services\Messaging\Exceptions;

/**
 * Die Plattform bremst uns aus. Der einzige Fehler, bei dem ein
 * erneuter Versuch RICHTIG ist - aber erst nach der genannten Zeit.
 *
 * `retryAfter` gehoert deshalb an die Ausnahme und nicht in eine
 * Konstante im Kern: jede Plattform hat andere Grenzen, und ein fest
 * verdrahteter WhatsApp-Wert waere fuer Telegram schlicht falsch.
 */
class ChannelRateLimitException extends MessagingException
{
    public function __construct(string $message = '', public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
