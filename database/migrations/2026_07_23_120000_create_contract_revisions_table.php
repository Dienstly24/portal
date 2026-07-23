<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feld-genaue Aenderungshistorie (Audit Log / Version History) je Vertrag.
 * Betreiber-Vorgabe (23.07.2026): Wird ein neues Dokument fuer einen bereits
 * bestehenden Vertrag importiert, entsteht KEIN Duplikat mehr - der vorhandene
 * Vertrag wird aktualisiert und jede geaenderte Angabe hier festgehalten:
 * welches Feld, alter Wert, neuer Wert, wann, durch wen (Mitarbeiter oder
 * System) und aus welchem Dokument.
 *
 * So bleibt genau EIN Vertrag je Fahrzeug (Single Source of Truth) sichtbar,
 * mit vollstaendigem Verlauf statt mehrerer Vertraege fuer dasselbe Auto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_id');
            // Aenderungen aus EINEM Vorgang (z.B. ein importiertes Dokument)
            // teilen sich eine batch_id -> in der UI als ein Ereignis gruppierbar.
            $table->uuid('batch_id')->nullable();
            $table->string('field');                 // Maschinen-Schluessel, z.B. premium_amount
            $table->string('label')->nullable();     // Anzeigename, z.B. "Beitrag"
            $table->text('old_value')->nullable();   // bereits fuer die Anzeige formatiert
            $table->text('new_value')->nullable();
            // Woher stammt die Aenderung: document | manual | import | system
            $table->string('source')->default('system');
            $table->uuid('source_document_id')->nullable();
            // Wer hat geaendert (users.id). null = automatisch durch das System.
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();

            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
            $table->index(['contract_id', 'created_at']);
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_revisions');
    }
};
