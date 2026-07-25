<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provisions-Saetze fuer Vermittler (Betreiber-Vorgabe 25.07.2026):
 * je Mitarbeiter und je Partner konfigurierbar. Beide Werte optional:
 * - provision_fixed:   fester EUR-Betrag je vermitteltem Neuvertrag
 * - provision_percent: Prozent vom Jahresbeitrag des Neuvertrags
 * Die Saetze dienen als VORSCHLAG im Neukunden-Bericht; der tatsaechliche
 * Betrag wird beim Erfassen der Provision bestaetigt/ueberschrieben (HITL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('provision_fixed', 8, 2)->nullable()->after('can_import_export');
            $table->decimal('provision_percent', 5, 2)->nullable()->after('provision_fixed');
        });
        Schema::table('partners', function (Blueprint $table) {
            $table->decimal('provision_fixed', 8, 2)->nullable()->after('is_active');
            $table->decimal('provision_percent', 5, 2)->nullable()->after('provision_fixed');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['provision_fixed', 'provision_percent']);
        });
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['provision_fixed', 'provision_percent']);
        });
    }
};
