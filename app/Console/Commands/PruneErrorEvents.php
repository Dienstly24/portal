<?php

namespace App\Console\Commands;

use App\Models\ErrorEvent;
use Illuminate\Console\Command;

/**
 * Alte Fehlereintraege aufraeumen.
 *
 * Geloescht wird nur, was ERLEDIGT und laenger nicht mehr aufgetreten ist -
 * ein offener Fehler bleibt stehen, egal wie alt er ist. Ein Problem
 * verschwindet nicht dadurch, dass man es lange genug ignoriert.
 */
class PruneErrorEvents extends Command
{
    protected $signature = 'errors:prune {--tage=30 : Aufbewahrung erledigter Fehler in Tagen}';
    protected $description = 'Erledigte Fehlereintraege nach Ablauf der Aufbewahrung loeschen';

    public function handle(): int
    {
        $tage = max(1, (int) $this->option('tage'));
        $grenze = now()->subDays($tage);

        $geloescht = ErrorEvent::whereNotNull('resolved_at')
            ->where('last_seen_at', '<', $grenze)
            ->delete();

        $this->info($geloescht . ' erledigte Fehlereintraege aelter als ' . $tage . ' Tage geloescht.');

        return self::SUCCESS;
    }
}
