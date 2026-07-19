<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indizes auf haeufig gefilterten Nicht-FK-Spalten (Audit DB-5). Dashboards,
 * Reports und Portal filtern/zaehlen ueber contracts.status/type und
 * tickets.status - bislang ohne Index (Full-Table-Scans, die mit dem
 * Datenvolumen wachsen). Composite-Indizes passend zum Zugriffsmuster.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('contracts', function (Blueprint $table) {
            $table->index(['customer_id', 'status'], 'contracts_customer_status_idx');
            $table->index('type', 'contracts_type_idx');
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->index('status', 'tickets_status_idx');
        });
    }

    public function down(): void {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex('contracts_customer_status_idx');
            $table->dropIndex('contracts_type_idx');
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_status_idx');
        });
    }
};
