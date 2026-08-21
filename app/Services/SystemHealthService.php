<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AiKnowledgeEntry;
use App\Models\Document;
use App\Models\ErrorEvent;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/**
 * Sammelt den Betriebszustand des Systems fuer /admin/systemzustand.
 *
 * Warum es diese Seite gibt: die riskanten Teile des Portals laufen im
 * HINTERGRUND - Warteschlange, Planer, externe Dienste. Faellt dort etwas
 * aus, sieht der Betreiber nichts. Keine Fehlermeldung, keine leere Seite:
 * es passiert einfach nichts mehr. Dokument-Analysen bleiben "in Pruefung",
 * Erinnerungen gehen nicht raus, die KI schweigt. Bisher fiel das erst auf,
 * wenn sich ein Kunde beschwert hat.
 *
 * Grundregeln dieses Dienstes:
 *  - NUR LESEND. Keine Aktion, kein Versand, keine Aenderung.
 *  - KEINE Geheimnisse ausgeben - immer nur "gesetzt" / "fehlt", nie der
 *    Wert und auch kein Ausschnitt davon.
 *  - KEINE kostenpflichtigen Aufrufe an externe Dienste beim Seitenaufbau.
 *    Geprueft wird die Konfiguration, nicht die Rechnung. Den echten
 *    Live-Test macht bewusst nur `php artisan ki:pruefen --live`.
 */
class SystemHealthService
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';
    public const INFO = 'info';

    /** Ab so vielen Minuten Wartezeit gilt ein Job als haengend. */
    private const JOB_STALE_MINUTES = 15;

    /** Puffer, den eine geplante Aufgabe ueber ihr Intervall hinaus haben darf. */
    private const SCHEDULE_GRACE_MINUTES = 60;

    /**
     * Gesamtbild: alle Abschnitte plus eine Ampel darueber.
     */
    public function overview(): array
    {
        $sections = [
            'queue' => $this->queue(),
            'schedule' => $this->schedule(),
            'integrations' => $this->integrations(),
            'security' => $this->security(),
            'errors' => $this->errors(),
        ];

        return [
            'generated_at' => now(),
            'environment' => app()->environment(),
            'app_timezone' => config('app.timezone'),
            'schedule_timezone' => config('app.schedule_timezone'),
            'sections' => $sections,
            'status' => $this->worstOf(array_column($sections, 'status')),
        ];
    }

    // ================================================================ Queue

    /**
     * Warteschlange: fehlgeschlagene Jobs, Rueckstau und die eigentliche
     * Frage - laeuft ueberhaupt ein Worker?
     */
    public function queue(): array
    {
        $connection = (string) config('queue.default');
        $items = [];
        $status = self::OK;

        // Bei 'sync' gibt es keinen Worker: Jobs laufen im Request. Das ist
        // kein Fehler, aber die folgenden Kennzahlen waeren sinnlos.
        if ($connection === 'sync') {
            return [
                'title' => 'Warteschlange',
                'status' => self::WARN,
                'summary' => 'Verbindung "sync" - Hintergrundaufgaben laufen direkt im Aufruf.',
                'items' => [[
                    'label' => 'Queue-Verbindung',
                    'value' => 'sync',
                    'status' => self::WARN,
                    'hint' => 'Im Produktivbetrieb QUEUE_CONNECTION=database (oder redis) setzen und einen Worker betreiben.',
                ]],
            ];
        }

        $items[] = [
            'label' => 'Queue-Verbindung',
            'value' => $connection,
            'status' => self::INFO,
        ];

        // 1) Fehlgeschlagene Jobs.
        $failed = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;
        $items[] = [
            'label' => 'Fehlgeschlagene Jobs',
            'value' => (string) $failed,
            'status' => $failed > 0 ? self::FAIL : self::OK,
            'hint' => $failed > 0
                ? 'Auf dem Server ansehen: php artisan queue:failed - erneut versuchen: php artisan queue:retry all'
                : null,
        ];
        if ($failed > 0) {
            $status = self::FAIL;
        }

        // 2) Wartende Jobs und - die entscheidende Frage - wie lange schon.
        $waiting = 0;
        $oldest = null;
        if (Schema::hasTable('jobs')) {
            $waiting = (int) DB::table('jobs')->count();
            $oldestTs = DB::table('jobs')->min('available_at');
            if ($oldestTs !== null) {
                $oldest = Carbon::createFromTimestamp((int) $oldestTs);
            }
        }

        $items[] = [
            'label' => 'Wartende Jobs',
            'value' => (string) $waiting,
            'status' => self::INFO,
        ];

        // Ein voller Stapel allein sagt nichts - erst ein Job, der SEIT
        // LANGEM wartet, beweist, dass niemand ihn abholt.
        $workerAlive = true;
        if ($oldest !== null && $oldest->lt(now()->subMinutes(self::JOB_STALE_MINUTES))) {
            $workerAlive = false;
        }

        $items[] = [
            'label' => 'Queue-Worker',
            'value' => $workerAlive
                ? ($waiting > 0 ? 'arbeitet' : 'nichts zu tun')
                : 'antwortet nicht',
            'status' => $workerAlive ? self::OK : self::FAIL,
            'hint' => $workerAlive
                ? ($waiting === 0 ? 'Leere Warteschlange - ein toter Worker faellt erst mit dem naechsten Job auf.' : null)
                : 'Aeltester Job wartet seit ' . $oldest->diffForHumans(now(), true)
                    . '. Worker starten (supervisor/systemd) - siehe docs/DEPLOYMENT.md.',
        ];
        if (! $workerAlive) {
            $status = self::FAIL;
        }

        // 3) Dokument-Analyse als fachlicher Rueckstau-Anzeiger.
        $pending = Document::where('ai_status', 'pending')->count();
        $stale = Document::where('ai_status', 'pending')
            ->where('created_at', '<', now()->subMinutes(30))->count();

        $items[] = [
            'label' => 'Dokumente in Analyse-Warteschlange',
            'value' => $pending . ($stale > 0 ? ' (' . $stale . ' seit ueber 30 Min)' : ''),
            'status' => $stale >= max(1, (int) config('services.ocr.pending_backlog_alert', 10))
                ? self::WARN : self::OK,
            'hint' => $stale > 0 ? 'Sicherheitsnetz: php artisan documents:analyze-pending' : null,
        ];
        if ($stale >= max(1, (int) config('services.ocr.pending_backlog_alert', 10)) && $status === self::OK) {
            $status = self::WARN;
        }

        return [
            'title' => 'Warteschlange',
            'status' => $status,
            'summary' => $failed . ' fehlgeschlagen, ' . $waiting . ' wartend.',
            'items' => $items,
        ];
    }

    // ============================================================= Zeitplan

    /**
     * Geplante Aufgaben: was ist definiert, wann lief es zuletzt WIRKLICH.
     *
     * Die Liste kommt aus der Planer-Definition (routes/console.php), die
     * Laufzeiten aus scheduled_task_runs. Eine Aufgabe, die definiert ist
     * aber nie gelaufen ist, faellt damit sofort auf - genau der Fall, der
     * bei einem fehlenden Cron-Eintrag oder falscher Zeitzone eintritt.
     */
    public function schedule(): array
    {
        $runs = Schema::hasTable('scheduled_task_runs')
            ? ScheduledTaskRun::all()->keyBy('task_key')
            : collect();

        $tasks = [];
        $neverRan = 0;
        $overdue = 0;
        $failing = 0;

        foreach ($this->scheduledEvents() as $event) {
            $key = ScheduledTaskRun::keyFor($event->getSummaryForDisplay());
            $run = $runs->get($key);
            $lastRun = $run?->lastRunAt();

            $itemStatus = self::OK;
            $note = null;

            if ($lastRun === null) {
                // Noch nie gelaufen. Bei sehr seltenen Aufgaben (einmal
                // taeglich) kann das direkt nach dem Einbau normal sein -
                // deshalb Hinweis, nicht Fehler.
                $itemStatus = self::WARN;
                $note = 'Noch kein Lauf protokolliert.';
                $neverRan++;
            } elseif ($lastRun->lt(now()->subMinutes($this->expectedIntervalMinutes($event) + self::SCHEDULE_GRACE_MINUTES))) {
                $itemStatus = self::FAIL;
                $note = 'Ueberfaellig - letzter Lauf ' . $lastRun->diffForHumans() . '.';
                $overdue++;
            }

            if ($run && $run->last_failed_at !== null
                && ($run->last_success_at === null || $run->last_failed_at->gte($run->last_success_at))) {
                $itemStatus = self::FAIL;
                $note = 'Letzter Lauf fehlgeschlagen: ' . ($run->last_error ?: 'Exitcode ' . $run->exit_code);
                $failing++;
            }

            $tasks[] = [
                'label' => $event->getSummaryForDisplay(),
                'expression' => $event->expression,
                'last_run' => $lastRun,
                'runtime_ms' => $run?->runtime_ms,
                'run_count' => $run?->run_count ?? 0,
                'fail_count' => $run?->fail_count ?? 0,
                'status' => $itemStatus,
                'note' => $note,
            ];
        }

        // Sortierung: Auffaelliges zuerst - die Seite soll nicht zum Suchen
        // zwingen.
        $rank = [self::FAIL => 0, self::WARN => 1, self::OK => 2, self::INFO => 3];
        usort($tasks, fn ($a, $b) => ($rank[$a['status']] ?? 9) <=> ($rank[$b['status']] ?? 9));

        $status = self::OK;
        if ($overdue > 0 || $failing > 0) {
            $status = self::FAIL;
        } elseif ($neverRan > 0) {
            $status = self::WARN;
        }

        // Ein einziger Wert beantwortet die Frage "laeuft der Cron ueberhaupt?"
        $lastAny = $runs->max(fn (ScheduledTaskRun $r) => $r->lastRunAt()?->getTimestamp());

        return [
            'title' => 'Geplante Aufgaben',
            'status' => $status,
            'summary' => count($tasks) . ' definiert, ' . $overdue . ' ueberfaellig, ' . $failing . ' fehlerhaft.',
            'last_any_run' => $lastAny ? Carbon::createFromTimestamp($lastAny) : null,
            'tasks' => $tasks,
            'items' => [],
        ];
    }

    /**
     * Die definierten Planer-Ereignisse.
     *
     * WICHTIG: routes/console.php wird im Web-Aufruf nicht von selbst
     * geladen - der Planer ist dort schlicht leer. Deshalb wird der
     * Console-Kernel angestossen; er laedt die Befehlsrouten nach und ist
     * idempotent (die Anwendung selbst ist laengst gebootet, es werden nur
     * die Befehle registriert). Ohne diesen Schritt zeigte die Seite
     * "keine geplanten Aufgaben" - also ausgerechnet dort einen falschen
     * Alarm, wo sie Vertrauen schaffen soll.
     */
    private function scheduledEvents(): array
    {
        try {
            app(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

            return app(Schedule::class)->events();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Grober Erwartungswert, wie oft eine Aufgabe laufen sollte - aus dem
     * Cron-Ausdruck abgeleitet. Bewusst grob: die Seite soll einen STILLEN
     * AUSFALL zeigen, keine Minuten zaehlen.
     */
    private function expectedIntervalMinutes(object $event): int
    {
        $expr = (string) ($event->expression ?? '0 0 * * *');
        [$minute, $hour] = array_pad(explode(' ', $expr), 5, '*');

        if (str_contains($minute, '/')) {
            $step = (int) substr($minute, strpos($minute, '/') + 1);

            return max(1, $step);
        }

        if ($minute === '*') {
            return 1;
        }

        if ($hour === '*') {
            return 60;
        }

        if (str_contains($hour, '/')) {
            return max(60, ((int) substr($hour, strpos($hour, '/') + 1)) * 60);
        }

        // Taeglich (oder seltener) - ein Tag Erwartung.
        return 60 * 24;
    }

    // ========================================================= Integrationen

    /**
     * Externe Dienste: nur Konfigurationsstand, keine Live-Aufrufe.
     */
    public function integrations(): array
    {
        $items = [];

        // --- OCR / Textebene (kostenlose Analyse-Basisebene)
        $ocrEnabled = (bool) config('services.ocr.enabled');
        // Die Programme nur pruefen, wenn OCR ueberhaupt genutzt wird - jeder
        // Probe-Aufruf startet einen Prozess und wuerde den Seitenaufbau
        // sonst grundlos verlangsamen.
        $binaries = $ocrEnabled ? $this->ocrBinaries() : ['ok' => true, 'missing' => []];
        $items[] = [
            'label' => 'OCR / PDF-Textebene',
            'value' => $ocrEnabled
                ? ('aktiv (' . (string) config('services.ocr.languages', 'deu+eng') . ')')
                : 'abgeschaltet',
            'status' => $ocrEnabled ? ($binaries['ok'] ? self::OK : self::FAIL) : self::WARN,
            'hint' => $ocrEnabled
                ? ($binaries['ok'] ? null : 'Fehlende Programme: ' . implode(', ', $binaries['missing'])
                    . ' - pruefen mit: php artisan ocr:check')
                : 'OCR_ENABLED=false - jede Analyse geht direkt an die kostenpflichtige KI.',
        ];

        // --- KI-Dokumentanalyse
        $anthropic = $this->configured(config('services.anthropic.key'));
        $items[] = [
            'label' => 'KI-Dokumentanalyse (Anthropic)',
            'value' => $anthropic ? 'Schluessel gesetzt' : 'kein Schluessel',
            'status' => $anthropic ? self::OK : self::WARN,
            'hint' => $anthropic ? null : 'ANTHROPIC_API_KEY in der Server-.env setzen (nie im Repo).',
        ];

        // --- KI-Kundenassistent
        $items[] = $this->assistantItem();

        // --- Meta (Facebook/Instagram)
        $meta = config('services.meta', []);
        $metaToken = $this->configured($meta['access_token'] ?? null);
        $metaPage = $this->configured($meta['page_id'] ?? null);
        $items[] = [
            'label' => 'Meta (Facebook / Instagram)',
            'value' => $metaToken
                ? ('Token gesetzt' . ($metaPage ? ', Seite verknuepft' : ', Seite fehlt'))
                : 'nicht eingerichtet',
            'status' => $metaToken ? ($metaPage ? self::OK : self::WARN) : self::INFO,
            'hint' => $metaToken
                ? ($metaPage ? null : 'META_PAGE_ID fehlt - Einrichtung: php artisan meta:einrichten')
                : 'Optional. Ohne Meta bleibt Social-Publishing eine manuelle Aktion.',
        ];

        // --- lexoffice
        $lex = $this->configured(config('services.lexoffice.key'));
        $items[] = [
            'label' => 'lexoffice',
            'value' => $lex ? 'Schluessel gesetzt' : 'nicht eingerichtet',
            'status' => $lex ? self::OK : self::INFO,
            'hint' => $lex ? null : 'Optional. Ohne Schluessel bleibt der Rechnungsbereich leer.',
        ];

        // --- Datenhaltung fuer Sitzungen, Cache und Warteschlange
        $items[] = $this->storageItem();

        // --- E-Mail-Versand
        $mailer = (string) config('mail.default');
        $items[] = [
            'label' => 'E-Mail-Versand',
            'value' => $mailer,
            'status' => in_array($mailer, ['log', 'array'], true) ? self::WARN : self::OK,
            'hint' => in_array($mailer, ['log', 'array'], true)
                ? 'Es geht KEINE echte E-Mail raus - MAIL_MAILER in der Server-.env pruefen.'
                : null,
        ];

        return [
            'title' => 'Externe Dienste',
            'status' => $this->worstOf(array_column($items, 'status')),
            'summary' => 'Konfigurationsstand - kein Live-Aufruf.',
            'items' => $items,
        ];
    }

    /** Zustand des KI-Kundenassistenten als eine Zeile. */
    private function assistantItem(): array
    {
        try {
            $settings = app(\App\Services\Ai\Assistant\AssistantSettings::class);
            $enabled = $settings->enabled();
            $autoReply = $settings->autoReply();
        } catch (\Throwable $e) {
            return [
                'label' => 'KI-Kundenassistent',
                'value' => 'Zustand nicht lesbar',
                'status' => self::WARN,
                'hint' => 'Pruefen mit: php artisan ki:pruefen',
            ];
        }

        $active = Schema::hasTable('ai_knowledge_entries')
            ? AiKnowledgeEntry::active()->count() : 0;

        if (! $enabled) {
            return [
                'label' => 'KI-Kundenassistent',
                'value' => 'ausgeschaltet',
                'status' => self::INFO,
                'hint' => 'Voreinstellung ist AUS. Vor dem Einschalten: Wissensbasis fuellen und ki:pruefen.',
            ];
        }

        return [
            'label' => 'KI-Kundenassistent',
            'value' => 'aktiv' . ($autoReply ? ', antwortet automatisch' : ', nur manuell')
                . ' - ' . $active . ' freigegebene Wissenseintraege',
            'status' => $active === 0 ? self::WARN : self::OK,
            'hint' => $active === 0
                ? 'Wissensbasis leer - der Assistent uebergibt fast alles ans Team. Start: php artisan ki:wissensbasis-vorschlag --schreiben'
                : 'Vollstaendige Diagnose: php artisan ki:pruefen --live',
        ];
    }

    /**
     * Womit laufen Sitzungen, Cache und Warteschlange - und ist das erreichbar?
     *
     * Die Datenbank ist fuer alle drei ein tragfaehiger Standard, aber sie
     * bezahlt jede Sitzung und jeden Job mit Schreibzugriffen. Wer auf Redis
     * umstellt, will vor allem EINES sofort wissen: hat es geklappt? Genau
     * das beantwortet diese Zeile - inklusive echtem PING, wenn Redis
     * eingestellt ist. Ein Umzug, der still auf die Datenbank zurueckfaellt,
     * waere sonst monatelang unbemerkt.
     */
    private function storageItem(): array
    {
        $treiber = [
            'Sitzungen' => (string) config('session.driver'),
            'Cache' => (string) config('cache.default'),
            'Warteschlange' => (string) config('queue.default'),
        ];
        $wert = implode(', ', array_map(
            fn ($name, $treiber) => $name . ': ' . $treiber,
            array_keys($treiber),
            $treiber
        ));

        $nutztRedis = in_array('redis', $treiber, true);
        if (! $nutztRedis) {
            return [
                'label' => 'Sitzungen / Cache / Warteschlange',
                'value' => $wert,
                'status' => self::INFO,
                'hint' => 'Datenbank ist ein tragfaehiger Standard. Redis entlastet sie spuerbar - '
                    . 'Umstellung: docs/ANLEITUNG_REDIS_AR.md',
            ];
        }

        // Echter PING - nur so ist bewiesen, dass die Umstellung wirkt.
        try {
            \Illuminate\Support\Facades\Redis::connection()->ping();

            return [
                'label' => 'Sitzungen / Cache / Warteschlange',
                'value' => $wert . ' - Redis erreichbar',
                'status' => self::OK,
            ];
        } catch (\Throwable $e) {
            return [
                'label' => 'Sitzungen / Cache / Warteschlange',
                'value' => $wert . ' - Redis NICHT erreichbar',
                'status' => self::FAIL,
                'hint' => 'Redis ist eingestellt, antwortet aber nicht. Auf dem Server pruefen: '
                    . 'systemctl status redis-server und redis-cli ping (erwartet: PONG).',
            ];
        }
    }

    /** Sind die OCR-Programme auf dem Server vorhanden? */
    private function ocrBinaries(): array
    {
        $missing = [];
        foreach ([
            'pdftotext' => (string) config('services.ocr.pdftotext_binary', 'pdftotext'),
            'tesseract' => (string) config('services.ocr.tesseract_binary', 'tesseract'),
        ] as $name => $binary) {
            try {
                $process = new Process([$binary, '-v']);
                $process->setTimeout(5);
                $process->run();
                // pdftotext/tesseract geben die Version teils auf STDERR und
                // mit Nicht-Null aus - entscheidend ist, dass das Programm
                // ueberhaupt gefunden wurde.
                $output = $process->getOutput() . $process->getErrorOutput();
                if (trim($output) === '') {
                    $missing[] = $name;
                }
            } catch (\Throwable $e) {
                $missing[] = $name;
            }
        }

        return ['ok' => $missing === [], 'missing' => $missing];
    }

    // ============================================================ Sicherheit

    /**
     * Anmeldung und zweiter Faktor. Das ist der direkte Erfolgsanzeiger der
     * 2FA-Einfuehrung: haeufen sich Fehlversuche, kommt das Team nicht rein.
     */
    public function security(): array
    {
        $items = [];
        $since = now()->subDay();

        $failedLogins = Schema::hasTable('activity_logs')
            ? ActivityLog::where('action', 'login_failed')->where('created_at', '>=', $since)->count() : 0;
        $failedTwoFactor = Schema::hasTable('activity_logs')
            ? ActivityLog::where('action', 'two_factor_failed')->where('created_at', '>=', $since)->count() : 0;

        $items[] = [
            'label' => 'Fehlgeschlagene Anmeldungen (24 h)',
            'value' => (string) $failedLogins,
            'status' => $failedLogins > 50 ? self::WARN : self::OK,
            'hint' => $failedLogins > 50
                ? 'Auffaellig viele - im Aktivitaetslog nach wiederkehrenden IPs sehen.' : null,
        ];

        $items[] = [
            'label' => 'Fehlgeschlagene 2FA-Eingaben (24 h)',
            'value' => (string) $failedTwoFactor,
            'status' => $failedTwoFactor > 20 ? self::WARN : self::OK,
            'hint' => $failedTwoFactor > 20
                ? 'Haeufigste Ursache ist eine abweichende Server-Uhr. Auf dem Server pruefen: timedatectl status. '
                    . 'Konto entsperren: php artisan 2fa:zuruecksetzen <email>'
                : null,
        ];

        // 2FA-Einfuehrung: wie viele der pflichtigen Konten sind schon durch?
        $required = User::query()
            ->whereIn('role', ['admin', 'manager', 'support', 'employee', 'partner'])
            ->where(fn ($q) => $q->whereNull('is_active')->orWhere('is_active', '!=', false));
        $total = (clone $required)->count();
        $confirmed = (clone $required)->whereNotNull('two_factor_confirmed_at')->count();

        $items[] = [
            'label' => '2FA eingerichtet',
            'value' => $confirmed . ' von ' . $total . ' pflichtigen Konten',
            'status' => ($total > 0 && $confirmed < $total) ? self::WARN : self::OK,
            'hint' => ($total > 0 && $confirmed < $total)
                ? 'Offene Konten richten den zweiten Faktor beim naechsten Login selbst ein - niemand wird ausgesperrt.'
                : null,
        ];

        $twoFactorRequired = \App\Http\Middleware\EnsureTwoFactor::enabled();
        $items[] = [
            'label' => 'Zwei-Faktor-Pflicht',
            'value' => $twoFactorRequired ? 'aktiv' : 'abgeschaltet',
            'status' => $twoFactorRequired ? self::OK : self::WARN,
            'hint' => $twoFactorRequired ? null
                : 'Schalter unter Einstellungen -> Sicherheit. Interne Konten sehen fremde personenbezogene Daten.',
        ];

        $items[] = [
            'label' => 'Debug-Modus',
            'value' => config('app.debug') ? 'AN' : 'aus',
            'status' => config('app.debug') ? self::FAIL : self::OK,
            'hint' => config('app.debug')
                ? 'APP_DEBUG=true gibt Stacktraces und Konfigurationswerte nach aussen. Im Produktivbetrieb auf false setzen.'
                : null,
        ];

        return [
            'title' => 'Anmeldung & Sicherheit',
            'status' => $this->worstOf(array_column($items, 'status')),
            'summary' => $failedLogins . ' fehlgeschlagene Anmeldungen, ' . $failedTwoFactor . ' fehlgeschlagene 2FA-Eingaben (24 h).',
            'items' => $items,
        ];
    }

    // ============================================================== Fehler

    /**
     * Unerwartete Fehler (500er), die echte Nutzer getroffen haben.
     *
     * Bisher landeten sie ausschliesslich in storage/logs/laravel.log -
     * einer Datei, die im Alltag niemand oeffnet. Der Betreiber erfuhr davon
     * erst, wenn sich jemand beschwert hat.
     */
    public function errors(): array
    {
        if (! Schema::hasTable('error_events')) {
            return [
                'title' => 'Fehler',
                'status' => self::INFO,
                'summary' => 'Fehlererfassung noch nicht eingerichtet.',
                'items' => [],
            ];
        }

        $offen24h = ErrorEvent::open()->seenSince(now()->subDay())->count();
        $offen7t = ErrorEvent::open()->seenSince(now()->subDays(7))->count();
        $haeufigster = ErrorEvent::open()->seenSince(now()->subDays(7))
            ->orderByDesc('occurrences')->first();

        $items = [[
            'label' => 'Neue Fehler (24 h)',
            'value' => (string) $offen24h,
            'status' => $offen24h > 0 ? self::FAIL : self::OK,
            'hint' => $offen24h > 0 ? 'Einzeln ansehen: /admin/fehler' : null,
        ], [
            'label' => 'Offene Fehler (7 Tage)',
            'value' => (string) $offen7t,
            'status' => $offen7t > 0 ? self::WARN : self::OK,
        ]];

        if ($haeufigster) {
            $items[] = [
                'label' => 'Haeufigster offener Fehler',
                'value' => $haeufigster->shortClass() . ' (' . $haeufigster->occurrences . '×)',
                'status' => self::INFO,
                'hint' => $haeufigster->shortFile() . ':' . $haeufigster->line
                    . ($haeufigster->route ? ' · ' . $haeufigster->route : ''),
            ];
        }

        return [
            'title' => 'Fehler',
            'status' => $this->worstOf(array_column($items, 'status')),
            'summary' => $offen24h . ' in den letzten 24 Stunden, ' . $offen7t . ' offen in 7 Tagen.',
            'items' => $items,
        ];
    }

    // ================================================================ Helfer

    /** Ist ein Konfigurationswert gesetzt? Der WERT wird nie zurueckgegeben. */
    private function configured(mixed $value): bool
    {
        return is_string($value) ? trim($value) !== '' : ! empty($value);
    }

    /** Schlechtester Zustand einer Liste - INFO zaehlt nie als Problem. */
    private function worstOf(array $states): string
    {
        if (in_array(self::FAIL, $states, true)) {
            return self::FAIL;
        }
        if (in_array(self::WARN, $states, true)) {
            return self::WARN;
        }

        return self::OK;
    }
}
