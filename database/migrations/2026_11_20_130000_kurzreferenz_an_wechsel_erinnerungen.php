<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kurzreferenz je Erinnerung (z.B. "R-7K3Q9X"): steht im WhatsApp-Text
 * der Erinnerung, damit das Team den Vertrag findet, OHNE dass die
 * Vertragsnummer durch WhatsApp geht. Rein ergaenzend, nullbar - der
 * Bestand bleibt unveraendert.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contract_switch_reminders', function (Blueprint $table) {
            $table->string('kurzreferenz', 16)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('contract_switch_reminders', function (Blueprint $table) {
            $table->dropUnique(['kurzreferenz']);
            $table->dropColumn('kurzreferenz');
        });
    }
};
