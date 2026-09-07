<?php

namespace App\Events\Messaging;

use App\Models\Conversation;
use App\Models\CustomerMessage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Eine eingegangene Nachricht wurde gespeichert.
 *
 * ZWECK DER EREIGNISSE: Glocke, KI-Assistent und Inbox haengen sich
 * hier an, statt dass der Engine sie kennt. Sonst muesste jede neue
 * Reaktion auf eine Nachricht im Kern nachgetragen werden - und der
 * Kern waechst mit jedem Anbau.
 */
class InboundMessageReceived
{
    use Dispatchable;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly CustomerMessage $message,
    ) {}
}
