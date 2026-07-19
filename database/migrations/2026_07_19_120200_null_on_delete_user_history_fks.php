<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cascade-Deletes Richtung users vernichteten bislang Betriebs-/Audit-Historie,
 * wenn ein Mitarbeiterkonto geloescht wurde (Audit DB-4): dessen Aufgaben,
 * Termine, Ankuendigungen, Notizen, Kampagnen und E-Mail-Sende-Logs
 * verschwanden mit. Wie schon bei ticket_messages (2026_07_13_100000): die
 * Autor-/Zustaendig-Spalten werden nullable + ON DELETE SET NULL. Auf SQLite
 * (Tests) wird nur nullable gesetzt - dort nullt die App die Referenzen selbst
 * (EmployeeController::destroy), sodass der Cascade nie greift.
 */
return new class extends Migration {
    /** table => [author-/assignee-Spalten Richtung users] */
    private array $map = [
        'tasks' => ['assigned_to', 'created_by'],
        'appointments' => ['assigned_to'],
        'announcements' => ['created_by'],
        'customer_notes' => ['created_by'],
        'email_campaigns' => ['created_by'],
        'email_logs' => ['user_id'],
    ];

    public function up(): void {
        $isSqlite = DB::getDriverName() === 'sqlite';
        foreach ($this->map as $table => $columns) {
            if (!Schema::hasTable($table)) continue;
            foreach ($columns as $col) {
                if (!Schema::hasColumn($table, $col)) continue;

                if (!$isSqlite) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropForeign([$col]));
                }
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger($col)->nullable()->change());
                if (!$isSqlite) {
                    Schema::table($table, fn (Blueprint $t) => $t->foreign($col)->references('id')->on('users')->nullOnDelete());
                }
            }
        }
    }

    public function down(): void {
        // Bewusst ohne erneutes CASCADE - das Datenverlust-Verhalten soll nicht
        // zurueckkehren.
    }
};
