<?php

namespace App\Console\Commands;

use App\Models\TicketAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Verschiebt Bestands-Ticketanhaenge von der oeffentlichen Disk in den
 * privaten Storage (analog documents:move-private). Neue Uploads werden
 * bereits privat gespeichert; dieser Befehl raeumt Altbestand auf und
 * ist idempotent - er kann bei jedem Deploy mitlaufen.
 */
class MoveTicketAttachmentsPrivate extends Command
{
    protected $signature = 'tickets:attachments-private {--dry-run : Nur anzeigen, nichts verschieben}';
    protected $description = 'Verschiebt Ticketanhaenge von der public Disk in den privaten Storage';

    public function handle(): int
    {
        $query = TicketAttachment::where(fn ($q) => $q->where('disk', 'public')->orWhereNull('disk'));

        $moved = $missing = 0;
        foreach ($query->get() as $att) {
            if (!Storage::disk('public')->exists($att->file_path)) {
                $this->warn("Fehlt auf public Disk, übersprungen: {$att->file_path} ({$att->id})");
                $missing++;
                continue;
            }
            if ($this->option('dry-run')) {
                $this->line("Würde verschieben: {$att->file_path}");
                $moved++;
                continue;
            }
            // Erst schreiben, dann DB aktualisieren, erst danach löschen -
            // bei einem Abbruch geht so keine Datei verloren.
            Storage::disk('local')->put($att->file_path, Storage::disk('public')->get($att->file_path));
            $att->update(['disk' => 'local']);
            Storage::disk('public')->delete($att->file_path);
            $moved++;
        }

        $this->info(($this->option('dry-run') ? '[DRY-RUN] ' : '') . "Verschoben: {$moved}, fehlend: {$missing}");
        return self::SUCCESS;
    }
}
