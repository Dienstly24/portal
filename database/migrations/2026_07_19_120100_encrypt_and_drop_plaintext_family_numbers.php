<?php
use App\Models\CustomerFamily;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * customer_family: die Klartext-Spalten krankenversicherung_nr / steuer_nr
 * waren unverschluesselte Duplikate der spaeter eingefuehrten, verschluesselten
 * Felder health_insurance_number / tax_id (Audit DB-2 / DSGVO). Diese Migration
 * uebernimmt vorhandene Klartextwerte in die verschluesselten Spalten (nur wenn
 * dort noch leer) und entfernt danach die Klartext-Spalten.
 */
return new class extends Migration {
    public function up(): void {
        if (Schema::hasColumn('customer_family', 'krankenversicherung_nr')
            || Schema::hasColumn('customer_family', 'steuer_nr')) {
            // Ueber die Modelle iterieren, damit der encrypted-Cast beim
            // Schreiben tatsaechlich greift (kein Mass-Update).
            // Ziel-Leerpruefung entschluesselt die verschluesselte Spalte. Sollte
            // dort (untypisch) ein nicht entschluesselbarer Alt-Wert liegen,
            // werten wir ihn als "belegt" statt die Migration abzubrechen.
            $targetEmpty = function ($member, string $col): bool {
                try {
                    return empty($member->{$col});
                } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                    return false;
                }
            };
            foreach (CustomerFamily::all() as $member) {
                $dirty = false;
                if (Schema::hasColumn('customer_family', 'krankenversicherung_nr')
                    && !empty($member->getRawOriginal('krankenversicherung_nr'))
                    && $targetEmpty($member, 'health_insurance_number')) {
                    $member->health_insurance_number = $member->getRawOriginal('krankenversicherung_nr');
                    $dirty = true;
                }
                if (Schema::hasColumn('customer_family', 'steuer_nr')
                    && !empty($member->getRawOriginal('steuer_nr'))
                    && $targetEmpty($member, 'tax_id')) {
                    $member->tax_id = $member->getRawOriginal('steuer_nr');
                    $dirty = true;
                }
                if ($dirty) {
                    $member->save();
                }
            }
        }

        Schema::table('customer_family', function (Blueprint $table) {
            foreach (['krankenversicherung_nr', 'steuer_nr'] as $col) {
                if (Schema::hasColumn('customer_family', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void {
        // Bewusst nicht wiederherstellbar - die Klartext-Duplikate sollen nicht
        // zurueckkehren (die Daten liegen verschluesselt in den Zielspalten).
    }
};
