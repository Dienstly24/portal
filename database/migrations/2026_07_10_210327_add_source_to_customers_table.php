<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Herkunft eines Kundendatensatzes (Architekturplan Abschnitt 6):
 * manual | website | email_import | fonds_finanz | import | lexoffice.
 * Bestehende Kunden bleiben NULL (unbekannte/historische Herkunft) -
 * kein rückwirkendes Raten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'source')) {
                $table->string('source')->nullable()->after('customer_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
