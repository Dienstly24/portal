<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Protokoll der geplanten Aufgaben (Systemzustand-Seite).
 *
 * Laravel merkt sich NICHT, wann eine geplante Aufgabe zuletzt wirklich
 * gelaufen ist. Faellt der Cron-Eintrag weg oder steht der Planer auf der
 * falschen Zeitzone, passiert schlicht nichts - ohne Fehlermeldung, ohne
 * Eintrag, ohne Anzeige. Genau dieser stille Ausfall war bisher unsichtbar.
 *
 * Je Aufgabe genau EINE Zeile (Schluessel = Befehl bzw. Beschreibung des
 * Closures), die bei jedem Lauf fortgeschrieben wird. Bewusst kein
 * Lauf-Archiv: die Seite beantwortet "laeuft es noch?", nicht "was lief am
 * 3. Maerz?" - dafuer gibt es die Logs. So bleibt die Tabelle klein.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id();
            // Der Planer-Schluessel: 'command:name' oder 'closure:<beschreibung>'.
            $table->string('task_key', 191)->unique();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->integer('exit_code')->nullable();
            // Kurzfassung des letzten Fehlers - nie der komplette Stacktrace.
            $table->string('last_error', 500)->nullable();
            $table->unsignedInteger('run_count')->default(0);
            $table->unsignedInteger('fail_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
