<?php

namespace App\Services\Messaging\Exceptions;

/** Die Plattform ist voruebergehend nicht erreichbar. Der klassische Fall fuer einen spaeteren Versuch. */
class ChannelUnavailableException extends MessagingException
{
}
