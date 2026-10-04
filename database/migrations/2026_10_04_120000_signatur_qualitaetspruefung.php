<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signatur-Qualitaetsgate (Betreiber-Auftrag 04.10.2026, Teil A).
 *
 * Rein ADDITIV - keine bestehende Zeile wird geaendert:
 *  - feld_bezug: auf welche Seitenflaeche sich die gespeicherten
 *    Feldanteile beziehen. Der Bestand bleibt "mediabox" (so wurden seine
 *    Felder gesetzt, eine Umrechnung waere ein Risiko ohne Gewinn); neue
 *    Anfragen bekommen "cropbox" - die Flaeche, die JEDER Betrachter zeigt.
 *  - upload_original_*: die hochgeladene Datei, wenn die Eingangspruefung
 *    sie reparieren musste. original_* ist dann die reparierte Basis.
 *  - quality_*: Ergebnis der letzten Qualitaetspruefung (Selbsttest beim
 *    Abschluss oder Nachtlauf) - Grundlage der Liste in der Beraterwelt.
 *  - render_ms: Dauer der letzten Erzeugung (Ueberwachung).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->string('feld_bezug', 10)->default('mediabox')->after('page_count');
            $table->string('upload_original_path')->nullable()->after('original_size');
            $table->string('upload_original_hash', 64)->nullable()->after('upload_original_path');
            $table->string('quality_status', 20)->nullable()->after('signed_size');
            $table->timestamp('quality_checked_at')->nullable()->after('quality_status');
            $table->json('quality_findings')->nullable()->after('quality_checked_at');
            $table->unsignedInteger('render_ms')->nullable()->after('quality_findings');
            $table->index('quality_status');
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropIndex(['quality_status']);
            $table->dropColumn([
                'feld_bezug', 'upload_original_path', 'upload_original_hash',
                'quality_status', 'quality_checked_at', 'quality_findings', 'render_ms',
            ]);
        });
    }
};
