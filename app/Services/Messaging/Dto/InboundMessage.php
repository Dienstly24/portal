<?php

namespace App\Services\Messaging\Dto;

/**
 * Eine EINGEHENDE Nachricht, nachdem der Adapter sie normalisiert hat.
 *
 * Ab hier gibt es keine Plattform mehr. Was WhatsApp "wa_id" und
 * Instagram "sender.id" nennt, heisst hier beides `externalUserId` -
 * genau das ist der Zweck: der Conversation Engine sieht nur noch
 * dieses Objekt und kann deshalb keine kanalabhaengige Bedingung
 * enthalten.
 */
final class InboundMessage
{
    /**
     * @param array<int,InboundAttachment> $attachments
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public readonly string $externalUserId,
        public readonly ?string $externalMessageId = null,
        public readonly ?string $externalConversationId = null,
        public readonly ?string $text = null,
        public readonly string $type = 'text',
        public readonly array $attachments = [],
        public readonly ?string $senderName = null,
        public readonly ?string $senderPhone = null,
        public readonly ?string $senderEmail = null,
        public readonly ?\DateTimeInterface $sentAt = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * Ohne Text und ohne Anhang gibt es nichts anzuzeigen. Solche
     * Ereignisse (reine Statusmeldungen, nicht unterstuetzte Typen)
     * werden protokolliert, aber nicht als Nachricht gespeichert - eine
     * leere Blase im Kundenchat waere fuer den Mitarbeiter ein Raetsel.
     */
    public function isEmpty(): bool
    {
        return trim((string) $this->text) === '' && $this->attachments === [];
    }
}
