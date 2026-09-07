<?php

namespace App\Services\Messaging\Channels;

use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Services\Messaging\Dto\OutboundMessage;
use App\Services\Messaging\Dto\SendResult;

/**
 * Der interne Mitarbeiter-Chat als Kanal.
 *
 * Er hat bewusst KEINEN Kunden, keine externe Kennung und keinen
 * Aussenweg - und genau deshalb steht er hier: er zwingt den Kern
 * dazu, mit fehlendem `customer_id` und fehlenden externen Kennungen
 * umzugehen, statt sie stillschweigend vorauszusetzen.
 *
 * Die bestehenden Tabellen `internal_conversations` bleiben unberuehrt
 * in Betrieb; die strukturelle Trennung zum Kundenportal (Spec Teil 8)
 * bleibt damit erhalten.
 */
class InternalChatAdapter extends AbstractChannelAdapter
{
    public function key(): string
    {
        return Channel::INTERNAL;
    }

    public function send(OutboundMessage $message, ?ChannelAccount $account): SendResult
    {
        return SendResult::ok();
    }
}
