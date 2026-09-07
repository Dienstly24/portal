<?php

namespace App\Services\Messaging\Channels;

use App\Models\ChannelAccount;
use App\Services\Messaging\Dto\ConnectionTest;

/**
 * Sinnvolle Vorgaben fuer alles, was ein Kanal NICHT kann.
 *
 * Ohne diese Basis muesste jeder neue Adapter acht Methoden schreiben,
 * von denen ihn zwei interessieren - und die sechs Pflichtantworten
 * wuerden mit der Zeit auseinanderlaufen. Ein Adapter setzt hier nur
 * das um, was seine Plattform wirklich beherrscht.
 */
abstract class AbstractChannelAdapter implements ChannelAdapterInterface
{
    public function verifyWebhook(array $payload, array $headers, ?ChannelAccount $account): bool
    {
        // Bewusst restriktiv: ein Kanal ohne eigene Pruefung nimmt keine
        // Webhooks entgegen. Durchwinken waere die gefaehrlichere Vorgabe.
        return false;
    }

    public function parseInbound(array $payload, ?ChannelAccount $account): array
    {
        return [];
    }

    public function parseStatusUpdates(array $payload, ?ChannelAccount $account): array
    {
        return [];
    }

    public function fetchMedia(string $externalMediaId, ?ChannelAccount $account): ?array
    {
        return null;
    }

    public function markAsRead(string $externalMessageId, ?ChannelAccount $account): bool
    {
        return false;
    }

    public function refreshCredentials(ChannelAccount $account): bool
    {
        return false;
    }

    /**
     * Kanaele ohne externe Plattform (Portal, interner Chat) haben
     * nichts zu testen. Sie melden das ausdruecklich - "kein Test
     * moeglich" ist eine Aussage, ein stilles "verbunden" waere eine
     * Behauptung.
     */
    public function testConnection(?ChannelAccount $account): ConnectionTest
    {
        return ConnectionTest::make(ConnectionTest::NOT_SUPPORTED);
    }
}
