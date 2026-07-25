<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Neukunden-Bericht (Betreiber-Vorgabe 25.07.2026): Wer hat den Kunden
 * ANGELEGT (created_by) und wer hat ihn GEWORBEN (Werber)? Der Werber kann
 * ein Mitarbeiter (acquired_by -> users) ODER ein Vertriebspartner
 * (acquired_by_partner_id -> partners) sein - genau einer von beiden.
 *
 * Bewusst NICHT customers.partner_id wiederverwendet: das Feld steuert den
 * Partner-Portal-Zugriff (Datenfreigabe). Werber-Attribution fuer die
 * Provision darf KEINE Datensicht eroeffnen (DSGVO).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('source')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('acquired_by')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
            $table->uuid('acquired_by_partner_id')->nullable()->after('acquired_by');
            $table->foreign('acquired_by_partner_id')->references('id')->on('partners')->nullOnDelete();
        });

        // Best-Effort-Backfill: Auto-Anlagen haben den Anleger bereits im
        // Aktivitaetslog (customer_auto_created). Manuelle Alt-Anlagen bleiben
        // leer ("System/unbekannt") - keine Daten erfinden.
        $logs = DB::table('activity_logs')
            ->where('action', 'customer_auto_created')
            ->where('entity_type', 'customer')
            ->whereNotNull('user_id')
            ->whereNotNull('entity_id')
            ->orderBy('created_at')
            ->get(['entity_id', 'user_id']);
        foreach ($logs as $log) {
            DB::table('customers')
                ->where('id', $log->entity_id)
                ->whereNull('created_by')
                ->update(['created_by' => $log->user_id]);
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['acquired_by']);
            $table->dropForeign(['acquired_by_partner_id']);
            $table->dropColumn(['created_by', 'acquired_by', 'acquired_by_partner_id']);
        });
    }
};
