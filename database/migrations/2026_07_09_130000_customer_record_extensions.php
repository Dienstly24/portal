<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kundenakte-Erweiterung (Spec Teil 1/3):
 * - Anrede (Herr/Frau/Divers/Firma) für Korrespondenz
 * - Kranken-/Renten-/Steuerdaten am Kunden (sensibel -> verschlüsselte
 *   Eloquent-Casts im Model, siehe Customer::casts)
 * - KV-Daten je Familienmitglied inkl. Familienversicherungs-Status
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('salutation', 10)->nullable(); // herr|frau|divers|firma
            // Verschlüsselte Felder brauchen TEXT (Ciphertext > 255 Zeichen möglich)
            $table->text('health_insurance_number')->nullable();
            $table->string('health_insurance_company')->nullable();
            $table->string('health_insurance_type', 20)->nullable(); // gesetzlich|privat
            $table->text('pension_insurance_number')->nullable();
            $table->text('tax_id')->nullable();
        });

        Schema::table('customer_family', function (Blueprint $table) {
            $table->string('health_insurance_status', 30)->nullable(); // mitglied|familienversichert
            $table->string('health_insurance_company')->nullable();
            $table->text('health_insurance_number')->nullable();
            $table->date('health_insurance_start')->nullable();
        });
    }

    public function down(): void {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['salutation','health_insurance_number','health_insurance_company','health_insurance_type','pension_insurance_number','tax_id']);
        });
        Schema::table('customer_family', function (Blueprint $table) {
            $table->dropColumn(['health_insurance_status','health_insurance_company','health_insurance_number','health_insurance_start']);
        });
    }
};
