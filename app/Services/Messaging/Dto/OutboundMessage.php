<?php

namespace App\Services\Messaging\Dto;

/**
 * Eine AUSGEHENDE Nachricht, wie der Engine sie dem Adapter uebergibt.
 * Der Adapter uebersetzt sie in das Format seiner Plattform - der Engine
 * kennt dieses Format nicht.
 */
final class OutboundMessage
{
    /** @param array<int,array<string,mixed>> $attachments */
    public function __construct(
        public readonly string $recipientId,
        public readonly ?string $text = null,
        public readonly string $type = 'text',
        public readonly array $attachments = [],
        public readonly ?string $replyToExternalId = null,
        public readonly array $metadata = [],
        /**
         * Wann der Kunde zuletzt geschrieben hat - null, wenn nie.
         *
         * Der Kern reicht nur diese TATSACHE weiter. Ob daraus eine
         * Einschraenkung folgt, entscheidet der Adapter: manche
         * Plattformen erlauben freie Nachrichten nur in einem Fenster
         * nach der letzten Kundennachricht. Diese Frist gehoert zur
         * Plattform, nicht in den Kern.
         */
        public readonly ?\DateTimeInterface $lastInboundAt = null,
    ) {}
}
