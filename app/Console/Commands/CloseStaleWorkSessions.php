<?php

namespace App\Console\Commands;

use App\Services\Activity\ActivityTracker;
use Illuminate\Console\Command;

/**
 * Beendet verwaiste Arbeitssitzungen (Browser zu, Session abgelaufen,
 * kein Logout). Als Sitzungsende gilt der letzte gesehene Request,
 * damit stille Zeit nicht als Anmeldezeit zaehlt.
 */
class CloseStaleWorkSessions extends Command
{
    protected $signature = 'activity:close-stale';

    protected $description = 'Beendet Arbeitssitzungen ohne Aktivitaet (Timeout)';

    public function handle(ActivityTracker $tracker): int
    {
        $closed = $tracker->closeStaleSessions();
        $this->info("Geschlossene Sitzungen: {$closed}");
        return self::SUCCESS;
    }
}
