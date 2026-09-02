<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROVISIONSMANAGEMENT (Betreiber-Auftrag 02.09.2026): aus dem einen
 * Import-Weg wird ein zentrales Provisionsmanagement fuer MEHRERE Pools.
 *
 * WARUM ERWEITERN STATT NEU BAUEN: Die Tabellen aus dem Provisions-Import
 * (26.08.2026) tragen den Kern bereits - eine Provision je Vorgang, Rohzeile
 * je Import, Protokoll je Aenderung. Was fehlte, ist die POOL-Ebene und die
 * ZEIT: aus welcher Quelle kam die Provision, wann waere sie zu erwarten
 * gewesen, und was ist zu tun, wenn sie ausbleibt. Ein zweites Datenmodell
 * daneben haette dieselbe Provision zweimal gefuehrt - genau der Fehler, den
 * das Provisionsmanagement verhindern soll.
 *
 * VIER NEUE BAUSTEINE:
 *  1. `commission_pools` - je Pool die erwartete Frist und die Prueffrist.
 *     Sie sind EINSTELLUNG, nicht Code: der naechste Pool entsteht in der
 *     Oberflaeche, nicht in einem Deployment.
 *  2. `pool` an Import, Provision und Vertrag - die Herkunft ist damit eine
 *     Abfrage ("was hat uns CHECK24 gebracht?"), kein Dateiname.
 *  3. `commission_reference_links` - die Bruecke Referenz-Nr. <-> Pool-Id.
 *     Einmal gesehen, bleibt sie: die naechste Datei darf nur noch die Id
 *     fuehren und findet trotzdem den Vertrag.
 *  4. `commission_followups` - der Bearbeitungsstand einer FEHLENDEN
 *     Provision (Pool kontaktiert, in Klaerung, erledigt). Ohne ihn ist
 *     "Provision fehlt" eine Feststellung, die niemand weiterverfolgt.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // 1. Pools mit ihren Fristen (Einstellungen)
        // ---------------------------------------------------------------
        Schema::create('commission_pools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 40)->unique();          // check24, maklerpool ...
            $table->string('name', 120);
            // Welches Dateiprofil gehoert typischerweise zu diesem Pool?
            // Nur ein Vorschlag - eine unbekannte Datei wird nie abgelehnt.
            $table->string('source_profile', 60)->nullable();
            // Erwartete Provision nach X Monaten, Pruefung faellig nach Y.
            // Beides Monate, weil der Betrieb so rechnet ("etwa 3 Monate").
            $table->unsignedSmallInteger('expected_months')->default(3);
            $table->unsignedSmallInteger('check_months')->default(5);
            $table->boolean('active')->default(true);
            $table->string('contact', 190)->nullable();   // Ansprechpartner beim Pool
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------------
        // 2. Die Pool-Zugehoerigkeit an den drei Stellen, die sie brauchen
        // ---------------------------------------------------------------
        Schema::table('commission_imports', function (Blueprint $table) {
            $table->string('pool', 40)->nullable()->after('provider')->index();
        });

        Schema::table('contract_commissions', function (Blueprint $table) {
            $table->string('pool', 40)->nullable()->after('provider')->index();
            // Provisionsart normalisiert (abschluss, folge, bestand, storno,
            // korrektur ...). Die Bezeichnung der Quelle bleibt daneben in
            // `commission_type` stehen - sie ist der Beleg, unsere Deutung
            // nur die Auswertung.
            $table->string('commission_kind', 30)->nullable()->after('commission_type')->index();
            $table->date('booking_date')->nullable()->after('commission_date');
            $table->decimal('gross_amount', 12, 2)->nullable()->after('amount');
            $table->decimal('net_amount', 12, 2)->nullable()->after('gross_amount');
            $table->string('booking_reason', 255)->nullable()->after('storno_reason');
            // Herkunft bis auf die ZEILE genau: "aus welcher Datei und
            // welcher Zeile stammt diese Provision?" muss beantwortbar sein.
            $table->unsignedInteger('source_row')->nullable()->after('source_file');
            $table->json('raw')->nullable()->after('source_row'); // Original-Spaltenwerte
        });

        // ---------------------------------------------------------------
        // 3. Der Vertrag als Traeger des Provisions-Zustands
        // ---------------------------------------------------------------
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('pool', 40)->nullable()->after('internal_contract_number')->index();
            // Abschluss-/Antragsdatum: die Uhr der Provisionsfrist laeuft ab
            // dem Abschluss, nicht ab dem Lieferbeginn.
            $table->date('application_date')->nullable()->after('start_date');
            $table->date('signing_date')->nullable()->after('application_date');
            $table->date('expected_commission_date')->nullable()->after('signing_date');
            $table->date('commission_check_date')->nullable()->after('expected_commission_date');
            $table->string('commission_status', 30)->nullable()->after('commission_check_date')->index();
            $table->timestamp('commission_status_at')->nullable()->after('commission_status');
        });

        // ---------------------------------------------------------------
        // 4. Referenz-Nr. <-> Pool-Id (CHECK24-Speziallogik, §14/§15)
        // ---------------------------------------------------------------
        Schema::create('commission_reference_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('pool', 40)->index();
            $table->string('reference_key', 60);          // normalisiert
            $table->string('external_key', 60);           // normalisiert (Pool-Id)
            $table->string('reference_number', 60);       // wie geschrieben
            $table->string('external_id', 60);
            $table->foreignUuid('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('source', 20)->default('import'); // import | manuell
            $table->timestamps();
            // Ein Paar genau einmal. Zwei verschiedene Ids zur selben
            // Referenz bleiben beide stehen - das ist ein Fall fuer die
            // Pruefliste, keiner fuer eine stille Ueberschreibung.
            $table->unique(['pool', 'reference_key', 'external_key'], 'commission_ref_link_unique');
            $table->index(['pool', 'external_key']);
        });

        // ---------------------------------------------------------------
        // 5. Bearbeitungsstand einer fehlenden Provision (§19)
        // ---------------------------------------------------------------
        Schema::create('commission_followups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('contract_id')->unique()->constrained('contracts')->cascadeOnDelete();
            $table->string('status', 30)->default('offen'); // offen|pool_kontaktiert|in_klaerung|geklaert
            $table->date('contacted_on')->nullable();
            $table->string('contact_person', 190)->nullable();
            $table->text('response')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Startbestand der Pools. Bewusst hier und nicht in einem Seeder:
        // ohne Pools waere die Oberflaeche beim ersten Aufruf leer, und ein
        // Import haette keine Frist, gegen die er messen koennte.
        $now = now();
        $rows = [
            ['check24', 'CHECK24 / TARIFCHECK24', 'tarifcheck24', 3, 5],
            ['maklerpool', 'Maklerpool', 'maklerpool', 3, 6],
            ['energie', 'Energie-Pool', 'energie_vertriebsportal', 4, 8],
            ['versicherung', 'Versicherungs-Pool', null, 3, 6],
            ['fonds_finanz', 'Fonds Finanz', null, 3, 6],
            ['sonstiger', 'Sonstiger Pool', null, 3, 6],
        ];
        foreach ($rows as [$key, $name, $profile, $expected, $check]) {
            \Illuminate\Support\Facades\DB::table('commission_pools')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'key' => $key,
                'name' => $name,
                'source_profile' => $profile,
                'expected_months' => $expected,
                'check_months' => $check,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_followups');
        Schema::dropIfExists('commission_reference_links');

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex(['pool']);
            $table->dropIndex(['commission_status']);
            $table->dropColumn([
                'pool', 'application_date', 'signing_date', 'expected_commission_date',
                'commission_check_date', 'commission_status', 'commission_status_at',
            ]);
        });

        Schema::table('contract_commissions', function (Blueprint $table) {
            $table->dropIndex(['pool']);
            $table->dropIndex(['commission_kind']);
            $table->dropColumn([
                'pool', 'commission_kind', 'booking_date', 'gross_amount', 'net_amount',
                'booking_reason', 'source_row', 'raw',
            ]);
        });

        Schema::table('commission_imports', function (Blueprint $table) {
            $table->dropIndex(['pool']);
            $table->dropColumn('pool');
        });

        Schema::dropIfExists('commission_pools');
    }
};
