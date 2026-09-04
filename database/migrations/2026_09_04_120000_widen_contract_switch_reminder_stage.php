<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `contract_switch_reminders.stage` war auf 10 Zeichen ausgelegt - passend zu
 * den urspruenglichen Werten "first" und "followup".
 *
 * Spaeter kamen laengere Kennungen dazu, und eine davon passt nicht mehr:
 * "schutzbrief_renewal" hat 19 Zeichen. Auf SQLITE faellt das nicht auf, weil
 * SQLite Laengenangaben bei VARCHAR ignoriert - genau deshalb ist es nie
 * jemandem aufgefallen. MySQL lehnt den Datensatz dagegen ab (SQLSTATE 22001,
 * "Data too long").
 *
 * WAS DAS IN PRODUKTION BEDEUTET HAT: der Eintrag wird GESCHRIEBEN, BEVOR die
 * E-Mail rausgeht (SchutzbriefRenewalReminderService: erst create(), dann
 * Mail::send). Der Insert scheitert also, die Ausnahme wird je Vertrag
 * abgefangen - und die Schutzbrief-Erinnerung wurde nie verschickt. Still,
 * jeden Tag aufs Neue.
 *
 * 32 Zeichen statt 19, damit die naechste Kennung nicht wieder ansteht.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('contract_switch_reminders')) {
            return;
        }

        Schema::table('contract_switch_reminders', function (Blueprint $table) {
            $table->string('stage', 32)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('contract_switch_reminders')) {
            return;
        }

        // Bewusst NICHT auf 10 zurueck: vorhandene Zeilen mit
        // "schutzbrief_renewal" wuerden dabei abgeschnitten oder die
        // Migration scheitern. Zurueck geht es nur auf eine Laenge, die
        // jeden bereits gespeicherten Wert noch aufnimmt.
        Schema::table('contract_switch_reminders', function (Blueprint $table) {
            $table->string('stage', 32)->change();
        });
    }
};
