<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duplikat-Erkennung: jede Dokumentdatei bekommt eine SHA-256-Bestimmung
 * ihres Inhalts (content_hash). Wird dieselbe Datei erneut hochgeladen -
 * z.B. eine Woche spaeter -, erkennt das System sie am identischen Hash und
 * merkt sie sich als Duplikat des zuerst hochgeladenen Dokuments
 * (duplicate_of). Der Eingang warnt dann prominent, und die (kostenpflichtige)
 * KI-Analyse wird uebersprungen (das vorhandene Ergebnis wird uebernommen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->after('file_size')->index();
            // Verweist auf documents.id des zuerst hochgeladenen, inhaltsgleichen
            // Dokuments. Bewusst OHNE Fremdschluessel: wird das Original geloescht,
            // darf der Verweis folgenlos ins Leere zeigen (Relation liefert null).
            $table->uuid('duplicate_of')->nullable()->after('content_hash')->index();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['content_hash', 'duplicate_of']);
        });
    }
};
