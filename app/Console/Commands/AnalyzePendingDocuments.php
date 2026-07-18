<?php
namespace App\Console\Commands;

use App\Jobs\AnalyzeDocumentJob;
use App\Models\Document;
use Illuminate\Console\Command;

/**
 * Sicherheitsnetz fuer die Dokument-Analyse: stoesst haengengebliebene
 * Analysen erneut an (z.B. wenn der Queue-Worker beim Upload nicht lief)
 * und beendet festgefahrene Laeufe. Laeuft alle 10 Minuten im Scheduler.
 */
class AnalyzePendingDocuments extends Command
{
    protected $signature = 'documents:analyze-pending';
    protected $description = 'Wartende Dokument-Analysen erneut anstossen, festgefahrene beenden';

    public function handle(): int
    {
        // pending aelter als 10 Minuten: Job ging offenbar verloren -> neu einreihen.
        $pending = Document::where('ai_status', 'pending')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->orderBy('updated_at')->limit(10)->get();
        foreach ($pending as $document) {
            $document->touch(); // verhindert Doppel-Dispatch im naechsten Lauf
            AnalyzeDocumentJob::dispatch($document->id);
        }

        // processing aelter als 20 Minuten: festgefahren -> als Fehler markieren,
        // Mitarbeiter koennen ueber die Review-UI neu analysieren. Ein regulaerer
        // Lauf kann inkl. Retries hoechstens ~11 Min dauern (2x timeout 300s +
        // backoff), 20 Min ist also ein sicherer Abstand und surft Fehler
        // schneller auf als die frueheren 45 Min.
        $stuck = Document::where('ai_status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(20))->get();
        foreach ($stuck as $document) {
            $document->update([
                'ai_status' => 'failed',
                'ai_error' => 'Analyse abgebrochen (Zeitueberschreitung).',
            ]);
        }

        $this->info(sprintf('%d erneut angestossen, %d als fehlgeschlagen markiert.', $pending->count(), $stuck->count()));
        return self::SUCCESS;
    }
}
