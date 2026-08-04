<?php

namespace App\Console\Commands;

use App\Models\BannerSocialChannel;
use App\Services\Social\MetaInsightsService;
use Illuminate\Console\Command;

/**
 * Kennzahlen der veroeffentlichten Social-Beitraege + den Seiten-
 * Ueberblick (Follower/Seitenaufrufe) aus der Meta-API aktualisieren -
 * alle 6 Stunden. Beitraege aelter als 60 Tage werden nicht mehr
 * abgefragt (Zahlen aendern sich kaum noch, API-Aufrufe sparen).
 * Fehler einzelner Beitraege brechen den Lauf nicht ab.
 */
class RefreshSocialInsights extends Command
{
    protected $signature = 'social:refresh-insights';

    protected $description = 'Likes/Kommentare/Reichweite der Social-Posts und Seiten-Kennzahlen von Meta holen';

    public function handle(MetaInsightsService $insights): int
    {
        $channels = BannerSocialChannel::query()
            ->whereNotNull('external_post_id')
            ->where('published_at', '>=', now()->subDays(60))
            ->get();

        $ok = 0;
        foreach ($channels as $channel) {
            try {
                $insights->refreshChannel($channel);
                $ok++;
            } catch (\Throwable $e) {
                $this->warn($channel->platform . ' #' . $channel->id . ': ' . $e->getMessage());
            }
        }

        try {
            $insights->refreshPageOverview();
        } catch (\Throwable $e) {
            $this->warn('Seiten-Ueberblick: ' . $e->getMessage());
        }

        $this->info('Kennzahlen aktualisiert: ' . $ok . '/' . $channels->count() . ' Beitraege.');

        return self::SUCCESS;
    }
}
