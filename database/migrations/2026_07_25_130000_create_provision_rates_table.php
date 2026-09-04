<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provisions-Saetze je SPARTE (Provisions-Management, Betreiber-Vorgabe
 * 25.07.2026): Jeder Mitarbeiter und jeder Partner kann je Produkt/Sparte
 * einen EIGENEN Satz haben (z.B. GKV 50 EUR fuer Mitarbeiter A, 40 EUR fuer
 * Mitarbeiter B, 60 EUR fuer Partner X). Empfaenger ist GENAU EINER von
 * beiden (user_id ODER partner_id). Beide Wertarten optional kombinierbar:
 * - amount_fixed:   fester EUR-Betrag je Neuvertrag der Sparte
 * - amount_percent: Prozent vom Jahresbeitrag des Neuvertrags
 * Fehlt ein Sparten-Satz, greift als Fallback der globale Satz am
 * Mitarbeiter/Partner (users.provision_fixed/-percent bzw. partners.*).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('provision_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Empfaenger: Mitarbeiter ...
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // ... oder Vertriebspartner
            $table->uuid('partner_id')->nullable();
            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
            // Sparten-Schluessel aus Contract::TYPES (kfz, krankenversicherung, strom ...)
            $table->string('contract_type', 60);
            $table->decimal('amount_fixed', 8, 2)->nullable();
            $table->decimal('amount_percent', 5, 2)->nullable();
            $table->timestamps();

            // Je Empfaenger und Sparte genau EIN Satz.
            $table->unique(['user_id', 'contract_type']);
            $table->unique(['partner_id', 'contract_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provision_rates');
    }
};
