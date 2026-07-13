<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vertrags-Flexibilisierung (Betreiber-Feedback 13.07.2026):
 *
 * 1. contracts.type war ein enger Enum (kfz/krankenversicherung/internet/
 *    strom_gas/andere). Die UI bot aber laengst mehr Sparten an (Haftpflicht,
 *    Leben, Unfall, Hausrat, Rechtsschutz, E-Scooter ...). Das fuehrte beim
 *    Anlegen/Genehmigen zu Enum-Fehlern. -> string(40), Whitelist jetzt im
 *    Code (Contract::TYPES), damit neue Sparten ohne Migration moeglich sind.
 *
 * 2. contract_number war NOT NULL und wurde bei leerer Eingabe automatisch
 *    als "V-XXXXXXXX" erzeugt. Der Betreiber will die echte
 *    Versicherungsnummer spaeter nachtragen, keine Fantasienummer.
 *    -> nullable; die Auto-Erzeugung entfaellt im Controller.
 *
 * 3. type_other: Freitext fuer die Sparte "Sonstige" (z.B. "Schutzbrief",
 *    "ADAC Mobil-Club").
 */
return new class extends Migration {
    public function up(): void
    {
        // 1 + 2: Spaltentypen anpassen (nativer change() funktioniert auf
        // MySQL und SQLite in Laravel 11+).
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('type', 40)->default('andere')->change();
            $table->string('contract_number')->nullable()->change();
        });

        // Alte, leere Platzhalter-Nummern (aus applyContract) auf NULL
        // heben, damit mehrere gemeldete Vertraege ohne Nummer nebeneinander
        // bestehen koennen (leerer String kollidiert am Unique-Index).
        DB::table('contracts')->where('contract_number', '')->update(['contract_number' => null]);

        // 3: Freitext-Sparte
        if (!Schema::hasColumn('contracts', 'type_other')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->string('type_other', 120)->nullable()->after('subtype');
            });
        }
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (Schema::hasColumn('contracts', 'type_other')) {
                $table->dropColumn('type_other');
            }
        });
        // type/contract_number bleiben als string/nullable - eine Rueckkehr
        // zum engen Enum wuerde Bestandsdaten (neue Sparten) zerstoeren.
    }
};
