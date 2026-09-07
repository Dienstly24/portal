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
    ) {}
}
