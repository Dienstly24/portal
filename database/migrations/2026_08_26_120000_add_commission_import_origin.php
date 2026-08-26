<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aus einer Abrechnung darf ein VERTRAG (und notfalls ein KUNDE) entstehen
 * (Betreiber-Entscheidung 26.08.2026).
 *
 * WARUM DIE HERKUNFT AN DEN DATENSATZ GEHOERT: Ein Lauf kann in einem Zug
 * mehrere hundert Vertraege und Kunden anlegen. Ohne festgehaltene Herkunft
 * liesse sich hinterher nicht mehr sagen, welcher Datensatz aus welcher Datei
 * stammt - und ein Fehlgriff waere nur noch von Hand zurueckzunehmen. Mit ihr
 * ist die Frage "was hat dieser Import angelegt?" eine Abfrage.
 *
 * BETRIEBSART: dieselbe Oberflaeche liest zwei Arten von Dateien. Eine
 * ABRECHNUNG traegt Betraege (Provisionen entstehen), eine AUFTRAGSLISTE
 * traegt Kundendaten ohne einen einzigen Betrag (nur Kunden/Vertraege
 * entstehen). Beides in einen Modus zu zwingen hiesse, entweder die
 * Auftragsliste als "fehlerhaft" abzulehnen oder bei der Abrechnung den
 * fehlenden Betrag durchgehen zu lassen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_imports', function (Blueprint $table) {
            $table->string('mode', 20)->default('provisionen')->after('format');
            $table->unsignedInteger('contracts_created')->default(0)->after('rows_invalid');
            $table->unsignedInteger('customers_created')->default(0)->after('contracts_created');
            $table->unsignedInteger('rows_unlinked_kept')->default(0)->after('customers_created');
            // Wie viele nicht zugeordnete Zeilen genug tragen, um daraus einen
            // Vertrag zu machen - die Zahl muss VOR der Entscheidung sichtbar
            // sein, sonst ist der Haken im letzten Schritt ein Blindflug.
            $table->unsignedInteger('rows_buildable')->default(0)->after('rows_unlinked_kept');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->uuid('commission_import_id')->nullable()->after('internal_contract_number');
            $table->index('commission_import_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->uuid('commission_import_id')->nullable()->after('source');
            $table->index('commission_import_id');
        });

        // Die Zeile merkt sich, was aus ihr geworden ist - der Import-Bericht
        // kann so "angelegt" von "vorhanden" unterscheiden, ohne zu raten.
        Schema::table('commission_import_rows', function (Blueprint $table) {
            $table->foreignUuid('customer_id')->nullable()->after('contract_id')->constrained('customers')->nullOnDelete();
            $table->boolean('created_contract')->default(false)->after('customer_id');
            $table->boolean('created_customer')->default(false)->after('created_contract');
        });
    }

    public function down(): void
    {
        Schema::table('commission_import_rows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['created_contract', 'created_customer']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['commission_import_id']);
            $table->dropColumn('commission_import_id');
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex(['commission_import_id']);
            $table->dropColumn('commission_import_id');
        });
        Schema::table('commission_imports', function (Blueprint $table) {
            $table->dropColumn(['mode', 'contracts_created', 'customers_created', 'rows_unlinked_kept', 'rows_buildable']);
        });
    }
};
