<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Der Portal-Kanal heisst im Postfach "Kundenchat".
 *
 * Seit die Kanal-Punkte aus der DATENBANK kommen, ist der Name der Zeile
 * auch die Beschriftung in der Seitenleiste. "Kundenportal" beschreibt
 * dort den falschen Gegenstand: der Mitarbeiter oeffnet keinen Ort,
 * sondern die Unterhaltungen aus dem Portal - und genau dafuer ist
 * "Kundenchat" seit jeher das eingefuehrte Wort im Haus.
 *
 * Nur die Beschriftung. Der Schluessel `portal` bleibt, an ihm haengen
 * Adapter, Unterhaltungen und Tests.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('channels')->where('key', 'portal')->update(['name' => 'Kundenchat']);
    }

    public function down(): void
    {
        DB::table('channels')->where('key', 'portal')->update(['name' => 'Kundenportal']);
    }
};
