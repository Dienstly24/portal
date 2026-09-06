<?php

namespace App\Events\Messaging;

use App\Models\Conversation;
use Illuminate\Foundation\Events\Dispatchable;

/** Eine Unterhaltung ist neu entstanden. */
class ConversationCreated
{
    use Dispatchable;

    public function __construct(public readonly Conversation $conversation) {}
}
