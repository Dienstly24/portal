<?php

namespace App\Jobs;

use App\Services\Import\CustomerCsvImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Importiert eine bereits als Vorschau geprüfte CSV-Datei im Hintergrund.
 *
 * Grund: Eine grosse Datei (z. B. > 1000 Kunden) kann im Web-Request nicht in
 * einem Rutsch angelegt werden - pro Zeile laufen Matching + Transaktion +
 * Nummernvergabe, das ueberschreitet die PHP-/Webserver-Zeitgrenze und der
 * Import bricht mittendrin ab. Als Queue-Job laeuft der Import ohne
 * HTTP-Timeout durch; der Betreiber wird per interner Benachrichtigung
 * informiert, sobald er fertig ist.
 */
class ImportCustomersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Grosse Importe brauchen Zeit - Timeout entsprechend grosszuegig. */
    public int $timeout = 1800;

    /** Kein automatischer Neuversuch: ein Teilimport soll nicht doppelt laufen. */
    public int $tries = 1;

    public function __construct(
        public readonly string $path,
        public readonly ?int $actorId = null,
    ) {
    }

    public function handle(CustomerCsvImporter $importer): void
    {
        if (!is_file($this->path)) {
            Log::warning('ImportCustomersJob: Datei nicht gefunden', ['path' => $this->path]);

            return;
        }

        try {
            $result = $importer->commit($this->path, $this->actorId);
        } finally {
            // Rohdaten nach dem Import nicht aufbewahren (Datenminimierung).
            @unlink($this->path);
        }

        if ($this->actorId) {
            $body = "{$result['imported']} Kunden importiert, {$result['skipped']} uebersprungen.";
            if (!empty($result['errors'])) {
                // Nur wenige, gekuerzte Hinweise einbetten und Platz fuer den
                // "weitere"-Zaehler reservieren: die Notification-Spalte ist
                // string(500), ein Import mit vielen Warnungen (z.B. hunderte
                // Duplikate) wuerde sie sonst sprengen und den Job trotz
                // erfolgreichem Import als fehlgeschlagen markieren.
                $shown = array_map(
                    fn ($e) => mb_substr((string) $e, 0, 80),
                    array_slice($result['errors'], 0, 3)
                );
                $body .= ' Hinweise: ' . implode(' | ', $shown);
                $more = count($result['errors']) - count($shown);
                if ($more > 0) {
                    $body .= ' | ... und ' . $more . ' weitere.';
                }
            }

            \App\Support\Facades\Notify::push($this->actorId, [
                'type'  => \App\Services\Notifications\NotificationService::TYPE_IMPORT,
                'title' => 'Kunden-Import abgeschlossen',
                'body'  => $body,
                'link'  => route('admin.import_export'),
            ]);
        }
    }
}
