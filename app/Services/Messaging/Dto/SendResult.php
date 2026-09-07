<?php

namespace App\Services\Messaging\Dto;

/**
 * Ergebnis eines Sendeversuchs.
 *
 * `externalMessageId` ist der wichtigste Wert: nur mit ihm lassen sich
 * spaetere Zustell- und Lesemeldungen der Plattform der richtigen
 * Nachricht zuordnen. Ein Kanal ohne eigene Kennung (interner Chat,
 * Portal) liefert hier schlicht null.
 */
final class SendResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $externalMessageId = null,
        public readonly ?string $error = null,
        public readonly array $metadata = [],
    ) {}

    public static function ok(?string $externalMessageId = null, array $metadata = []): self
    {
        return new self(true, $externalMessageId, null, $metadata);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }
}
