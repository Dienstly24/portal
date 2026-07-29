<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arbeitgeber des Kunden (Name + Anschrift) - z.B. aus dem hochgeladenen
 * Arbeitsvertrag gelesen (Dokumenten-Eingang) oder von Hand gepflegt.
 * Ergaenzt das bestehende Feld `occupation` (Beruf).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('employer_name', 150)->nullable()->after('occupation');
            $table->string('employer_address', 200)->nullable()->after('employer_name');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['employer_name', 'employer_address']);
        });
    }
};
