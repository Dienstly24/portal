<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-Mail-Marketing Paket C: spartenspezifische Wechsel-Erinnerungen.
 * - contracts.subtype: bei Krankenversicherung gkv|pkv - nur GKV wird
 *   erinnert (§175 SGB V), PKV nie.
 * - contract_switch_reminders: eine Zeile pro gesendeter Erinnerung.
 *   Der Unique-Index (contract, stage, anchor) macht den Versand
 *   idempotent - Button und Cron können nie doppelt senden.
 *   anchor = end_date des Vertrags (bzw. Wechselberechtigungs-Datum
 *   bei GKV), damit pro Vertragsperiode neu erinnert werden darf.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('subtype', 10)->nullable()->after('type');
        });
        Schema::create('contract_switch_reminders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_id');
            $table->foreign('contract_id')->references('id')->on('contracts')->onDelete('cascade');
            $table->string('stage', 10); // first | followup
            $table->date('anchor');
            $table->timestamp('sent_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['contract_id', 'stage', 'anchor'], 'csr_contract_stage_anchor_unique');
        });
    }

    public function down(): void {
        Schema::dropIfExists('contract_switch_reminders');
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('subtype');
        });
    }
};
