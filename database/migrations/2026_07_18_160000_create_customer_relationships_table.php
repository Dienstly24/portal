<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Verwandte Kunden": Ein als "Kein Duplikat" markiertes Paar ist keine
 * Dublette, sondern eine bestaetigte BEZIEHUNG (z. B. Familie/Haushalt mit
 * gleicher Anschrift oder Telefon). Solche Paare verschwinden aus der
 * Dubletten-Liste und erscheinen stattdessen unter "Verwandte Kunden".
 *
 * Gespeichert wird das Paar in fester Reihenfolge (customer_a_id <
 * customer_b_id), damit es genau einmal existiert - unabhaengig davon, von
 * welcher Seite es markiert wurde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_relationships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_a_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUuid('customer_b_id')->constrained('customers')->cascadeOnDelete();
            // Art der Beziehung - vorerst nur "not_duplicate" (aus der
            // Dubletten-Pruefung als "kein Duplikat" markiert). Spaeter
            // erweiterbar (z. B. "family", "household").
            $table->string('type')->default('not_duplicate');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['customer_a_id', 'customer_b_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_relationships');
    }
};
