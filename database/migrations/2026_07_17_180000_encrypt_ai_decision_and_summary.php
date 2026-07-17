<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Haertung nach Sicherheits-Review: ai_decisions.output (Dokument-
 * Analysen enthalten Name/Kundennummer eines Matches, KI-Zusammen-
 * fassung) und documents.ai_summary werden verschluesselt gespeichert -
 * gleiche Schutzstufe wie documents.ai_extracted. json-Spalten koennen
 * keine verschluesselten Strings aufnehmen (auf MySQL schlaegt das mit
 * "Invalid JSON text" fehl), daher zuerst auf text umstellen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_decisions', function (Blueprint $table) {
            $table->text('output')->change();
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->text('ai_summary')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('ai_summary', 500)->nullable()->change();
        });
        Schema::table('ai_decisions', function (Blueprint $table) {
            $table->json('output')->change();
        });
    }
};
