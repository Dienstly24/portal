<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-Mail darf fehlen: Kunden aus Dokumenten/Import, deren echte E-Mail (noch)
 * nicht bekannt ist, bekommen KEINE Platzhalter-Adresse mehr, sondern ein
 * leeres Feld (NULL). So sieht der Mitarbeiter sofort, bei welchem Kunden die
 * echte E-Mail noch nachgetragen werden muss. Der eindeutige Index bleibt -
 * mehrere NULL-Werte sind darin zulaessig (MySQL/SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
