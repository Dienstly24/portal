<?php

namespace App\Events\Messaging;

use App\Models\Conversation;
use Illuminate\Foundation\Events\Dispatchable;

/** Die Zustaendigkeit einer Unterhaltung hat gewechselt. */
class ConversationAssigned
{
    use Dispatchable;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly ?int $fromEmployeeId,
        public readonly ?int $toEmployeeId,
        public readonly string $action,
    ) {}
}
