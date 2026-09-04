<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnose des Queue-Betriebs (INT-10). Bisher fiel ein toter Queue-Worker
 * nur dadurch auf, dass Dokument-Analysen und E-Mails still liegen blieben -
 * es gab keine Sichtbarkeit. Dieser Befehl fasst den Zustand zusammen:
 *
 *  - Anzahl fehlgeschlagener Jobs (failed_jobs)
 *  - Dokument-Analyse-Rueckstau: pending/processing gesamt und wie lange das
 *    aelteste schon wartet
 *
 * Fuer den Betreiber (manuell) und fuer externe Ueberwachung (Exitcode 1 =
 * handlungsbeduerftig) gedacht - analog zu `ocr:check`. Nur lesend.
 */
class QueueHealth extends Command
{
    protected $signature = 'queue:health';
    protected $description = 'Queue-Zustand pruefen: fehlgeschlagene Jobs und Analyse-Rueckstau';

    public function handle(): int
    {
        $healthy = true;

        // 1) Fehlgeschlagene Jobs (nur, wenn die Tabelle existiert).
        $failed = 0;
        if (Schema::hasTable('failed_jobs')) {
            $failed = (int) DB::table('failed_jobs')->count();
        }
        if ($failed > 0) {
            $this->error('✗ '.$failed.' fehlgeschlagene Job(s) in failed_jobs. Erneut versuchen: php artisan queue:retry all');
            $healthy = false;
        } else {
            $this->info('✓ Keine fehlgeschlagenen Jobs.');
        }

        // 2) Dokument-Analyse-Rueckstau.
        $pending = Document::where('ai_status', 'pending')->count();
        $processing = Document::where('ai_status', 'processing')->count();
        $oldestPending = Document::where('ai_status', 'pending')->min('created_at');

        $this->line('Analyse-Status: '.$pending.' pending, '.$processing.' processing.');

        $threshold = max(1, (int) config('services.ocr.pending_backlog_alert', 10));
        $staleBacklog = Document::where('ai_status', 'pending')
            ->where('created_at', '<', now()->subMinutes(30))->count();

        if ($staleBacklog >= $threshold) {
            $wartet = $oldestPending
                ? Carbon::parse($oldestPending)->diffForHumans(now(), true)
                : 'unbekannt';
            $this->error('✗ Rueckstau: '.$staleBacklog.' Dokument(e) warten seit >30 Min (aeltestes seit '.$wartet.').');
            $this->error('  Vermutlich laeuft der Queue-Worker nicht: php artisan queue:work (siehe docs/DEPLOYMENT.md).');
            $healthy = false;
        } else {
            $this->info('✓ Kein nennenswerter Analyse-Rueckstau.');
        }

        $this->newLine();
        if ($healthy) {
            $this->info('Queue-Betrieb unauffaellig.');
            return self::SUCCESS;
        }
        $this->error('Queue-Betrieb handlungsbeduerftig - siehe oben.');
        return self::FAILURE;
    }
}
