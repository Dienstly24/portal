<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Betreiber-Vorgabe 20.11.2026: die kostenpflichtige KI-Analyse laeuft nur
 * noch nach Freigabe. Reicht die kostenlose Stufe nicht, merkt sich das
 * Dokument "KI empfohlen - wartet auf Freigabe", statt die KI selbst
 * aufzurufen.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('ki_freigabe_offen')->default(false)->after('ai_source');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('ki_freigabe_offen');
        });
    }
};
