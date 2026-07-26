<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aufgaben-System-Ausbau (Betreiber-Vorgabe 26.07.2026):
 *
 * 1) `type` wird von ENUM auf String umgestellt. Der taegliche
 *    Geburtstags-Job (routes/console.php) legt bereits Aufgaben mit
 *    type='reminder' an - das ENUM kannte den Wert nicht (auf MySQL
 *    strict schlug das Anlegen fehl). String macht die Typenliste
 *    zukunftssicher; die gueltigen Werte pflegt Task::TYPES.
 *
 * 2) Geplante automatische Kunden-E-Mail je Aufgabe ("in 14 Tagen
 *    nachfassen"): Betreff/Text (mit {{platzhaltern}}) + Stichtag werden
 *    beim Anlegen erfasst, der Versand laeuft ueber tasks:send-auto-emails.
 *    Status: pending -> sent | skipped (Aufgabe vorher erledigt) | failed.
 *
 * 3) `completed_at` haelt fest, WANN eine Aufgabe erledigt wurde
 *    (Sortierung im Erledigt-Tab, Nachvollziehbarkeit).
 *
 * Alles additiv/nullable - bestehende Aufgaben bleiben unveraendert gueltig.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('type', 30)->default('other')->change();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('due_date');
            $table->string('auto_email_status', 20)->nullable()->after('completed_at');
            $table->string('auto_email_subject', 200)->nullable()->after('auto_email_status');
            $table->text('auto_email_body')->nullable()->after('auto_email_subject');
            $table->date('auto_email_send_on')->nullable()->after('auto_email_body');
            $table->timestamp('auto_email_sent_at')->nullable()->after('auto_email_send_on');
            $table->string('auto_email_error', 500)->nullable()->after('auto_email_sent_at');

            $table->index(['auto_email_status', 'auto_email_send_on'], 'tasks_auto_email_due_index');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_auto_email_due_index');
            $table->dropColumn([
                'completed_at',
                'auto_email_status',
                'auto_email_subject',
                'auto_email_body',
                'auto_email_send_on',
                'auto_email_sent_at',
                'auto_email_error',
            ]);
        });
        // `type` bleibt bewusst String - Rueckbau auf ENUM wuerde
        // vorhandene 'reminder'-Aufgaben ungueltig machen.
    }
};
