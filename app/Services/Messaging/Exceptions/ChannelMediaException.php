<?php

namespace App\Services\Messaging\Exceptions;

/** Eine Mediendatei liess sich nicht holen oder senden. Der Text der Nachricht kann trotzdem gueltig sein. */
class ChannelMediaException extends MessagingException
{
}
