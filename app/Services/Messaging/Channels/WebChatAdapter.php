<?php

namespace App\Services\Messaging\Channels;

use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Services\Messaging\Dto\OutboundMessage;
use App\Services\Messaging\Dto\SendResult;

/**
 * Gast-Chat auf `/chat` - ohne Portal-Anmeldung (Kundenportal gesperrt,
 * Betreiber-Vorgabe 20.11.2026).
 *
 * Wie der Portal-Kanal hat er keinen Weg nach aussen: die Zustellung IST
 * das Speichern, der Besucher liest die Antwort ueber seine Sitzung
 * (WebChatController::verlauf). Die Gegenstelle ist eine zufaellige
 * Kennung aus der Server-Sitzung ("web:<uuid>") - nie eine Rufnummer oder
 * E-Mail-Adresse, damit der Kundenabgleich eine getippte, ungepruefte
 * Angabe nie fuer einen Kunden haelt.
 */
class WebChatAdapter extends AbstractChannelAdapter
{
    public function key(): string
    {
        return Channel::WEBCHAT;
    }

    public function send(OutboundMessage $message, ?ChannelAccount $account): SendResult
    {
        return SendResult::ok();
    }

    public function markAsRead(string $externalMessageId, ?ChannelAccount $account): bool
    {
        return true;
    }
}
