<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bankdaten (iban, iban2) werden seit der DSGVO-Umstellung verschluesselt at
 * rest gespeichert (Cast SafeEncrypted / vormals "encrypted"). Der Laravel-
 * Ciphertext ist base64(JSON mit iv/value/mac/tag) und damit deutlich laenger
 * als der Klartext: schon eine formatierte IBAN (mit Leerzeichen) ergibt >255
 * Zeichen. Die Spalten waren aber noch als VARCHAR(255) angelegt, sodass das
 * Speichern der Kundenakte auf MySQL mit
 *   SQLSTATE[22001]: Data too long for column 'iban'
 * abbrach (HTTP 500 in AdminController::customerUpdate). SQLite meldet diese
 * Laengenverletzung nicht, deshalb blieb der Fehler in den Tests unsichtbar.
 *
 * Die uebrigen verschluesselten Felder (health_insurance_number,
 * pension_insurance_number, tax_id sowie customer_change_requests.old_data/
 * new_data) wurden bereits frueher auf TEXT umgestellt - iban/iban2 waren die
 * letzten VARCHAR-Spalten mit verschluesseltem Inhalt.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'iban')) {
                $table->text('iban')->nullable()->change();
            }
            if (Schema::hasColumn('customers', 'iban2')) {
                $table->text('iban2')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        // Kein Rueckbau auf VARCHAR(255): bereits verschluesselte Werte wuerden
        // dabei abgeschnitten und unlesbar. Die Spalten bleiben als TEXT.
    }
};
