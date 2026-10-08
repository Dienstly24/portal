<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kinder unter dem Selbststaendigkeitsalter bekommen keine eigene
 * Kundennummer mehr (Betreiber-Auftrag 07.10.2026, KI-095).
 *
 *  1. `customers.customer_number` wird NULLBAR. Die Akte des Kindes bleibt
 *     (Name, Geburtsdatum, Krankenkasse, Dokumente haengen an ihr), sie
 *     traegt nur keine Nummer. UNIQUE bleibt - NULL kollidiert in SQLite
 *     und MySQL nie mit sich selbst.
 *  2. `archivierte_kundennummern`: eine zu Unrecht vergebene Nummer wird
 *     NICHT geloescht und NIE neu vergeben. Sie bleibt hier belegt, mit
 *     Grund, Akte und Bezugsperson - die Suche nach der alten Nummer
 *     findet weiterhin das Kind (und damit die Familie).
 *  3. `customers.portal_vorbereitung_erinnert_at`: die Erinnerung "Kind
 *     wird 15" geht genau EINMAL je Kind raus.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('customer_number')->nullable()->change();
        });

        if (! Schema::hasColumn('customers', 'portal_vorbereitung_erinnert_at')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->timestamp('portal_vorbereitung_erinnert_at')->nullable();
            });
        }

        if (! Schema::hasTable('archivierte_kundennummern')) {
            Schema::create('archivierte_kundennummern', function (Blueprint $table) {
                $table->id();
                $table->string('customer_number')->unique();
                // nullOnDelete: auch wenn die Akte spaeter geloescht wird,
                // bleibt die Nummer belegt - sie stand auf Schreiben.
                $table->uuid('customer_id')->nullable()->index();
                $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
                $table->uuid('bezugsperson_customer_id')->nullable()->index();
                $table->foreign('bezugsperson_customer_id')->references('id')->on('customers')->nullOnDelete();
                $table->string('grund', 60);
                $table->text('notiz')->nullable();
                $table->foreignId('archiviert_von')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Rueckbau nur, wenn er nichts verliert: gibt es Akten ohne Nummer,
        // wuerde NOT NULL sie unspeicherbar machen.
        if (DB::table('customers')->whereNull('customer_number')->exists()) {
            throw new RuntimeException('Rueckbau abgebrochen: es gibt Kundenakten ohne Kundennummer (abhaengige Kinder). Erst zuordnen, dann zurueckrollen.');
        }

        Schema::dropIfExists('archivierte_kundennummern');

        if (Schema::hasColumn('customers', 'portal_vorbereitung_erinnert_at')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('portal_vorbereitung_erinnert_at');
            });
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->string('customer_number')->nullable(false)->change();
        });
    }
};
