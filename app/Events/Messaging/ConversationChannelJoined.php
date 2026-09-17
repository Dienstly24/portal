<?php

namespace App\Events\Messaging;

use App\Models\Channel;
use App\Models\Conversation;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Ein WEITERER Kanal ist zu einer bestehenden Unterhaltung
 * dazugekommen (Phase 2).
 *
 * Als eigenes Ereignis und nicht als Sonderfall von
 * `ConversationCreated`: hier entsteht nichts Neues, hier waechst etwas
 * Bestehendes. Wer auf "neue Unterhaltung" hoert, will ueber eine
 * Zusammenfuehrung gerade NICHT benachrichtigt werden - sonst meldet
 * die Glocke einen neuen Vorgang, den es nicht gibt.
 */
class ConversationChannelJoined
{
    use Dispatchable;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly Channel $channel,
    ) {}
}
