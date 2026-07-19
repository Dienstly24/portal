<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification-System-Audit (Juli 2026): Haertung + Skalierbarkeit.
 *
 *  - `type`      kategorisiert jede Benachrichtigung (ticket, message,
 *                change_request, document, system, ...). Grundlage fuer
 *                spaetere Filter, Priorisierung und weitere Kanaele
 *                (E-Mail/Push/SMS aus derselben Struktur).
 *  - `dedup_key` erlaubt zuverlaessige Duplikat-Vermeidung: mehrere
 *                gleiche Ereignisse (Doppel-Submit, wiederholte Antwort auf
 *                dasselbe Ticket) fallen zu EINEM ungelesenen Eintrag
 *                zusammen, statt die Glocke zu fluten.
 *  - Zusatzindex (user_id, created_at) beschleunigt das Notification
 *    Center (Sortierung nach Zeit je Empfaenger).
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('internal_notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('internal_notifications', 'type')) {
                $table->string('type', 40)->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('internal_notifications', 'dedup_key')) {
                $table->string('dedup_key')->nullable()->after('link');
            }
        });

        Schema::table('internal_notifications', function (Blueprint $table) {
            // Sortierung/Abruf je Empfaenger (Notification Center laedt die
            // neuesten Eintraege eines Users).
            $table->index(['user_id', 'created_at'], 'internal_notifications_user_created_idx');
            // Duplikat-Lookup: (user_id, dedup_key) fuer ungelesene Eintraege.
            $table->index(['user_id', 'dedup_key'], 'internal_notifications_user_dedup_idx');
        });
    }

    public function down(): void {
        Schema::table('internal_notifications', function (Blueprint $table) {
            $table->dropIndex('internal_notifications_user_created_idx');
            $table->dropIndex('internal_notifications_user_dedup_idx');
            $table->dropColumn(['type', 'dedup_key']);
        });
    }
};
