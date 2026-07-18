<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vertragsverlauf je Kunde und Sparte (Betreiber-Vorgabe: fuer ALLE Sparten
 * wichtig - Kfz, Strom, Gas, Kranken ...). Haelt fest, bei welchem
 * Versicherer/Versorger der Kunde in welchem Zeitraum war und warum gewechselt
 * wurde. So bleibt beim naechsten Wechsel klar, "wo der Kunde ueber die Jahre
 * war".
 *
 * Bewusst schlanke, abfragbare Spalten (kein PII-Schwerpunkt: Sparte, Anbieter,
 * Zeitraum, Grund).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->uuid('contract_id')->nullable();
            $table->string('branch');                 // Sparte: kfz, strom, gas, kranken ...
            $table->string('provider')->nullable();   // Versicherer/Versorger/Krankenkasse
            $table->string('role')->nullable();       // z.B. hauptversichert | familienversichert (Kranken)
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable(); // null = laufend
            $table->string('reason')->nullable();     // initial | wechsel | sonder | new_job ...
            $table->uuid('source_document_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('contract_id')->references('id')->on('contracts')->nullOnDelete();
            $table->index(['customer_id', 'branch']);
            $table->index('contract_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_histories');
    }
};
