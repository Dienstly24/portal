<?php

namespace App\Console\Commands;

use App\Models\ServicePage;
use Illuminate\Console\Command;

/**
 * P0-6: Normalisiert historisch absolut gespeicherte Bildpfade der
 * Leistungsseiten (z. B. "http://187.127.70.161/storage/service-pages/x.png")
 * auf Disk-relative Pfade ("service-pages/x.png"). Die Anzeige nutzt
 * seither ServicePage::imageUrl() und rendert immer relative /storage/-URLs;
 * dieser Befehl raeumt zusaetzlich die Datenbank auf.
 * WICHTIG zusaetzlich auf dem Server: APP_URL=https://www.dienstly24.de
 * in der .env setzen (Ursache der IP-Links).
 */
class FixStorageUrls extends Command
{
    protected $signature = 'website:fix-storage-urls {--write : Aenderungen speichern (sonst nur Vorschau)}';

    protected $description = 'Repariert absolut gespeicherte Storage-URLs (IP-/Host-Basen) in den Leistungsseiten';

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        $fixed = 0;

        foreach (ServicePage::whereNotNull('image_path')->get() as $page) {
            $old = trim((string) $page->image_path);
            if ($old === '') {
                continue;
            }
            $new = $old;
            if (preg_match('#^https?://[^/]+/storage/(.+)$#i', $new, $m)) {
                $new = $m[1];
            }
            $new = ltrim(preg_replace('#^/storage/#', '', $new), '/');

            if ($new === $old) {
                continue;
            }
            $fixed++;
            $this->line(($write ? 'Repariert' : 'Wuerde reparieren') . ': ' . $page->slug . ': "' . $old . '" -> "' . $new . '"');
            if ($write) {
                $page->forceFill(['image_path' => $new])->save();
            }
        }

        if ($fixed === 0) {
            $this->info('Alle Bildpfade sind bereits relativ.');
        } elseif (! $write) {
            $this->warn('Vorschau - nichts gespeichert. Mit --write anwenden.');
        }

        return self::SUCCESS;
    }
}
