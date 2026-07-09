<?php
namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Verschiebt Bestandsdokumente von der öffentlichen Disk in den
 * privaten Storage (storage/app/private). Standard: nur Kategorie
 * 'contract' (Vertragsdokumente); mit --all alle Kategorien.
 * Mit --dry-run wird nur angezeigt, was passieren würde.
 */
class MoveDocumentsPrivate extends Command
{
    protected $signature = 'documents:move-private {--all : Alle Kategorien statt nur contract} {--dry-run : Nur anzeigen, nichts verschieben}';
    protected $description = 'Verschiebt Dokumente von der public Disk in den privaten Storage';

    public function handle(): int
    {
        $query = Document::where('disk', 'public');
        if (!$this->option('all')) {
            $query->where('category', 'contract');
        }

        $moved = $missing = 0;
        foreach ($query->get() as $doc) {
            if (!Storage::disk('public')->exists($doc->file_path)) {
                $this->warn("Fehlt auf public Disk, übersprungen: {$doc->file_path} (#{$doc->id})");
                $missing++;
                continue;
            }
            if ($this->option('dry-run')) {
                $this->line("Würde verschieben: {$doc->file_path}");
                $moved++;
                continue;
            }
            // Erst schreiben, dann DB aktualisieren, erst danach löschen -
            // bei einem Abbruch geht so keine Datei verloren.
            Storage::disk('local')->put($doc->file_path, Storage::disk('public')->get($doc->file_path));
            $doc->update(['disk' => 'local']);
            Storage::disk('public')->delete($doc->file_path);
            $moved++;
        }

        $this->info(($this->option('dry-run') ? '[DRY-RUN] ' : '') . "Verschoben: {$moved}, fehlend: {$missing}");
        return self::SUCCESS;
    }
}
