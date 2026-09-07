<?php

namespace App\Services\Messaging\Exceptions;

/** Etwas fehlt in der Einrichtung (Konto, Adapter, Kennung). Kein Netzproblem - hier hilft nur ein Mensch. */
class ChannelConfigurationException extends MessagingException
{
}
