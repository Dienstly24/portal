<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit-Log fuer Provisionen (Provisions-Management, 25.07.2026): JEDE
 * Aenderung an einer Provision (Anlage, Betrags-Anpassung, Bonus/Abzug,
 * Freigabe, Auszahlung, Storno) wird feldgenau festgehalten - wer, wann,
 * alter Wert, neuer Wert, Grund. user_id null = System (automatische
 * Anlage/Gegenbuchung). Eintraege werden nie veraendert oder geloescht
 * (vollstaendige Finanzhistorie).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('provision_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('provision_id');
            $table->foreign('provision_id')->references('id')->on('provisions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // created | amount_changed | status_changed | storno_created ...
            $table->string('action', 40);
            $table->string('field', 40)->nullable();
            $table->string('old_value', 500)->nullable();
            $table->string('new_value', 500)->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['provision_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provision_audit_logs');
    }
};
