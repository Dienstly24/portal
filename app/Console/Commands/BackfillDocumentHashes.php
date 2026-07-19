<?php
namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;

/**
 * Berechnet die Inhalts-Bestimmung (content_hash) fuer Bestandsdokumente, die
 * vor Einfuehrung der Duplikat-Erkennung angelegt wurden. Erst danach werden
 * spaeter erneut hochgeladene, inhaltsgleiche Dateien auch gegen den Altbestand
 * als Duplikat erkannt.
 *
 * Bewusst schonend: nur fehlende Hashes, in Bloecken, streamend gelesen. Es
 * werden KEINE bestehenden Dokumente rueckwirkend als Duplikat markiert (das
 * wuerde bereits bearbeitete Vorgaenge nachtraeglich mit Warnungen ueberziehen)
 * - der Hash genuegt, damit KUENFTIGE Uploads den Altbestand treffen.
 */
class BackfillDocumentHashes extends Command
{
    protected $signature = 'documents:backfill-hashes {--chunk=200 : Dokumente pro Block} {--dry-run : Nur zaehlen, nichts schreiben}';
    protected $description = 'Berechnet fehlende Inhalts-Hashes (SHA-256) fuer Bestandsdokumente (Duplikat-Erkennung)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $done = $missing = 0;

        Document::whereNull('content_hash')
            ->whereNotNull('file_path')
            ->orderBy('created_at')
            ->chunkById($chunk, function ($documents) use (&$done, &$missing, $dry) {
                foreach ($documents as $doc) {
                    $hash = Document::hashStoredFile($doc->disk ?: 'local', $doc->file_path);
                    if ($hash === null) {
                        $missing++;
                        continue;
                    }
                    if (!$dry) {
                        // Nur die Hash-Spalte schreiben (kein Model-Event, keine
                        // rueckwirkende Duplikat-Markierung, keine verschluesselten
                        // Felder erneut speichern).
                        Document::whereKey($doc->id)->update(['content_hash' => $hash]);
                    }
                    $done++;
                }
            });

        $this->info(($dry ? '[DRY-RUN] ' : '') . "Hashes gesetzt: {$done}, Datei fehlte: {$missing}");
        return self::SUCCESS;
    }
}
