<?php

namespace App\Services\Messaging\Channels;

use App\Models\ChannelAccount;
use App\Services\Messaging\Dto\InboundMessage;
use App\Services\Messaging\Dto\OutboundMessage;
use App\Services\Messaging\Dto\SendResult;

/**
 * Der VERTRAG jedes Kanals. Alles Plattformabhaengige lebt hinter
 * dieser Schnittstelle: Authentifizierung, Nutzlast-Formate,
 * Fehlermeldungen, Medien-Abruf, Zustellmeldungen.
 *
 * Ein neuer Kanal bedeutet: eine Umsetzung dieser Schnittstelle plus
 * ein Eintrag in `channels`. Er bedeutet ausdruecklich KEINEN Eingriff
 * in Conversation, Message, Inbox oder Zuweisung.
 */
interface ChannelAdapterInterface
{
    /** Kanalschluessel, wie er in `channels.key` steht. */
    public function key(): string;

    /**
     * Pruefen, ob eine Webhook-Zustellung ECHT ist (Signatur, Token).
     * Wird aufgerufen, BEVOR irgendetwas gespeichert wird - eine
     * gefaelschte Nachricht darf nie in einer Kundenakte landen.
     */
    public function verifyWebhook(array $payload, array $headers, ?ChannelAccount $account): bool;

    /**
     * Rohe Webhook-Nutzlast in normalisierte Nachrichten uebersetzen.
     * Mehrere, weil Plattformen mehrere Ereignisse buendeln.
     *
     * @return array<int,InboundMessage>
     */
    public function parseInbound(array $payload, ?ChannelAccount $account): array;

    /**
     * Zustell-/Lese-/Fehlermeldungen aus derselben Nutzlast.
     *
     * @return array<int,array{external_message_id:string,status:string,reason?:string}>
     */
    public function parseStatusUpdates(array $payload, ?ChannelAccount $account): array;

    /** Nachricht senden. Fehler kommen als SendResult::failed zurueck, nicht als Ausnahme. */
    public function send(OutboundMessage $message, ?ChannelAccount $account): SendResult;

    /**
     * Eine Mediendatei beschaffen. Viele Plattformen liefern im Webhook
     * nur eine Kennung, hinter der ein zweiter, authentifizierter Abruf
     * steht - und die Kennung verfaellt.
     *
     * @return array{contents:string,mime_type:?string,file_name:?string}|null
     */
    public function fetchMedia(string $externalMediaId, ?ChannelAccount $account): ?array;

    /** Als gelesen melden - nur sinnvoll, wenn der Kanal es kann. */
    public function markAsRead(string $externalMessageId, ?ChannelAccount $account): bool;

    /**
     * Zugangsdaten erneuern. Kanaele mit Dauer-Token geben false
     * zurueck - "nicht noetig" ist kein Fehler.
     */
    public function refreshCredentials(ChannelAccount $account): bool;
}
