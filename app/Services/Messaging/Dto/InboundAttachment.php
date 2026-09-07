<?php

namespace App\Services\Messaging\Dto;

/**
 * Ein Anhang, wie ihn der Kanal meldet. Die Datei ist zu diesem
 * Zeitpunkt NOCH NICHT heruntergeladen - viele Plattformen liefern nur
 * eine Kennung, hinter der ein zweiter, authentifizierter Abruf steht.
 * Das Herunterladen gehoert deshalb in den Adapter, nicht in den Engine.
 */
final class InboundAttachment
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $externalMediaId = null,
        public readonly ?string $url = null,
        public readonly ?string $fileName = null,
        public readonly ?string $mimeType = null,
        public readonly ?int $fileSize = null,
    ) {}
}
