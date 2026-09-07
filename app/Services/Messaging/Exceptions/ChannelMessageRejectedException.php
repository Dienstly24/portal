<?php

namespace App\Services\Messaging\Exceptions;

/** Die Nachricht selbst wurde abgelehnt (Format, Laenge, Empfaenger, Zeitfenster). Wiederholen wuerde dieselbe Ablehnung erzeugen. */
class ChannelMessageRejectedException extends MessagingException
{
}
