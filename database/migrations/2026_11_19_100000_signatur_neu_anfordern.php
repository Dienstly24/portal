<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Erneut zur Unterschrift anfordern" (Betreiber-Meldung 19.11.2026).
 *
 * Ein unterschriebener Vorgang wird NIE geloescht - er ist der Nachweis.
 * Hat der Kunde falsch unterschrieben, entsteht deshalb eine NEUE Anfrage
 * aus demselben Original, und beide kennen einander: die neue zeigt auf
 * die alte (`replaces_id`). Rein ADDITIV, keine bestehende Zeile aendert
 * sich.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->string('replaces_id', 36)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropIndex(['replaces_id']);
            $table->dropColumn('replaces_id');
        });
    }
};
