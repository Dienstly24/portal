<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generische externe Kennungen (Architekturplan Abschnitt 7):
 * Fonds-Finanz-Nummern, externe Vertragsnummern, Partner-IDs usw.
 * Polymorph an Customer/Contract (später Partner) - EINE Tabelle statt
 * Spalten pro Anbieter, erweiterbar ohne erneute Schemaänderung
 * (z. B. Affiliate-Codes später nur als neuer type-Wert).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('referenceable_type');
            $table->uuid('referenceable_id');
            $table->string('type');
            $table->string('value');
            $table->string('source')->nullable();
            $table->timestamps();

            $table->index(['referenceable_type', 'referenceable_id']);
            $table->index(['type', 'value']);
            // Dieselbe Kennung nicht doppelt an derselben Entität
            $table->unique(['referenceable_type', 'referenceable_id', 'type', 'value'], 'external_refs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_references');
    }
};
