<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Woher stammt die Analyse eines Dokuments: 'ai' (KI-Anbieter, z.B. Claude)
 * oder 'ocr' (kostenlose Tesseract-Basisebene ohne KI). Steuert die
 * Anzeige in der Review-UI (niedrigere Konfidenz bei OCR-Ergebnissen soll
 * fuer Mitarbeiter erkennbar sein) und ist rein informativ - keine
 * Constraint auf andere Tabellen/Spalten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('ai_source', 10)->nullable()->after('ai_confidence');
        });

        // Bereits analysierte Bestandsdokumente liefen ausschliesslich
        // ueber den (bisher einzigen) KI-Anbieter.
        DB::table('documents')->where('ai_status', 'done')->update(['ai_source' => 'ai']);
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('ai_source');
        });
    }
};
