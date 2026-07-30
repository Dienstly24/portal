<?php

namespace App\Console\Commands;

use App\Models\MediaAsset;
use App\Services\Media\ImageVariantGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Medien-Papierkorb (P1-1f): Geloeschte Bilder bleiben 30 Tage
 * wiederherstellbar, danach werden Dateien (Varianten + Original)
 * und Datensatz endgueltig entfernt.
 */
class PurgeMediaTrash extends Command
{
    protected $signature = 'media:purge-trash {--dry-run : Nur anzeigen, nichts loeschen}';

    protected $description = 'Loescht Bilder endgueltig, die laenger als 30 Tage im Papierkorb liegen';

    public function handle(ImageVariantGenerator $generator): int
    {
        $days = (int) config('website.media.trash_days', 30);
        $assets = MediaAsset::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays($days))
            ->get();

        if ($assets->isEmpty()) {
            $this->info('Keine Bilder aelter als ' . $days . ' Tage im Papierkorb.');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('Wuerde ' . $assets->count() . ' Bild(er) endgueltig loeschen.');
            return self::SUCCESS;
        }

        foreach ($assets as $asset) {
            $generator->deleteVariants($asset);
            if ($asset->original_path) {
                Storage::disk('local')->delete($asset->original_path);
                Storage::disk('local')->deleteDirectory(dirname($asset->original_path));
            }
            $asset->forceDelete();
        }

        $this->info($assets->count() . ' Bild(er) endgueltig geloescht.');
        return self::SUCCESS;
    }
}
