<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Zaehlerstands-Historie der Energievertraege (Betreiber-Vorgabe 29.07.2026).
 *
 * Bisher hielt contract_energy_details.meter_reading nur EINEN Wert - der
 * naechste Stand ueberschrieb den vorherigen. Fuer die Verbrauchshistorie
 * ("wann wurde abgelesen, wie viel wurde seitdem verbraucht") bekommt jede
 * Ablesung eine eigene Zeile; der Bestandswert bleibt als "aktueller Stand"
 * erhalten und wird vom MeterReadingService mitgefuehrt.
 *
 * register = OBIS-Kennzahl des Zaehlwerks: 1.8.0 Bezug (Standard), 2.8.0
 * Einspeisung (Zweirichtungszaehler mit PV), 1.8.1/1.8.2 HT/NT. Ohne
 * Trennung wuerde die Einspeisung eines Zweirichtungszaehlers den
 * Bezugsverbrauch verfaelschen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meter_readings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_energy_detail_id');
            $table->foreign('contract_energy_detail_id', 'mr_detail_fk')
                ->references('id')->on('contract_energy_details')->cascadeOnDelete();
            // Zaehlernummer wie abgelesen/erkannt - dokumentiert, WELCHER
            // Zaehler abgelesen wurde (nach einem Zaehlerwechsel weicht sie
            // vom aktuellen Bestand ab).
            $table->string('meter_number', 60)->nullable();
            $table->string('register', 10)->default('1.8.0');
            $table->decimal('reading', 12, 3);
            // Zaehleinheit: Strom kWh, Gas m3 (der Zaehler zaehlt Kubikmeter,
            // die Abrechnung rechnet erst spaeter in kWh um) - der erfasste
            // Wert wird nie stillschweigend umgerechnet.
            $table->string('unit', 10)->default('kWh');
            $table->date('reading_date');
            // Exakter Zeitpunkt der Meldung/des Uploads - der Betreiber will
            // wissen, wann das Foto kam, nicht nur an welchem Tag.
            $table->timestamp('captured_at')->nullable();
            $table->string('source', 20)->default('staff'); // staff|customer|document
            // Beleg (Zaehlerfoto), aus dem der Stand stammt.
            $table->uuid('document_id')->nullable();
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();
            $table->string('created_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['contract_energy_detail_id', 'register', 'reading_date'], 'mr_detail_register_date_idx');
        });

        // Zaehlernummer in normalisierter Form (nur A-Z0-9, Grossschreibung):
        // Grundlage der Zuordnung "Foto -> Vertrag -> Kunde". Ohne sie muesste
        // jede Suche alle Schreibvarianten in PHP vergleichen.
        Schema::table('contract_energy_details', function (Blueprint $table) {
            $table->string('meter_number_normalized', 60)->nullable()->after('meter_number');
            $table->index('meter_number_normalized', 'ced_meter_norm_idx');
        });

        foreach (DB::table('contract_energy_details')->whereNotNull('meter_number')->get() as $row) {
            $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $row->meter_number));
            DB::table('contract_energy_details')->where('id', $row->id)
                ->update(['meter_number_normalized' => $normalized !== '' ? $normalized : null]);
        }

        // Bereits erfasste Bestands-Zaehlerstaende als ersten Historieneintrag
        // uebernehmen - sonst startet die Verbrauchshistorie bei jedem
        // Vertrag ohne Ausgangswert und der erste gemeldete Stand ergaebe
        // keinen Verbrauch. Datum: Vertragsbeginn, sonst Erfassungszeitpunkt.
        $details = DB::table('contract_energy_details')->whereNotNull('meter_reading')->get();
        foreach ($details as $detail) {
            $value = (float) str_replace(',', '.', (string) preg_replace('/[^0-9,.\-]/', '', (string) $detail->meter_reading));
            if ($value <= 0) {
                continue;
            }
            $start = DB::table('contracts')->where('id', $detail->contract_id)->value('start_date');
            $readingDate = substr((string) ($start ?: $detail->created_at ?: now()->toDateString()), 0, 10);
            DB::table('meter_readings')->insert([
                'id' => (string) Str::uuid(),
                'contract_energy_detail_id' => $detail->id,
                'meter_number' => $detail->meter_number,
                'register' => '1.8.0',
                'reading' => $value,
                'unit' => 'kWh',
                'reading_date' => $readingDate,
                'captured_at' => $detail->created_at,
                'source' => 'staff',
                'created_by' => null, // System-Uebernahme aus dem Bestand
                'note' => 'Uebernahme des erfassten Zaehlerstands (Bestand)',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_readings');
        Schema::table('contract_energy_details', function (Blueprint $table) {
            $table->dropIndex('ced_meter_norm_idx');
            $table->dropColumn('meter_number_normalized');
        });
    }
};
