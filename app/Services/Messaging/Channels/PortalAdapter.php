<?php

namespace App\Services\Messaging\Channels;

use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Services\Messaging\Dto\OutboundMessage;
use App\Services\Messaging\Dto\SendResult;

/**
 * Der Kundenportal-Chat als Kanal.
 *
 * Er hat keine externe Plattform: die Nachricht IST bereits im System,
 * sobald sie gespeichert ist, und der Kunde sieht sie beim naechsten
 * Aufruf. Der Adapter existiert trotzdem - und zwar als Beweis, dass
 * die Abstraktion traegt, bevor der erste echte externe Kanal
 * angebunden wird. Ein Kanal, der nichts zu tun hat, ist der beste
 * Test dafuer, dass der Kern nichts Plattformabhaengiges voraussetzt.
 */
class PortalAdapter extends AbstractChannelAdapter
{
    public function key(): string
    {
        return Channel::PORTAL;
    }

    public function send(OutboundMessage $message, ?ChannelAccount $account): SendResult
    {
        // Kein Aussenweg: die Zustellung ist das Speichern selbst. Die
        // Begleit-E-Mail bleibt bewusst beim CustomerMessageNotifier -
        // sie ist eine Benachrichtigung ueber die Nachricht, nicht der
        // Zustellweg der Nachricht.
        return SendResult::ok();
    }

    public function markAsRead(string $externalMessageId, ?ChannelAccount $account): bool
    {
        // Gelesen wird im Portal ueber `read_at` gefuehrt; der Kanal
        // selbst hat dafuer keinen eigenen Weg nach aussen.
        return true;
    }
}
