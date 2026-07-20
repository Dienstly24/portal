<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vorversorger (bisheriger Strom-/Gaslieferant) am Energievertrag festhalten.
 *
 * Aus dem Auftrag ("Derzeitiger Lieferant: Stadtwerke Neuss", "Kundennummer
 * beim derzeitigen Lieferanten: 20478172") laesst sich beim Wechsel eindeutig
 * ablesen, von wem der Kunde kommt - wichtig fuer Kuendigung/Anmeldung. Bisher
 * gab es keine Vertragsspalte dafuer.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('contract_energy_details', function (Blueprint $table) {
            $table->string('previous_provider', 150)->nullable()->after('metering_operator');
            $table->string('previous_customer_number', 60)->nullable()->after('previous_provider');
        });
    }

    public function down(): void {
        Schema::table('contract_energy_details', function (Blueprint $table) {
            $table->dropColumn(['previous_provider', 'previous_customer_number']);
        });
    }
};
