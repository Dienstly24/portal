<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Betreiber-Vorgabe 29.07.2026: Ein Vertrag entsteht im Alltag in ZWEI
 * Schritten. Zuerst kommt der AUFTRAG/ANTRAG (z.B. der EWE-Strom-Auftrag oder
 * ein Versicherungs-Antrag) - er traegt bereits viele Daten, aber noch KEINE
 * Bestaetigung. Erst spaeter folgt der eigentliche VERTRAG (Vertrags-
 * bestaetigung/Police) mit Vertragsnummer, Kundennummer, endgueltigem Beginn
 * und Abschlag.
 *
 * contracts.stage haelt fest, in welchem dieser beiden Schritte ein Vertrag
 * steht:
 *   'antrag'  = aus einem Auftrag/Antrag entstanden, wartet auf die
 *               Vertragsbestaetigung (nur solche Vertraege duerfen von einem
 *               spaeteren Bestaetigungs-Dokument ergaenzt werden)
 *   'vertrag' = bestaetigt (Police/Vertragsbestaetigung liegt vor)
 *   null      = Altbestand / manuell angelegt - bleibt bewusst unberuehrt von
 *               der Automatik.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('stage', 20)->nullable()->after('status');
            $table->index(['customer_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'stage']);
            $table->dropColumn('stage');
        });
    }
};
