<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use Illuminate\Console\Command;

/**
 * DSGVO-Loeschfrist fuer Website-Anfragen (Arbeitsauftrag P0-1):
 * Anfragen ueber das Website-Formular (source=website), aus denen KEINE
 * Kundenbeziehung entstanden ist (customer_id leer), werden nach
 * 6 Monaten automatisch geloescht - inklusive Nachrichten, Anhaengen
 * und Verlaufs-Eintraegen. Kundengebundene Tickets bleiben unberuehrt.
 */
class PurgeWebsiteLeads extends Command
{
    protected $signature = 'tickets:purge-website-leads {--dry-run : Nur anzeigen, nichts loeschen}';

    protected $description = 'Loescht unkonvertierte Website-Anfragen aelter als 6 Monate (DSGVO-Loeschfrist)';

    public function handle(): int
    {
        $cutoff = now()->subMonths(6);

        $query = Ticket::where('source', 'website')
            ->whereNull('customer_id')
            ->where('created_at', '<', $cutoff);

        $count = $query->count();
        if ($count === 0) {
            $this->info('Keine Website-Anfragen aelter als 6 Monate ohne Kundenbezug.');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Wuerde {$count} Website-Anfrage(n) loeschen (aelter als {$cutoff->format('d.m.Y')}, ohne Kundenbezug).");
            return self::SUCCESS;
        }

        $deleted = 0;
        $query->orderBy('created_at')->chunkById(100, function ($tickets) use (&$deleted) {
            foreach ($tickets as $ticket) {
                // Anhaenge inkl. Dateien, Nachrichten und Events mitloeschen -
                // nach der Frist darf nichts von der Anfrage uebrig bleiben.
                foreach ($ticket->attachments as $attachment) {
                    try {
                        $attachment->delete();
                    } catch (\Throwable $e) {
                        \Log::warning('Website-Lead-Purge: Anhang nicht loeschbar: ' . $e->getMessage());
                    }
                }
                $ticket->messages()->delete();
                $ticket->events()->delete();
                $ticket->delete();
                $deleted++;
            }
        });

        $this->info("{$deleted} Website-Anfrage(n) aelter als 6 Monate geloescht.");
        return self::SUCCESS;
    }
}
