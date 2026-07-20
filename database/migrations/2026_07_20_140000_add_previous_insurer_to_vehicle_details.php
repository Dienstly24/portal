<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vorversicherung (bisheriger Kfz-Versicherer) am Fahrzeugvertrag festhalten.
 *
 * Aus dem CHECK24-Beratungsprotokoll ("Vorversicherung: Generali", "Seit
 * wann: laenger als 3 Jahre", "Kuendigung durch Vorversicherer: nein") laesst
 * sich beim Wechsel eindeutig ablesen, wo der Kunde vorher versichert war -
 * eine wichtige Wechsel-/Beratungsinformation. Bisher ging sie verloren
 * (keine Vertragsspalte), der Betrieb musste sie von Hand notieren.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('contract_vehicle_details', function (Blueprint $table) {
            $table->string('previous_insurer', 120)->nullable()->after('annual_mileage');
            // Freitext wie "laenger als 3 Jahre" / "seit 01.2021".
            $table->string('previous_insurance_since', 60)->nullable()->after('previous_insurer');
            // Kuendigung durch den Vorversicherer (relevant fuer Annahme/Beitrag).
            $table->boolean('previous_insurance_terminated_by_insurer')->nullable()->after('previous_insurance_since');
        });
    }

    public function down(): void {
        Schema::table('contract_vehicle_details', function (Blueprint $table) {
            $table->dropColumn([
                'previous_insurer',
                'previous_insurance_since',
                'previous_insurance_terminated_by_insurer',
            ]);
        });
    }
};
