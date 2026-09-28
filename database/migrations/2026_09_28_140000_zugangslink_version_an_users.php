<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KI-043 (Nachpruefung vor dem Merge von PR #358): ein Zaehler, der alle
 * bis dahin ausgestellten Zugangslinks eines Kontos (Magic-Login,
 * Passwort-Setzen-Einladung) auf einen Schlag entwertet.
 *
 * Jeder neue Link traegt den aktuellen Stand als signierten Parameter `v`;
 * gilt nur, solange er dem Stand am Konto entspricht. Erhoeht wird er bei
 * Aenderung der Login-Adresse, bei einem von der Verwaltung gesetzten
 * Passwort, beim Portal-Reset und bei jeder neu verschickten Einladung.
 *
 * Bewusst ein ZAEHLER und kein Zeitstempel "ungueltig ab": ein Zeitstempel
 * vergleicht Sekunden und laesst einen Link gelten, der in derselben
 * Sekunde VOR dem Widerruf entstand - bei zweimal "Einladung senden" genau
 * der Fall, um den es geht. Ein Zaehler ist exakt und haengt an keiner Uhr.
 *
 * Voreinstellung 0: bereits verschickte Links tragen kein `v` und zaehlen
 * als 0 - sie gelten nach dem Deployment unveraendert weiter, bis zum
 * ersten Widerruf an ihrem Konto.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('zugangslink_version')->default(0)->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('zugangslink_version');
        });
    }
};
