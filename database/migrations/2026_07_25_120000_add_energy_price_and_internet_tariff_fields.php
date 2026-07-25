<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Betreiber-Vorgabe 25.07.2026: Energie- und Internetvertraege brauchen eigene
 * Tarif-Bausteine, die es bei Versicherungen nicht gibt.
 *
 * - Energie (Strom/Gas): Arbeitspreis (ct/kWh) und Grundpreis (EUR/Monat) sind
 *   die beiden Kernpreise eines Energietarifs, getrennt vom Abschlag.
 * - Internet: DSL-Tarife sind fast immer 24 Monate und PREISVARIABEL (z.B. die
 *   ersten Monate guenstiger, danach der regulaere Preis). Dazu Router
 *   (inklusive/Aufpreis), Upload-Geschwindigkeit sowie Bonus/Gutschein, die der
 *   Kunde beim Abschluss erhaelt (Cashback, Router-Gutschrift ...).
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('contract_energy_details', function (Blueprint $table) {
            // Arbeitspreis in ct/kWh (z.B. 28,900), Grundpreis in EUR/Monat.
            $table->decimal('working_price', 8, 3)->nullable()->after('consumption_kwh');
            $table->decimal('base_price', 8, 2)->nullable()->after('working_price');
        });

        Schema::table('contract_internet_details', function (Blueprint $table) {
            // Upload getrennt vom Download (speed bleibt der Download).
            $table->string('upload_speed', 30)->nullable()->after('speed');
            // Preisvariabel: Aktionspreis fuer die ersten N Monate, danach regulaer.
            $table->decimal('price_initial', 8, 2)->nullable()->after('upload_speed');
            $table->unsignedSmallInteger('price_initial_months')->nullable()->after('price_initial');
            $table->decimal('price_regular', 8, 2)->nullable()->after('price_initial_months');
            // Router: inklusive oder mit monatlichem Aufpreis.
            $table->boolean('has_router')->default(false)->after('price_regular');
            $table->string('router_name', 120)->nullable()->after('has_router');
            $table->decimal('router_price', 8, 2)->nullable()->after('router_name');
            // Einmalige Vorteile beim Abschluss (Cashback/Bonus, Gutschein/Gutschrift).
            $table->decimal('bonus_amount', 10, 2)->nullable()->after('router_price');
            $table->decimal('voucher_amount', 10, 2)->nullable()->after('bonus_amount');
        });
    }

    public function down(): void {
        Schema::table('contract_energy_details', function (Blueprint $table) {
            $table->dropColumn(['working_price', 'base_price']);
        });
        Schema::table('contract_internet_details', function (Blueprint $table) {
            $table->dropColumn([
                'upload_speed', 'price_initial', 'price_initial_months', 'price_regular',
                'has_router', 'router_name', 'router_price', 'bonus_amount', 'voucher_amount',
            ]);
        });
    }
};
