<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SF-Sondereinstufung mit Bezugsfahrzeug (Betreiber-Auftrag 01.10.2026,
 * KFZ-Modul Phase 1).
 *
 * Eine Zweitwagen-/Drittwagen-/Familien-Einstufung wird WEGEN eines anderen
 * Vertrags gewaehrt (Erstwagen). Bisher stand dieser Bezug zweckentfremdet
 * in der Vorversicherung ("Vorheriger Versicherer: Zweite Wagen ADAC") -
 * die Vorversicherung beschreibt aber den Vorvertrag DIESES Fahrzeugs.
 *
 * Rein additiv: keine bestehende Spalte wird geaendert oder geloescht.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('vehicle_sf_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_vehicle_detail_id');
            $table->foreign('contract_vehicle_detail_id', 'vsr_detail_fk')
                ->references('id')->on('contract_vehicle_details')->cascadeOnDelete();
            $table->string('branch', 20); // haftpflicht|vollkasko

            // Bezugsfahrzeug (nur Zweitwagen/Drittwagen/Familie)
            $table->string('reference_type', 20)->nullable(); // internal|external
            $table->uuid('reference_contract_id')->nullable();
            // nullOnDelete: der Bezug ueberlebt das Loeschen des Erstwagens -
            // reference_label haelt fest, worauf er sich bezog.
            $table->foreign('reference_contract_id', 'vsr_ref_contract_fk')
                ->references('id')->on('contracts')->nullOnDelete();
            $table->string('reference_label')->nullable();
            $table->string('ext_insurer', 120)->nullable();
            $table->string('ext_contract_number', 60)->nullable();
            $table->string('ext_license_plate', 20)->nullable();
            $table->string('ext_sf_class', 10)->nullable(); // SF des Erstwagens in DIESER Sparte

            $table->string('holder_relation', 30)->nullable(); // kunde|partner|familie|sonstige
            $table->string('holder_name', 120)->nullable();

            // Stand bei Gewaehrung: aendert sich der Erstwagen spaeter,
            // bleibt nachvollziehbar, worauf die Einstufung beruhte.
            $table->string('snapshot_sf_class', 10)->nullable();
            $table->date('snapshot_date')->nullable();

            $table->uuid('proof_document_id')->nullable();
            $table->foreign('proof_document_id', 'vsr_proof_fk')
                ->references('id')->on('documents')->nullOnDelete();
            $table->boolean('verified')->default(false);
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();

            // Angaben der uebrigen Gruende
            $table->date('license_date')->nullable();     // Fuehrerschein seit
            $table->string('campaign_name', 120)->nullable(); // Sonderaktion
            $table->text('note')->nullable();             // Sonstige / Freitext

            $table->timestamps();
            $table->unique(['contract_vehicle_detail_id', 'branch'], 'vsr_detail_branch_unique');
            $table->index('reference_contract_id', 'vsr_ref_contract_idx');
        });

        Schema::table('vehicle_sf_history', function (Blueprint $table) {
            $table->string('special_reason', 40)->nullable()->after('sf_class');
            $table->string('reference_label')->nullable()->after('special_reason');
            $table->uuid('reference_contract_id')->nullable()->after('reference_label');
        });

        Schema::table('contract_vehicle_details', function (Blueprint $table) {
            $table->boolean('no_previous_insurance')->default(false)->after('previous_insurance_terminated_by_insurer');
        });
    }

    public function down(): void {
        Schema::table('contract_vehicle_details', function (Blueprint $table) {
            $table->dropColumn('no_previous_insurance');
        });
        Schema::table('vehicle_sf_history', function (Blueprint $table) {
            $table->dropColumn(['special_reason', 'reference_label', 'reference_contract_id']);
        });
        Schema::dropIfExists('vehicle_sf_references');
    }
};
