<?php

namespace App\Jobs;

use App\Services\Import\CustomerCsvImporter;
use App\Services\Notifications\NotificationService;
use App\Support\Facades\Notify;
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

    /**
     * Eigene Verbindung fuer LANGE Laeufe (Audit 15.09.2026).
     *
     * `database` hat retry_after = 360 - ein Import mit $timeout = 1800
     * wurde danach ein zweites Mal aus der Warteschlange geholt und
     * (wegen tries = 1) als FEHLGESCHLAGEN eingetragen, waehrend der
     * erste Lauf noch sauber arbeitete. `database-lang` hat ein
     * retry_after, das ueber dem Zeitlimit liegt.
     *
     * Auf dem Server braucht diese Schlange einen eigenen Worker
     * (docs/DEPLOYMENT.md). Fehlt er, bleibt der Import LIEGEN - er geht
     * nicht verloren und laeuft nicht doppelt.
     */
    /**
     * Verbindungen fuer lange Laeufe, je Treiber (siehe config/queue.php).
     *
     * Beide noetig: ein Umzug auf Redis (docs/ANLEITUNG_REDIS_AR.md)
     * wuerde den Import sonst auf eine Verbindung mit retry_after = 90
     * Sekunden legen - bei einem Zeitlimit von 1800 waere das derselbe
     * Fehler wie zuvor, nur zwanzigmal schaerfer.
     */
    public const VERBINDUNGEN = [
        'database' => 'database-lang',
        'redis' => 'redis-lang',
    ];

    /** Grosse Importe brauchen Zeit - Timeout entsprechend grosszuegig. */
    public int $timeout = 1800;

    /** Kein automatischer Neuversuch: ein Teilimport soll nicht doppelt laufen. */
    public int $tries = 1;

    public function __construct(
        public readonly string $path,
        public readonly ?int $actorId = null,
    ) {
        // Verbindung im KONSTRUKTOR statt als Eigenschaft: das Trait
        // Queueable deklariert $connection/$queue bereits, und eine
        // Neudeklaration mit eigenem Vorgabewert ist in PHP ein FATALER
        // Fehler beim Laden der Klasse. Der Waechter-Test QueueTimeoutTest
        // hat genau das sofort aufgedeckt.
        //
        // NUR bei einer echten Datenbank-Warteschlange umlenken. Laeuft
        // die Anwendung auf `sync` (Testsuite, und jede Installation
        // ohne Worker), wird der Import SOFORT ausgefuehrt - dort gibt
        // es kein retry_after und damit auch das Problem nicht. Ohne
        // diese Bedingung waere der Import auf `sync` still in einer
        // Warteschlange gelandet, die niemand abarbeitet: er haette
        // schlicht nicht mehr stattgefunden. Die Testsuite hat genau
        // das sofort gemeldet (5 fehlgeschlagene Importtests).
        // NUR bei einer echten Warteschlange umlenken. Laeuft die
        // Anwendung auf `sync` (Testsuite, und jede Installation ohne
        // Worker), wird der Import SOFORT ausgefuehrt - dort gibt es kein
        // retry_after und damit auch das Problem nicht. Ohne diese
        // Bedingung waere der Import auf `sync` still in einer
        // Warteschlange gelandet, die niemand abarbeitet: er haette
        // schlicht nicht mehr stattgefunden. Die Testsuite hat genau das
        // sofort gemeldet (5 fehlgeschlagene Importtests).
        $ziel = self::VERBINDUNGEN[$this->treiberDerVorgabe()] ?? null;
        if ($ziel !== null) {
            $this->onConnection($ziel)->onQueue('lang');
        }
    }

    /** Treiber der voreingestellten Warteschlangen-Verbindung. */
    private function treiberDerVorgabe(): ?string
    {
        $vorgabe = config('queue.default');

        return $vorgabe ? config('queue.connections.'.$vorgabe.'.driver') : null;
    }

    public function handle(CustomerCsvImporter $importer): void
    {
        if (! is_file($this->path)) {
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
            if (! empty($result['errors'])) {
                // Nur wenige, gekuerzte Hinweise einbetten und Platz fuer den
                // "weitere"-Zaehler reservieren: die Notification-Spalte ist
                // string(500), ein Import mit vielen Warnungen (z.B. hunderte
                // Duplikate) wuerde sie sonst sprengen und den Job trotz
                // erfolgreichem Import als fehlgeschlagen markieren.
                $shown = array_map(
                    fn ($e) => mb_substr((string) $e, 0, 80),
                    array_slice($result['errors'], 0, 3)
                );
                $body .= ' Hinweise: '.implode(' | ', $shown);
                $more = count($result['errors']) - count($shown);
                if ($more > 0) {
                    $body .= ' | ... und '.$more.' weitere.';
                }
            }

            Notify::push($this->actorId, [
                'type' => NotificationService::TYPE_IMPORT,
                'title' => 'Kunden-Import abgeschlossen',
                'body' => $body,
                'link' => route('admin.import_export'),
            ]);
        }
    }

    /**
     * Scheitert der Import, MUSS der Betreiber davon erfahren
     * (Audit 18.08.2026 - stiller Fehlschlag).
     *
     * Vorher endete ein abgebrochener Import so: die hochgeladene CSV war
     * geloescht (das `finally` oben raeumt sie bewusst weg), es kam KEINE
     * Benachrichtigung, und in der Oberflaeche sah alles aus wie immer.
     * Der Betreiber wartete auf Kunden, die nie ankamen. Jetzt gibt es
     * eine ehrliche Meldung samt Hinweis, dass die Datei erneut
     * hochgeladen werden muss.
     */
    public function failed(?\Throwable $e): void
    {
        Log::error('Kunden-Import fehlgeschlagen', [
            'path' => $this->path,
            'fehler' => $e?->getMessage() ?? 'unbekannt',
        ]);

        // Rohdaten auch im Fehlerfall nicht liegen lassen (Datenminimierung).
        if (is_file($this->path)) {
            @unlink($this->path);
        }

        if (! $this->actorId) {
            return;
        }

        try {
            Notify::push($this->actorId, [
                'type' => NotificationService::TYPE_IMPORT,
                'title' => 'Kunden-Import FEHLGESCHLAGEN',
                'body' => 'Der Import wurde abgebrochen und ist NICHT vollstaendig durchgelaufen. '
                    .'Moeglicherweise wurde ein Teil der Kunden bereits angelegt - bitte pruefen Sie '
                    .'die Kundenliste, bevor Sie die Datei erneut hochladen. Grund: '
                    .mb_substr((string) ($e?->getMessage() ?? 'unbekannt'), 0, 200),
                'link' => route('admin.import_export'),
                'dedup_key' => 'import_failed:'.md5($this->path),
            ]);
        } catch (\Throwable $inner) {
            Log::warning('Hinweis zum fehlgeschlagenen Import konnte nicht zugestellt werden: '.$inner->getMessage());
        }
    }
}
