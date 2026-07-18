<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produktionsfehler: documents.ai_extracted lag in der Produktions-DB noch als
 * JSON-Spalte vor (aus einer frueheren Migrationsversion), der Cast ist aber
 * 'encrypted:array' - der verschluesselte String ist KEIN gueltiges JSON, MySQL
 * lehnt jeden Schreibvorgang mit SQLSTATE[22032] "Invalid JSON text" ab. Dadurch
 * schlug JEDE Dokument-Analyse beim Speichern fehl (Dokument blieb in
 * 'processing' -> Sicherheitsnetz meldete faelschlich "Zeitueberschreitung").
 *
 * Wie schon bei ai_summary und ai_decisions.output: Spalte auf TEXT umstellen,
 * damit der Ciphertext hineinpasst. Auf frischen Installationen (Spalte bereits
 * TEXT) ist dies ein harmloser No-Op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->text('ai_extracted')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Bewusst KEIN Zurueckdrehen auf JSON: das wuerde den identischen
        // Produktionsfehler wieder einfuehren (Ciphertext ist kein JSON).
    }
};
