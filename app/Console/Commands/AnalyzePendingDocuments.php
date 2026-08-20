<?php
namespace App\Console\Commands;

use App\Console\Concerns\ProcessesRecordsSafely;
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
    use ProcessesRecordsSafely;

    protected $signature = 'documents:analyze-pending';
    protected $description = 'Wartende Dokument-Analysen erneut anstossen, festgefahrene beenden';

    public function handle(): int
    {
        // pending aelter als 10 Minuten: Job ging offenbar verloren -> neu einreihen.
        $pending = Document::where('ai_status', 'pending')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->orderBy('updated_at')->limit(10)->get();
        // Je Dokument abgesichert: ein einzelnes kaputtes Dokument darf das
        // Sicherheitsnetz nicht ausser Kraft setzen - sonst bleiben alle
        // anderen haengenden Analysen fuer immer liegen.
        $wiederAngestossen = $this->verarbeiteEinzeln($pending, function (Document $document) {
            $document->touch(); // verhindert Doppel-Dispatch im naechsten Lauf
            AnalyzeDocumentJob::dispatch($document->id);
        }, 'Dokument');

        // processing aelter als 20 Minuten: festgefahren -> als Fehler markieren,
        // Mitarbeiter koennen ueber die Review-UI neu analysieren. Ein regulaerer
        // Lauf kann inkl. Retries hoechstens ~11 Min dauern (2x timeout 300s +
        // backoff), 20 Min ist also ein sicherer Abstand und surft Fehler
        // schneller auf als die frueheren 45 Min.
        $stuck = Document::where('ai_status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(20))->get();
        $intake = app(\App\Services\DocumentIntake\DocumentIntakeService::class);
        $abgebrochen = $this->verarbeiteEinzeln($stuck, function (Document $document) use ($intake) {
            $document->update([
                'ai_status' => 'failed',
                'ai_error' => 'Analyse abgebrochen (Zeitueberschreitung).',
            ]);
            // Auch der Zeitueberschreitungs-Fall bekommt jetzt einen aktiven
            // Hinweis (frueher verstummte ein festgefahrenes Dokument).
            $intake->notifyAnalysisFailed($document);
        }, 'Dokument');

        // Rueckstau-Alarm (INT-10): seit >30 Min unbearbeitete Dokumente
        // (created_at, nicht updated_at - das Re-Dispatch oben "touched" den
        // Stand und wuerde die Alterung sonst verschleiern) deuten auf einen
        // toten Queue-Worker hin. Der Scheduler (schedule:work) laeuft dann
        // zwar noch und reiht neu ein, aber niemand arbeitet die Queue ab.
        // Ein deduplizierter Glocken-Hinweis an die Verwaltung macht das
        // sichtbar, statt dass Uploads still liegen bleiben.
        // Auch der Alarm selbst laeuft abgesichert: ausgerechnet die Meldung
        // "der Worker ist tot" darf nicht daran scheitern, dass etwas anderes
        // kaputt ist.
        try {
        $threshold = (int) config('services.ocr.pending_backlog_alert', 10);
        if ($threshold > 0) {
            $backlog = Document::where('ai_status', 'pending')
                ->where('created_at', '<', now()->subMinutes(30))->count();
            if ($backlog >= $threshold) {
                $admins = \App\Models\User::whereIn('role', ['admin', 'manager'])
                    ->where('is_active', true)->pluck('id');
                \App\Support\Facades\Notify::pushMany($admins, [
                    'type' => \App\Services\Notifications\NotificationService::TYPE_DOCUMENT,
                    'title' => 'Analyse-Rueckstau: Queue-Worker pruefen',
                    'body' => $backlog . ' Dokumente warten seit ueber 30 Minuten auf ihre Analyse. '
                        . 'Vermutlich laeuft der Queue-Worker (php artisan queue:work) nicht. '
                        . 'Diagnose: php artisan queue:health',
                    'link' => route('admin.documents.inbox'),
                    // Ein Hinweis, der sich auffrischt statt zu spammen.
                    'dedup_key' => 'analyze-backlog',
                ]);
                $this->warn(sprintf('Rueckstau-Alarm: %d Dokumente seit >30 Min pending.', $backlog));
            }
        }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Rueckstau-Alarm fehlgeschlagen: ' . $e->getMessage(), ['exception' => $e]);
            $this->warn('Rueckstau-Alarm konnte nicht gesendet werden: ' . $e->getMessage());
        }

        $this->info(sprintf('%d erneut angestossen, %d als fehlgeschlagen markiert.', $wiederAngestossen, $abgebrochen));

        return $this->ergebnisMitUebersprungenen(self::SUCCESS);
    }
}
