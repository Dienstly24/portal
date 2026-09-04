<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referenz-/Vorgangsnummer am Vertrag (Betreiber-Vorgabe 17.08.2026).
 *
 * Ein ANTRAG traegt noch keine Vertragsnummer - aber fast jedes Portal
 * vergibt eine eigene Kennung (Referenznummer der Antragsstrecke,
 * Auftragsnummer des Energieportals, Vorgangsnummer des Maklerpools,
 * Protokoll-Nr. des Vergleichsrechners). Genau ueber diese Nummer laesst
 * sich spaeter nachvollziehen, welcher Vertrag bestaetigt wurde: die
 * Provisions-/Abrechnungsdatei der Gesellschaft nennt sie, und ein
 * hochgeladenes Dokument findet damit Vertrag UND Kunde.
 *
 * Bewusst NICHT unique: dieselbe Nummer kann in Ausnahmefaellen an zwei
 * Vertraegen desselben Vorgangs stehen (z.B. Buendel Strom + Gas); ein
 * harter Unique-Index wuerde das Speichern blockieren. Der Index dient der
 * schnellen Suche.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('reference_number', 60)->nullable()->after('contract_number');
            $table->index('reference_number');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex(['reference_number']);
            $table->dropColumn('reference_number');
        });
    }
};
