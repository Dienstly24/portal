<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * KFZ-Redesign (Betreiber-Auftrag 17.07.2026): Der KFZ-Vertrag wird an die
 * Arbeitsweise deutscher Versicherer angeglichen.
 *
 * - contract_vehicle_details erhaelt strukturierte Felder fuer Fahrzeugdaten
 *   (HSN/TSN, Erwerb, Leistung, Kraftstoff, Getriebe, Farbe), Deckung
 *   (Haftpflicht ist immer enthalten; Teilkasko/Vollkasko mit eigener SB),
 *   Zusatzleistungen (JSON-Katalog), Fahrerkreis, Halter/Eigentum,
 *   Kilometerstaende und die neue SF-Logik (gueltig-ab, Art der Einstufung,
 *   tatsaechliche vs. aktuelle Klasse).
 * - Schaeden ziehen aus der JSON-Spalte in eine eigene Tabelle vehicle_claims
 *   um (Datum, Art, Schadenhoehe, Status, Versicherer, Notizen).
 * - vehicle_mileage_readings: alle km-Staende bleiben historisch erhalten
 *   (Kunde kann im Portal melden).
 * - vehicle_sf_history: SF-Verlauf je Sparte (Haftpflicht/Vollkasko) wird
 *   fortgeschrieben statt ueberschrieben.
 *
 * Alt-Daten werden uebernommen: claims-JSON -> vehicle_claims,
 * "SF 12"-Freitexte werden auf den Klassen-Schluessel ("12") normalisiert,
 * sf_*_year wird zu gueltig-ab (01.01.Jahr) und als Verlaufseintrag gesichert.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('contract_vehicle_details', function (Blueprint $table) {
            // Fahrzeugdaten
            $table->string('hsn', 4)->nullable()->after('vin');
            $table->string('tsn', 10)->nullable()->after('hsn');
            $table->date('acquisition_date')->nullable()->after('first_registration');
            $table->string('vehicle_condition', 20)->nullable()->after('acquisition_date'); // neuwagen|gebrauchtwagen
            $table->unsignedSmallInteger('power_kw')->nullable()->after('vehicle_condition');
            $table->string('fuel_type', 30)->nullable()->after('power_kw');
            $table->string('transmission', 20)->nullable()->after('fuel_type');
            $table->string('color', 40)->nullable()->after('transmission');
            // Versicherungsschutz (Haftpflicht ist Pflicht und immer enthalten)
            $table->boolean('has_teilkasko')->default(false);
            $table->unsignedSmallInteger('teilkasko_deductible')->nullable(); // 0 = ohne SB
            $table->boolean('has_vollkasko')->default(false);
            $table->unsignedSmallInteger('vollkasko_deductible')->nullable();
            // Zusatzleistungen (Schluessel aus ContractVehicleDetail::EXTRAS)
            $table->json('extras')->nullable();
            // Fahrerkreis (Schluessel) + strukturierte weitere Fahrer
            $table->json('driver_groups')->nullable();
            $table->json('additional_drivers')->nullable(); // [{name, birth_date, license_date}]
            // Halter & Eigentum
            $table->string('holder_type', 30)->nullable();   // versicherungsnehmer|abweichender_halter
            $table->string('holder_name')->nullable();       // nur bei abweichendem Halter
            $table->string('ownership_type', 30)->nullable(); // versicherungsnehmer|fahrzeughalter|leasing|finanzierung
            // Nutzung / Kilometer
            $table->unsignedInteger('initial_mileage')->nullable(); // km bei Vertragsbeginn
            $table->unsignedInteger('annual_mileage')->nullable();  // vereinbarte km/Jahr
            // SF-Einstufung Haftpflicht (aktuelle Klasse bleibt in sf_liability_class)
            $table->date('sf_liability_valid_from')->nullable();
            $table->string('sf_liability_type', 20)->nullable();          // tatsaechlich|sondereinstufung
            $table->string('sf_liability_special_reason', 40)->nullable();
            $table->string('sf_liability_real_class', 10)->nullable();    // tatsaechliche Klasse bei Sondereinstufung
            // SF-Einstufung Vollkasko
            $table->date('sf_comprehensive_valid_from')->nullable();
            $table->string('sf_comprehensive_type', 20)->nullable();
            $table->string('sf_comprehensive_special_reason', 40)->nullable();
            $table->string('sf_comprehensive_real_class', 10)->nullable();
        });

        Schema::create('vehicle_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_vehicle_detail_id');
            $table->foreign('contract_vehicle_detail_id', 'vc_detail_fk')
                ->references('id')->on('contract_vehicle_details')->cascadeOnDelete();
            $table->date('claim_date')->nullable();
            $table->string('claim_type', 30)->nullable();   // haftpflicht|teilkasko|vollkasko|sonstige
            $table->decimal('damage_amount', 10, 2)->nullable();
            $table->string('status', 30)->nullable();       // offen|in_bearbeitung|reguliert|abgelehnt
            $table->string('insurer')->nullable();          // regulierender Versicherer (kann vom Vertrag abweichen)
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_mileage_readings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_vehicle_detail_id');
            $table->foreign('contract_vehicle_detail_id', 'vm_detail_fk')
                ->references('id')->on('contract_vehicle_details')->cascadeOnDelete();
            $table->unsignedInteger('mileage');
            $table->date('reading_date');
            $table->string('source', 20)->default('staff'); // staff|customer
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_sf_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_vehicle_detail_id');
            $table->foreign('contract_vehicle_detail_id', 'vs_detail_fk')
                ->references('id')->on('contract_vehicle_details')->cascadeOnDelete();
            $table->string('branch', 20);       // haftpflicht|vollkasko
            $table->string('sf_class', 10);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable(); // null = aktuell gueltig
            $table->timestamps();
        });

        // ---- Datenuebernahme der Alt-Spalten -------------------------------
        foreach (DB::table('contract_vehicle_details')->get() as $row) {
            $update = [];

            // Fahrzeugtyp-Freitext auf Katalog-Schluessel normalisieren (nur
            // wenn eindeutig, sonst bleibt der Freitext stehen).
            $typeKey = strtolower(trim((string) $row->vehicle_type));
            $map = ['pkw' => 'pkw', 'wohnmobil' => 'wohnmobil', 'transporter' => 'transporter',
                'lkw' => 'lkw', 'anhaenger' => 'anhaenger', 'anhänger' => 'anhaenger',
                'wohnwagen' => 'wohnwagen', 'taxi' => 'taxi', 'mietwagen' => 'mietwagen'];
            if ($typeKey !== '' && isset($map[$typeKey]) && $row->vehicle_type !== $map[$typeKey]) {
                $update['vehicle_type'] = $map[$typeKey];
            }

            // "SF 12" -> "12"; Jahr -> gueltig ab 01.01.
            $norm = fn($v) => $v === null ? null : (preg_replace('/^\s*SF\s*/i', '', trim((string) $v)) ?: null);
            foreach ([['sf_liability_class', 'sf_liability_year', 'sf_liability_valid_from', 'haftpflicht'],
                      ['sf_comprehensive_class', 'sf_comprehensive_year', 'sf_comprehensive_valid_from', 'vollkasko']] as [$classCol, $yearCol, $fromCol, $branch]) {
                $class = $norm($row->{$classCol});
                if ($class !== ($row->{$classCol} ?? null)) $update[$classCol] = $class;
                $from = $row->{$yearCol} ? sprintf('%04d-01-01', (int) $row->{$yearCol}) : null;
                if ($from) $update[$fromCol] = $from;
                if ($class) {
                    DB::table('vehicle_sf_history')->insert([
                        'id' => (string) Str::uuid(),
                        'contract_vehicle_detail_id' => $row->id,
                        'branch' => $branch, 'sf_class' => $class,
                        'valid_from' => $from, 'valid_until' => null,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    // Bestand mit Vollkasko-SF hatte offensichtlich Vollkasko.
                    if ($branch === 'vollkasko') {
                        $update['has_teilkasko'] = true;
                        $update['has_vollkasko'] = true;
                    }
                }
            }

            // Alt-Schaeden [{month, year, type}] -> eigene Tabelle.
            foreach (json_decode((string) $row->claims, true) ?: [] as $claim) {
                $year = (int) ($claim['year'] ?? 0);
                if (!$year) continue;
                $month = min(12, max(1, (int) ($claim['month'] ?? 1)));
                DB::table('vehicle_claims')->insert([
                    'id' => (string) Str::uuid(),
                    'contract_vehicle_detail_id' => $row->id,
                    'claim_date' => sprintf('%04d-%02d-01', $year, $month),
                    'claim_type' => in_array($claim['type'] ?? '', ['haftpflicht', 'teilkasko', 'vollkasko'], true) ? $claim['type'] : 'sonstige',
                    'status' => null, 'insurer' => null, 'notes' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            if ($update) DB::table('contract_vehicle_details')->where('id', $row->id)->update($update);
        }

        Schema::table('contract_vehicle_details', function (Blueprint $table) {
            $table->dropColumn(['has_claims', 'claims', 'sf_liability_year', 'sf_comprehensive_year']);
        });
    }

    public function down(): void {
        Schema::table('contract_vehicle_details', function (Blueprint $table) {
            $table->boolean('has_claims')->default(false);
            $table->json('claims')->nullable();
            $table->unsignedSmallInteger('sf_liability_year')->nullable();
            $table->unsignedSmallInteger('sf_comprehensive_year')->nullable();
        });
        Schema::dropIfExists('vehicle_sf_history');
        Schema::dropIfExists('vehicle_mileage_readings');
        Schema::dropIfExists('vehicle_claims');
        Schema::table('contract_vehicle_details', function (Blueprint $table) {
            $table->dropColumn([
                'hsn', 'tsn', 'acquisition_date', 'vehicle_condition', 'power_kw', 'fuel_type',
                'transmission', 'color', 'has_teilkasko', 'teilkasko_deductible', 'has_vollkasko',
                'vollkasko_deductible', 'extras', 'driver_groups', 'additional_drivers',
                'holder_type', 'holder_name', 'ownership_type', 'initial_mileage', 'annual_mileage',
                'sf_liability_valid_from', 'sf_liability_type', 'sf_liability_special_reason', 'sf_liability_real_class',
                'sf_comprehensive_valid_from', 'sf_comprehensive_type', 'sf_comprehensive_special_reason', 'sf_comprehensive_real_class',
            ]);
        });
    }
};
