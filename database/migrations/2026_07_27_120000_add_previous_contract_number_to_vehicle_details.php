<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vertragsnummer beim Vorversicherer (Vorvertrag) am Fahrzeugvertrag.
 *
 * Auf Schreiben des bisherigen Versicherers (z.B. ADAC "Ihre Kfz-Versicherung
 * AD-...") steht die Nummer des ALTEN Vertrags - sie wird fuer Kuendigung und
 * Wechsel gebraucht und musste bisher von Hand notiert werden. Die KI-Analyse
 * liefert sie beim Beratungsprotokoll bereits als previous_contract_number,
 * hatte aber keine Spalte zum Speichern.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('contract_vehicle_details', function (Blueprint $table) {
            $table->string('previous_contract_number', 60)->nullable()->after('previous_insurer');
        });
    }

    public function down(): void {
        Schema::table('contract_vehicle_details', function (Blueprint $table) {
            $table->dropColumn('previous_contract_number');
        });
    }
};
