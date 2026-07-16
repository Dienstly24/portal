<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\SystemSetting;
use Illuminate\Console\Command;

/**
 * DSGVO-Aufbewahrung: reine Seitenaufruf-Eintraege (seite_geoeffnet)
 * enthalten IP/Geraet pro Request und werden nach Ablauf der
 * Aufbewahrungsfrist geloescht. Produktive Aktionen (Audit-Trail)
 * bleiben unangetastet.
 */
class PruneActivityNavigationLogs extends Command
{
    protected $signature = 'activity:prune';

    protected $description = 'Loescht alte Seitenaufruf-Eintraege aus dem Aktivitaetslog (Aufbewahrungsfrist)';

    public function handle(): int
    {
        $days = (int) SystemSetting::get('activity_navigation_retention_days', 90);
        $days = max(7, $days);

        $deleted = ActivityLog::where('action', 'seite_geoeffnet')
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Geloeschte Seitenaufruf-Eintraege (aelter als {$days} Tage): {$deleted}");
        return self::SUCCESS;
    }
}
