<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ein Eintrag je geplanter Aufgabe: wann lief sie zuletzt, wie lange, mit
 * welchem Ergebnis. Geschrieben von den Planer-Ereignissen (siehe
 * AppServiceProvider), gelesen von der Systemzustand-Seite.
 */
class ScheduledTaskRun extends Model
{
    protected $fillable = [
        'task_key', 'last_started_at', 'last_finished_at', 'last_success_at',
        'last_failed_at', 'runtime_ms', 'exit_code', 'last_error',
        'run_count', 'fail_count',
    ];

    protected $casts = [
        'last_started_at' => 'datetime',
        'last_finished_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failed_at' => 'datetime',
        'runtime_ms' => 'integer',
        'exit_code' => 'integer',
        'run_count' => 'integer',
        'fail_count' => 'integer',
    ];

    /**
     * Planer-Schluessel einer Aufgabe. Bei Befehlen wird der PHP-Binary-Pfad
     * abgeschnitten, damit derselbe Befehl auf Server und in Tests denselben
     * Schluessel hat (sonst entstuenden Doppel-Zeilen nach einem PHP-Update).
     */
    public static function keyFor(string $summary): string
    {
        $summary = trim($summary);

        if (preg_match('/artisan[\'"]?\s+(.+)$/', $summary, $m) === 1) {
            return 'command:'.trim($m[1], " '\"");
        }

        return 'closure:'.mb_substr($summary, 0, 150);
    }

    /** Sprechender Name fuer die Anzeige. */
    public function label(): string
    {
        if (str_starts_with($this->task_key, 'command:')) {
            return substr($this->task_key, strlen('command:'));
        }

        return 'Closure ('.substr($this->task_key, strlen('closure:')).')';
    }

    /** Letzter Lauf - erfolgreich oder nicht. */
    public function lastRunAt(): ?Carbon
    {
        return $this->last_finished_at ?? $this->last_started_at;
    }
}
