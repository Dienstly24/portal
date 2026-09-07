<?php

namespace App\Events\Messaging;

use App\Models\CustomerMessage;
use Illuminate\Foundation\Events\Dispatchable;

/** Zustell-, Lese- oder Fehlermeldung der Plattform zu einer Nachricht. */
class MessageStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly CustomerMessage $message,
        public readonly string $status,
    ) {}
}
