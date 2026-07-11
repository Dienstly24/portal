<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generische externe Kennungen (Architekturplan Abschnitt 7):
 * Fonds-Finanz-Nummern, externe Vertragsnummern, Partner-IDs usw.
 * Polymorph an Customer/Contract (später Partner) - EINE Tabelle statt
 * Spalten pro Anbieter, erweiterbar ohne erneute Schemaänderung
 * (z. B. Affiliate-Codes später nur als neuer type-Wert).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Falls ein früherer, an dieser Migration abgebrochener Deploy die
        // Tabelle bereits ohne Unique-Index angelegt hat (MySQL rollt DDL nicht
        // zurück), sauber neu aufsetzen. Beim Erst-Deploy ist die Tabelle noch
        // leer, es gehen keine Daten verloren; nach erfolgreichem Lauf wird die
        // Migration als erledigt vermerkt und nie wieder ausgeführt.
        Schema::dropIfExists('external_references');

        Schema::create('external_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Explizite Längen: der zusammengesetzte Unique-Index unten muss unter
            // MySQLs Grenze von 3072 Bytes bleiben (utf8mb4 = 4 Byte/Zeichen).
            // 191 + 36 (UUID) + 100 + 191 = 518 Zeichen ≈ 2072 Byte < 3072.
            $table->string('referenceable_type', 191);
            $table->uuid('referenceable_id');
            $table->string('type', 100);
            $table->string('value', 191);
            $table->string('source')->nullable();
            $table->timestamps();

            $table->index(['referenceable_type', 'referenceable_id']);
            $table->index(['type', 'value']);
            // Dieselbe Kennung nicht doppelt an derselben Entität
            $table->unique(['referenceable_type', 'referenceable_id', 'type', 'value'], 'external_refs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_references');
    }
};
