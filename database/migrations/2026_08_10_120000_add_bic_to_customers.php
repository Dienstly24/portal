<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIC zur Bankverbindung des Kunden. Die Dokumentanalyse liest die BIC aus
 * dem SEPA-Mandat (mehrere Vorlagen-Parser + KI) - bisher wurde sie mangels
 * Spalte verworfen. Wie IBAN/Kontoinhaber verschluesselt at rest, daher als
 * TEXT (der Chiffretext ist laenger als die 8-11 Klartext-Zeichen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'bic')) {
                $table->text('bic')->nullable()->after('account_holder');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'bic')) {
                $table->dropColumn('bic');
            }
        });
    }
};
