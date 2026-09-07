<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Idempotenz-Register fuer eingehende Webhooks.
 *
 * JEDE Plattform stellt Webhooks mehrfach zu (Wiederholung nach
 * Zeitueberschreitung, Nachlieferung nach Stoerung). Ohne dieses
 * Register entsteht bei jeder Wiederholung eine zweite Nachricht im
 * Chat des Kunden - und der Kunde sieht sich selbst doppelt.
 */
class ChannelEvent extends Model
{
    protected $fillable = ['channel_account_id', 'external_event_id', 'kind', 'dedupe_key', 'processed_at'];
    protected $casts = ['processed_at' => 'datetime'];

    /**
     * Beansprucht ein Ereignis ATOMAR. `true` = dieser Aufruf darf es
     * verarbeiten, `false` = ein anderer war schneller (oder es lief
     * bereits).
     *
     * Die Entscheidung faellt ueber den UNIQUE-Index der Datenbank, nicht
     * ueber ein vorheriges exists() - zwischen Pruefen und Schreiben
     * passt sonst genau die zweite Zustellung, um die es hier geht.
     *
     * Der Schluessel ist bewusst EINE zusammengesetzte Spalte: ein
     * UNIQUE ueber (konto, ereignis) wuerde ein Ereignis OHNE Konto
     * beliebig oft durchlassen, weil NULL in beiden Datenbanken als
     * "immer verschieden" gilt.
     */
    public static function claim(?int $accountId, string $externalEventId, ?string $kind = null): bool
    {
        try {
            static::create([
                'channel_account_id' => $accountId,
                'external_event_id' => $externalEventId,
                'dedupe_key' => ($accountId ?? 0).':'.$externalEventId,
                'kind' => $kind,
                'processed_at' => now(),
            ]);

            return true;
        } catch (QueryException) {
            return false;
        }
    }
}
