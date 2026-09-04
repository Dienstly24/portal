<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ein Dokument im Eingang kann eine VORGANGSLISTE des Vermittlers sein
 * (Lehre 21.08.2026). Wird sie von dort aus eingelesen, haelt diese Spalte
 * fest, ZU WELCHEM Lauf sie gehoert.
 *
 * Warum ueberhaupt eine Spalte: ohne sie bliebe die Liste fuer immer unter
 * "Nicht zugeordnet" stehen - sie gehoert ja zu keinem Kunden. Der
 * Mitarbeiter saehe eine Daueraufgabe, die keine ist. Mit der Spalte
 * wandert sie nach dem Einlesen in einen eigenen, ruhigen Abschnitt und
 * bleibt trotzdem auffindbar (die Datei wird NIE automatisch geloescht).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignUuid('vermittler_import_id')->nullable()
                ->after('ai_processed_at')
                ->constrained('vermittler_imports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vermittler_import_id');
        });
    }
};
