<?php

namespace App\Services\Messaging\Channels;

use App\Models\Channel;
use App\Services\Messaging\Exceptions\ChannelConfigurationException;

/**
 * Die Registrierung aller Kanaele - der EINE Ort, an dem ein
 * Kanalschluessel auf seine Umsetzung trifft.
 *
 * Weil die Zuordnung hier steht und nirgends sonst, kostet ein neuer
 * Kanal genau zwei Zeilen: die Registrierung und einen Eintrag in
 * `channels`. Kein Controller, kein Modell und kein Dienst des Kerns
 * muss dafuer angefasst werden.
 */
class ChannelManager
{
    /** @var array<string,ChannelAdapterInterface> */
    private array $adapters = [];

    public function register(ChannelAdapterInterface $adapter): void
    {
        $this->adapters[$adapter->key()] = $adapter;
    }

    public function has(string $key): bool
    {
        return isset($this->adapters[$key]);
    }

    /** @return array<int,string> */
    public function keys(): array
    {
        return array_keys($this->adapters);
    }

    public function driver(string $key): ChannelAdapterInterface
    {
        return $this->adapters[$key]
            ?? throw new ChannelConfigurationException("Kein Adapter fuer den Kanal '{$key}' registriert.");
    }

    /** Bequemer Weg vom Kanal-Datensatz zum Adapter. */
    public function for(Channel $channel): ChannelAdapterInterface
    {
        return $this->driver($channel->key);
    }
}
