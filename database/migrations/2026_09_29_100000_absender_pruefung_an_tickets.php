<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KI-033: Anfragen aus OEFFENTLICHEN Formularen werden weiterhin per
 * E-Mail-Adresse einer Kundenakte zugeordnet - aber als "Absender nicht
 * verifiziert". Eine Adresse kennt jeder, der einmal eine Mail des Kunden
 * gesehen hat; sie belegt nicht, dass der Kunde selbst geschrieben hat.
 *
 * `absender_status`: NULL = keine Frage offen (Portal, Mitarbeiter, KI,
 * Hilfe-Formular mit Token aus der Willkommensmail, Altbestand),
 * 'ungeprueft' = nur per E-Mail zugeordnet, 'bestaetigt' = ein Mitarbeiter
 * hat den Kunden als Absender bestaetigt. Bewusst nullable und ohne
 * Nachtrag: ob ein ALTER Formular-Vorgang ueber das Token kam, steht
 * nirgends - ihn nachtraeglich zu markieren hiesse raten.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('absender_status', 20)->nullable()->after('source');
            $table->foreignId('absender_geprueft_von')->nullable()->after('absender_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('absender_geprueft_am')->nullable()->after('absender_geprueft_von');
            // Kein eigener Index: gefiltert wird immer innerhalb EINES Kunden
            // (tickets_customer_idx), die Spalte selbst trennt kaum.
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('absender_geprueft_von');
            $table->dropColumn(['absender_status', 'absender_geprueft_am']);
        });
    }
};
