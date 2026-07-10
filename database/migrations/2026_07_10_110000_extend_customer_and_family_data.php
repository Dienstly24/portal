<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review Punkte 2/5/6/8:
 * - customers: Geburtsort + strukturierte Adresse nach deutschem
 *   Standard (Straße, Hausnummer, Zusatz, PLZ, Ort). Das alte
 *   einzeilige address-Feld bleibt als Fallback/Anzeige erhalten.
 * - customer_family: Geschlecht, RV-Nummer, Steuer-ID (verschlüsselt),
 *   Geburtsort.
 * - customer_addresses: Hausnummer + Zusatz getrennt.
 * - customer_change_requests: old_data/new_data werden auf TEXT
 *   umgestellt und verschlüsselt (encrypted:array-Cast), damit
 *   sensible Werte (KV-/RV-Nummer, Steuer-ID) in Änderungsanträgen
 *   nicht im Klartext liegen. Bestandsdaten werden migriert.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('birth_place')->nullable();
            $table->string('address_street')->nullable();
            $table->string('address_house_number', 10)->nullable();
            $table->string('address_house_suffix', 10)->nullable();
            $table->string('address_zip', 10)->nullable();
            $table->string('address_city')->nullable();
        });

        Schema::table('customer_family', function (Blueprint $table) {
            $table->string('gender', 10)->nullable(); // male|female
            $table->text('pension_insurance_number')->nullable();
            $table->text('tax_id')->nullable();
            $table->string('birth_place')->nullable();
        });

        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->string('house_number', 10)->nullable();
            $table->string('house_number_suffix', 10)->nullable();
        });

        // JSON -> TEXT (Ciphertext ist kein gültiges JSON)
        Schema::table('customer_change_requests', function (Blueprint $table) {
            $table->text('old_data')->nullable()->change();
            $table->text('new_data')->nullable()->change();
        });

        // Bestehende Klartext-JSON-Payloads verschlüsseln.
        // encrypted:array speichert Crypt::encryptString(json_encode(...)),
        // der vorhandene Rohwert IST bereits das json_encode-Ergebnis.
        foreach (DB::table('customer_change_requests')->cursor() as $row) {
            $update = [];
            foreach (['old_data', 'new_data'] as $col) {
                $raw = $row->$col;
                if ($raw === null || str_starts_with($raw, 'eyJpdiI6')) {
                    continue; // leer oder bereits verschlüsselt
                }
                $update[$col] = Crypt::encryptString($raw);
            }
            if ($update) {
                DB::table('customer_change_requests')->where('id', $row->id)->update($update);
            }
        }
    }

    public function down(): void {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['birth_place','address_street','address_house_number','address_house_suffix','address_zip','address_city']);
        });
        Schema::table('customer_family', function (Blueprint $table) {
            $table->dropColumn(['gender','pension_insurance_number','tax_id','birth_place']);
        });
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->dropColumn(['house_number','house_number_suffix']);
        });
        // Verschlüsselte Payloads werden bewusst nicht zurückgewandelt.
    }
};
