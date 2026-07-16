<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Erweiterung des bestehenden Audit-Logs um die Felder der
// Aktivitaetserfassung (Seite, Client, Produktivitaet, Punkte,
// gutgeschriebene Aktivzeit, Zuordnung zur Arbeitssitzung).
// Alle Spalten nullable/mit Default - Alt-Eintraege bleiben gueltig.
return new class extends Migration {
    public function up(): void {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreignId('work_session_id')->nullable()->after('user_id')
                ->constrained('work_sessions')->nullOnDelete();
            $table->string('route')->nullable()->after('meta');
            $table->string('url_path', 500)->nullable()->after('route');
            $table->string('method', 10)->nullable()->after('url_path');
            $table->string('ip', 45)->nullable()->after('method');
            $table->string('user_agent')->nullable()->after('ip');
            $table->boolean('is_productive')->default(false)->after('user_agent');
            $table->unsignedSmallInteger('points')->default(0)->after('is_productive');
            $table->unsignedInteger('active_seconds')->default(0)->after('points');
            $table->index(['user_id', 'created_at']);
            $table->index('action');
        });
    }
    public function down(): void {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_session_id');
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['action']);
            $table->dropColumn(['route', 'url_path', 'method', 'ip', 'user_agent', 'is_productive', 'points', 'active_seconds']);
        });
    }
};
