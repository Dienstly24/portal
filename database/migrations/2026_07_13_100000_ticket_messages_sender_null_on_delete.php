<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ticket_messages.sender_id: bisher ON DELETE CASCADE - das Loeschen eines
 * Mitarbeiter-Kontos entfernte damit still dessen saemtliche Antworten aus
 * den Kundengespraechen (Verlust der Kommunikationshistorie). Jetzt:
 * nullable + ON DELETE SET NULL; die Views zeigen dann "Dienstly24 Team".
 */
return new class extends Migration {
    public function up(): void {
        // SQLite (Tests) unterstuetzt dropForeign nicht; dort genuegt das
        // Nullable-Change - die App nullt sender_id beim Loeschen selbst
        // (EmployeeController::destroy), sodass der Cascade nie greift.
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('ticket_messages', function (Blueprint $table) {
                $table->dropForeign(['sender_id']);
            });
        }
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('sender_id')->nullable()->change();
        });
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('ticket_messages', function (Blueprint $table) {
                $table->foreign('sender_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void {
        // Rueckweg bewusst ohne erneutes CASCADE - Datenverlust-Verhalten
        // soll nicht wiederhergestellt werden.
    }
};
