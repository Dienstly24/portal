<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vermittler-Abrechnung: Bruecke zwischen dem Vertrag im Portal und der
 * Abrechnung des Vermittlers (Betreiber-Auftrag 20.08.2026).
 *
 * WARUM DREI TABELLEN und nur wenige neue Spalten am Vertrag:
 * Der Abgleich ist eine ZUSATZSCHICHT ueber dem Bestand - die Vertragsdaten
 * selbst bleiben unberuehrt. Am Vertrag steht deshalb nur der aktuelle
 * Zustand (welche Vermittler-ID, welcher Abrechnungsstatus, wann zuletzt
 * abgeglichen); die Abrechnungszeilen und die Historie liegen daneben und
 * ueberleben das LOESCHEN des Vertrags (nullOnDelete + Klartext-Kopie von
 * Referenz-Nr./Vermittler-ID). Genau das ist der Zweck: belegen zu koennen,
 * dass ein Vertrag bei uns existierte und wie der Vermittler ihn behandelt hat.
 */
return new class extends Migration {
    public function up(): void
    {
        // Ein Import-Lauf (eine hochgeladene CSV-Datei des Vermittlers).
        Schema::create('vermittler_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('filename', 255);
            // sha256 der Datei: erneutes Hochladen derselben Datei ist erlaubt
            // (Status koennen sich geaendert haben), wird aber als solches
            // erkannt und im Ergebnis benannt.
            $table->string('file_hash', 64)->index();
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_matched')->default(0);
            $table->unsignedInteger('rows_new_link')->default(0);
            $table->unsignedInteger('rows_unmatched')->default(0);
            $table->unsignedInteger('rows_review')->default(0);
            $table->unsignedInteger('rows_storno')->default(0);
            $table->unsignedInteger('rows_unchanged')->default(0);
            $table->unsignedInteger('rows_invalid')->default(0);
            $table->unsignedInteger('contracts_not_found')->default(0);
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Eine Zeile je Abrechnungs-Datensatz des Vermittlers. Natuerlicher
        // Schluessel ist die Id des Vermittlers -> ein erneuter Import
        // AKTUALISIERT die Zeile, er dupliziert sie nie (Idempotenz).
        Schema::create('vermittler_settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_id')->nullable()->constrained('vermittler_imports')->nullOnDelete();
            $table->string('vermittler_id', 60)->unique();
            $table->string('produkt', 190)->nullable();
            $table->date('statement_date')->nullable();
            $table->string('status_code', 20)->nullable();
            $table->decimal('provision', 12, 2)->nullable();
            $table->string('tracking_id', 100)->nullable();
            $table->string('storno_reason', 255)->nullable();
            // Referenz-Nr. genau so, wie sie geliefert wurde (Anzeige) - plus
            // die normalisierte Fassung, gegen die verglichen wird.
            $table->string('reference_number', 60)->nullable();
            $table->string('reference_key', 60)->nullable()->index();
            $table->foreignUuid('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // Klartext-Kopie fuer die Zeit NACH einer Vertrags-/Kundenloeschung.
            $table->string('contract_label', 190)->nullable();
            $table->string('customer_label', 190)->nullable();
            // Dauerhafter Zuordnungs-Zustand: matched | unmatched | review.
            $table->string('match_result', 30)->default('unmatched')->index();
            // Was der LETZTE Import mit dieser Zeile gemacht hat - zusaetzlich
            // 'linked' (neu verknuepft) und 'unchanged' (bereits importiert).
            $table->string('import_result', 30)->nullable()->index();
            $table->string('match_note', 255)->nullable();
            // sha1 der Nutzdaten: unveraenderte Zeile = "Bereits importiert".
            $table->string('row_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['match_result', 'statement_date']);
        });

        // Kleine Historie: WANN und WIE ein Vertrag mit der Abrechnung
        // verknuepft wurde. Bewusst kein FK-Zwang auf den Vertrag.
        Schema::create('vermittler_match_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('reference_number', 60)->nullable()->index();
            $table->string('vermittler_id', 60)->nullable()->index();
            $table->string('action', 40);
            $table->string('detail', 255)->nullable();
            $table->foreignUuid('import_id')->nullable()->constrained('vermittler_imports')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->string('vermittler_id', 60)->nullable()->after('reference_number');
            $table->string('vermittler_status', 40)->nullable()->after('vermittler_id');
            $table->timestamp('vermittler_matched_at')->nullable()->after('vermittler_status');
            $table->uuid('vermittler_last_import_id')->nullable()->after('vermittler_matched_at');
            $table->timestamp('vermittler_last_imported_at')->nullable()->after('vermittler_last_import_id');
            $table->index('vermittler_id');
            $table->index('vermittler_status');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex(['vermittler_id']);
            $table->dropIndex(['vermittler_status']);
            $table->dropColumn([
                'vermittler_id', 'vermittler_status', 'vermittler_matched_at',
                'vermittler_last_import_id', 'vermittler_last_imported_at',
            ]);
        });
        Schema::dropIfExists('vermittler_match_events');
        Schema::dropIfExists('vermittler_settlements');
        Schema::dropIfExists('vermittler_imports');
    }
};
