<?php

namespace App\Services\Messaging\Exceptions;

/** Zugang abgelehnt (falscher, abgelaufener oder entzogener Schluessel). Ein erneuter Versuch mit denselben Daten hilft nie - der Zugang muss erneuert werden. */
class ChannelAuthenticationException extends MessagingException
{
}
