<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vertragsrollen (PR-6 des Dubletten-/Familien-Plans, 08.10.2026).
 *
 * Der VERSICHERUNGSNEHMER bleibt contracts.customer_id - daran haengen
 * Portfolio, Provision, Portal und jede Kennzahl, und das bleibt so.
 * Hier stehen die WEITEREN Personen eines Vertrags: versicherte Personen
 * (mehrere), ein abweichender Beitragszahler (hoechstens einer) und
 * Beguenstigte (mit optionalem Anteil).
 *
 * customer_id ist NULLBAR: eine versicherte Person braucht keine eigene
 * Kundenakte (Kind in der Krankenversicherung, Ehepartner in der
 * Risiko-LV). `name` ist deshalb die Kopie, die bleibt - auch wenn die
 * verknuepfte Akte spaeter geloescht wird (nullOnDelete).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('vertrag_beteiligte', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('rolle', 30);
            $table->string('name', 160);
            $table->date('geburtsdatum')->nullable();
            $table->decimal('anteil_prozent', 5, 2)->nullable();
            $table->string('notiz', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Dieselbe Akte nie zweimal in derselben Rolle. Eintraege OHNE
            // Akte (customer_id NULL) faellt der Index bewusst nicht -
            // zwei Kinder ohne eigene Akte sind zwei Personen.
            $table->unique(['contract_id', 'customer_id', 'rolle']);
            $table->index(['customer_id', 'rolle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vertrag_beteiligte');
    }
};
