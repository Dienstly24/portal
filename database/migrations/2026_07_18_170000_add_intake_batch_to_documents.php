<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gemeinsame Hochlade-Kennung fuer Eingangs-Dokumente. Werden mehrere
 * Dateien (Ausweis + Bankkarte + Fuehrerschein + Protokoll) in EINEM Upload
 * in den Dokumenten-Eingang gelegt, teilen sie sich diese Kennung. Der
 * Eingang gruppiert sie dann als EINEN Vorgang und bietet "Neuen Kunden aus
 * allen anlegen" (zusammengefuehrte Extraktion) an.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('intake_batch')->nullable()->after('customer_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('intake_batch');
        });
    }
};
