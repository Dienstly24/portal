<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Unterschriebenes Dokument ergaenzen" (Betreiber-Meldung 19.11.2026).
 *
 * Ein zweiter Kunde soll auf demselben Papier unterschreiben, eine Stelle
 * wurde vergessen, ein Textfeld fehlt. Das unterschriebene PDF wird NIE
 * veraendert - es entsteht eine NEUE Anfrage, deren Basis das
 * unterschriebene Dokument ist. `ergaenzt_id` zeigt auf den Vorgang, der
 * fortgeschrieben wird. Bewusst NICHT `replaces_id`: der alte Vorgang wird
 * nicht ersetzt, er bleibt gueltig und ist Teil des neuen Dokuments.
 * Rein ADDITIV.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->string('ergaenzt_id', 36)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropIndex(['ergaenzt_id']);
            $table->dropColumn('ergaenzt_id');
        });
    }
};
