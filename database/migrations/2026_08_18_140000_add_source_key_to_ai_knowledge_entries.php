<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Herkunft eines Wissensbasis-Eintrags (Betreiber-Auftrag 18.08.2026).
 *
 * Der Befehl ki:wissensbasis-vorschlag erzeugt Entwuerfe aus Inhalten, die
 * im System bereits gepflegt sind (Leistungsseiten der Website). Damit ein
 * zweiter Lauf nichts doppelt anlegt und damit ein Mitarbeiter sieht, WOHER
 * ein Eintrag stammt, traegt jeder erzeugte Eintrag seine Quelle.
 *
 * Von Hand angelegte Eintraege bleiben ohne Quelle (null) - sie gehoeren
 * dem Menschen und werden von keinem Befehl angefasst.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_knowledge_entries', function (Blueprint $table) {
            $table->string('source_key', 190)->nullable()->after('keywords');
            $table->index('source_key');
        });
    }

    public function down(): void
    {
        Schema::table('ai_knowledge_entries', function (Blueprint $table) {
            $table->dropIndex(['source_key']);
            $table->dropColumn('source_key');
        });
    }
};
