<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contracts.subtype war string(10) - angelegt fuer gkv/pkv. Inzwischen gibt
 * es laengere Untergruppen-Schluessel: 'auslandskranken' (Krankenzusatz, 15
 * Zeichen) passte auf MySQL im Strict Mode NICHT mehr hinein ("Data too
 * long"); die Tests (SQLite) haben das nicht bemerkt. Mit der neuen Sparte
 * Schutzbrief/Mobilclub (Stufen basis/plus/premium) wird die Spalte auf 40
 * Zeichen verbreitert - gleiche Weite wie contracts.type, die Whitelist
 * bleibt im Code (Contract::SUBTYPES).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('subtype', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Bewusst keine Rueckkehr zu string(10): das wuerde Bestandsdaten
        // (z.B. subtype 'auslandskranken') abschneiden bzw. den Change
        // fehlschlagen lassen.
    }
};
