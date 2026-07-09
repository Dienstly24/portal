<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Strukturierte Vertragsdetails (Spec Teil 4/5), je Sparte eine
 * eigene Detailtabelle 1:1 am Vertrag:
 * - KFZ: Fahrzeug, SF-Klassen (Haftpflicht/Vollkasko getrennt),
 *   Schäden als strukturierte JSON-Liste [{month, year, type}]
 * - Energie: MaLo-ID bewusst als EIGENES Feld, getrennt von der
 *   Zählernummer (Spec-Anforderung)
 * - Internet: Tarif + Geschwindigkeit
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('contract_vehicle_details', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_id')->unique();
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
            $table->string('license_plate', 20)->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('vehicle_type', 50)->nullable();
            $table->string('vin', 30)->nullable(); // FIN / Fahrgestellnummer
            $table->date('first_registration')->nullable();
            $table->boolean('has_claims')->default(false);
            $table->json('claims')->nullable(); // [{month:int, year:int, type:haftpflicht|vollkasko|teilkasko}]
            $table->string('sf_liability_class', 10)->nullable();
            $table->unsignedSmallInteger('sf_liability_year')->nullable();
            $table->string('sf_comprehensive_class', 10)->nullable();
            $table->unsignedSmallInteger('sf_comprehensive_year')->nullable();
            $table->timestamps();
        });

        Schema::create('contract_energy_details', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_id')->unique();
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
            $table->string('tariff')->nullable();
            $table->unsignedInteger('consumption_kwh')->nullable();
            $table->string('meter_number', 60)->nullable();
            $table->string('malo_id', 11)->nullable(); // Marktlokations-ID, SEPARAT von der Zählernummer
            $table->string('meter_reading', 30)->nullable();
            $table->string('grid_operator')->nullable();
            $table->string('metering_operator')->nullable();
            $table->timestamps();
        });

        Schema::create('contract_internet_details', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_id')->unique();
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
            $table->string('tariff')->nullable();
            $table->string('speed', 30)->nullable(); // z.B. "250 Mbit/s"
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('contract_internet_details');
        Schema::dropIfExists('contract_energy_details');
        Schema::dropIfExists('contract_vehicle_details');
    }
};
