<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Sprache des UNTERZEICHNERS - bewusst am Unterzeichner, nicht an der
 * Anfrage: ein Vorgang kann einen deutschen Kunden und einen arabisch
 * sprechenden Zeugen tragen, und jeder soll seine eigene Einladung und
 * seine eigene Seite in seiner Sprache bekommen.
 *
 * Sie ist auch NICHT die Portal-Sprache des Kunden: die steht in der
 * Kundenakte und gehoert dem Kunden. Hier wird nur vorgeschlagen, was dort
 * steht - wer sie fuer diesen einen Vorgang anders waehlt, aendert damit
 * nie den Wert im CRM.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('signature_signers', function (Blueprint $table) {
            $table->string('locale', 5)->default('de')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('signature_signers', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
