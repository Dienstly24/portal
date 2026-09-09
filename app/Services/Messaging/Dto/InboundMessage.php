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
        /**
         * Die Nachricht stammt von UNSERER Seite - wir werden nur
         * darueber unterrichtet.
         *
         * Der Fall entsteht, sobald dieselbe Kennung auf zwei Wegen
         * bedient wird: jemand tippt in der App der Plattform, und die
         * Plattform meldet uns die Nachricht ueber denselben Webhook wie
         * eine Kundennachricht. Ohne diese Unterscheidung waere die
         * eigene Antwort eine Kundenfrage - mit Ungelesen-Zaehler, mit
         * Zuweisung und mit einer KI-Antwort darauf. Die KI antwortet
         * dann auf sich selbst, und das Ergebnis erreicht den Kunden.
         *
         * Bewusst allgemein benannt: das ist keine Eigenheit einer
         * bestimmten Plattform, sondern die Folge davon, dass ein
         * Postfach zwei Bedienwege hat.
         */
        public readonly bool $fromBusiness = false,
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
