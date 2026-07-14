<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Energievertraege (Strom/Gas) fuehren eine Kundennummer beim
 * Energieanbieter - getrennt von der Vertragsnummer (die weiter am Vertrag
 * selbst, Feld contracts.contract_number, haengt). Betreiber-Vorgabe
 * 14.07.2026: statt einer Versicherungsnummer (VSNR) haben Energievertraege
 * Vertragsnummer UND Kundennummer.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contract_energy_details', function (Blueprint $table) {
            if (!Schema::hasColumn('contract_energy_details', 'customer_number')) {
                $table->string('customer_number', 60)->nullable()->after('meter_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contract_energy_details', function (Blueprint $table) {
            if (Schema::hasColumn('contract_energy_details', 'customer_number')) {
                $table->dropColumn('customer_number');
            }
        });
    }
};
