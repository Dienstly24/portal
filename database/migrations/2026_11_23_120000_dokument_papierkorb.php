<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Papierkorb fuer Dokumente, die als DOPPELT erkannt und entfernt wurden
 * (Betreiber-Auftrag 23.11.2026, KI-128).
 *
 * Bewusst eine EIGENE Tabelle statt einer Spalte "geloescht_am" an
 * documents: an rund 40 Stellen wird documents abgefragt (Eingang, Akte,
 * Portal, KI-Analyse, Kundenloeschung ...). Eine Spalte haette jede dieser
 * Abfragen um eine Bedingung erweitert - und die naechste neue Abfrage
 * haette sie vergessen. Hier verlaesst die Zeile documents; die Daten
 * stehen verschluesselt in `daten`, die Datei liegt unter
 * customers/{id}/papierkorb/ (die Kundenloeschung raeumt den Ordner mit).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('dokument_papierkorb', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            // Kennung des entfernten Dokuments - beim Wiederherstellen
            // entsteht es unter DERSELBEN Kennung.
            $table->uuid('document_id')->unique();
            // Das Dokument, das behalten wurde (kein Fremdschluessel: wird es
            // spaeter geloescht, bleibt der Papierkorb-Eintrag gueltig).
            $table->uuid('behalten_document_id')->nullable();
            $table->string('file_name');
            $table->string('disk', 32);
            $table->string('file_path');
            $table->text('daten'); // verschluesselt (encrypted:array)
            $table->string('grund', 64);
            $table->foreignId('entfernt_von')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entfernt_at');
            $table->timestamp('endgueltig_ab')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dokument_papierkorb');
    }
};
