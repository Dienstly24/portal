<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pro Leistungsseite konfigurierbare, zusaetzliche Formularfelder (z. B. bei
 * der Kfz-Versicherung: Fahrzeug, gewuenschte Deckung, Erstzulassung). Als
 * JSON, damit die Felder vollstaendig ueber die Adminoberflaeche pflegbar
 * sind. Die Antworten werden an die Ticketbeschreibung angehaengt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_pages', function (Blueprint $table) {
            $table->json('fields')->nullable()->after('faq');
        });
    }

    public function down(): void
    {
        Schema::table('service_pages', function (Blueprint $table) {
            $table->dropColumn('fields');
        });
    }
};
