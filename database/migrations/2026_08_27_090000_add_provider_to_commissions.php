<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WOHER stammt diese Provision? (Betreiber-Vorgabe 26.08.2026: "wir haben
 * mehr als einen Vermittler, mehr als ein Portal und mehr als eine Dateiart")
 *
 * Bisher stand die Herkunft nur als DATEINAME an der Provision. Das genuegt
 * fuer einen einzelnen Lauf, aber nicht fuer die Frage, die der Betrieb
 * wirklich stellt: "Was hat uns der Maklerpool dieses Jahr gebracht, was das
 * Vergleichsportal?" Ein Dateiname ist dafuer kein Schluessel - er aendert
 * sich mit jedem Export.
 *
 * Der Wert ist bewusst ein einfacher Text und KEIN Fremdschluessel auf eine
 * Quellen-Tabelle: eine neue Quelle darf entstehen, ohne dass jemand vorher
 * einen Stammdatensatz anlegt. Unbekannte Quellen bleiben schlicht leer.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('commission_imports', function (Blueprint $table) {
            $table->string('provider', 60)->nullable()->after('mode');
        });

        Schema::table('contract_commissions', function (Blueprint $table) {
            $table->string('provider', 60)->nullable()->after('source_file');
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::table('contract_commissions', function (Blueprint $table) {
            $table->dropIndex(['provider']);
            $table->dropColumn('provider');
        });
        Schema::table('commission_imports', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
