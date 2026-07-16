<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Arbeitssitzungen der Mitarbeiter (Login bis Logout/Timeout).
// Grundlage fuer Anmeldezeit vs. aktive Arbeitszeit vs. Leerlauf.
return new class extends Migration {
    public function up(): void {
        Schema::create('work_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('login_at');
            // Letzter Request egal welcher Art (Praesenz-Signal).
            $table->timestamp('last_seen_at')->nullable();
            // Letzte PRODUKTIVE Aktion - Basis der Aktivzeit-Berechnung.
            $table->timestamp('last_productive_at')->nullable();
            $table->timestamp('logout_at')->nullable();
            // Serverseitig gutgeschriebene aktive Arbeitszeit in Sekunden.
            $table->unsignedInteger('active_seconds')->default(0);
            // Wie die Sitzung endete: logout | timeout | new_login
            $table->string('ended_by', 20)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'login_at']);
            $table->index(['user_id', 'logout_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('work_sessions'); }
};
