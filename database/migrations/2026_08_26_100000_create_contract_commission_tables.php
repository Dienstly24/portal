<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interne Provisionen: Provisionsdaten aus FREMDSYSTEMEN (Maklerpool,
 * Vergleichsportal, Energie-Vertriebsportal) an den eigenen Vertrag binden
 * (Betreiber-Auftrag 26.08.2026).
 *
 * WARUM EIN EIGENER STRANG neben `provisions` und `vermittler_settlements`:
 *  - `provisions` = AUSGANG an eigene Mitarbeiter/Partner (was WIR zahlen).
 *  - `vermittler_settlements` = der EINE Vermittler TARIFCHECK24, ein festes
 *    Dateiformat, eine Kennung (`Id`).
 *  - `contract_commissions` = EINGANG aus BELIEBIG vielen Quellen, mit
 *    beliebigen Spalten, mehreren Kennungen, mehreren Provisionsarten je
 *    Vertrag, mehreren Waehrungen und Teilzahlungen.
 * Die drei zu verschmelzen hiesse, eine der drei Wahrheiten zu verbiegen.
 *
 * ZWEISTUFIGER IMPORT ist Absicht: Eine Datei wird zuerst als ENTWURF
 * gelesen (Zeilen liegen in `commission_import_rows`), erst die Bestaetigung
 * schreibt Provisionen. Ohne diese Trennung gaebe es keine ehrliche Vorschau -
 * man saehe erst nach dem Schreiben, was passiert ist.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Die INTERNE Vertragsnummer des Fremdsystems (z.B. Maklerpool
        // "V19613073"). Bewusst NEBEN contract_number (= Nummer der
        // Gesellschaft) und reference_number (= Vorgangsnummer der
        // Antragsstrecke): es sind drei verschiedene Nummern, die drei
        // verschiedene Systeme vergeben. Eine davon zu ueberschreiben
        // wuerde eine Bruecke kappen, die spaeter gebraucht wird.
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('internal_contract_number', 60)->nullable()->after('contract_number');
            $table->index('internal_contract_number');
        });

        // Ein Import-Lauf. Status 'entwurf' = gelesen und geprueft, aber noch
        // NICHTS geschrieben; 'importiert' = bestaetigt; 'verworfen' = der
        // Admin hat abgebrochen. Der Lauf bleibt in jedem Fall stehen
        // (Nachweis, wer wann was hochgeladen hat).
        Schema::create('commission_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('filename', 255);
            $table->string('file_hash', 64)->index();
            $table->string('format', 10);                  // csv | xlsx | xls
            $table->string('delimiter', 5)->nullable();     // erkanntes Trennzeichen
            $table->string('encoding', 30)->nullable();     // erkannte Kodierung
            $table->string('sheet_name', 190)->nullable();  // gewaehltes Excel-Blatt
            $table->json('sheet_names')->nullable();        // alle Blaetter der Datei
            $table->json('header')->nullable();             // Kopfzeile wie gelesen
            $table->json('column_map')->nullable();         // Systemfeld => Spaltenindex
            $table->string('status', 20)->default('entwurf')->index();
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_new')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_duplicate')->default(0);
            $table->unsignedInteger('rows_unmatched')->default(0);
            $table->unsignedInteger('rows_invalid')->default(0);
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        // Die gelesenen Zeilen eines Laufs - Grundlage der Vorschau UND des
        // Fehler-Exports. Sie halten den ROHWERT je Zelle, damit der Admin
        // sieht, was in der Datei stand, nicht nur unsere Deutung davon.
        Schema::create('commission_import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_id')->constrained('commission_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');          // Zeile in der Datei (1 = Kopfzeile)
            $table->json('raw')->nullable();                // Rohzellen der Zeile
            $table->json('mapped')->nullable();             // gedeutete Werte je Systemfeld
            // neu | aktualisiert | duplikat | nicht_zugeordnet | fehlerhaft
            $table->string('result', 30)->index();
            $table->string('message', 500)->nullable();     // Klartext-Begruendung
            $table->foreignUuid('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('match_reason', 120)->nullable();
            $table->string('dedupe_key', 64)->nullable()->index();
            $table->timestamps();
            $table->index(['import_id', 'result']);
        });

        // Die Provision selbst. Eine Zeile je Provisionsvorgang - ein Vertrag
        // darf MEHRERE haben (Abschluss + Bestand, Nachtrag, Teilzahlungen).
        Schema::create('contract_commissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_id')->nullable()->constrained('commission_imports')->nullOnDelete();

            // --- Kennungen (die Bruecken zum Vertrag) --------------------
            $table->string('internal_contract_number', 60)->nullable();
            $table->string('internal_key', 60)->nullable()->index();   // normalisiert
            $table->string('external_contract_number', 60)->nullable();
            $table->string('reference_number', 60)->nullable();
            $table->string('vermittler_id', 60)->nullable();
            $table->string('order_number', 60)->nullable();
            $table->string('external_id', 60)->nullable();             // Abrechnungsnummer der Quelle

            // --- Zuordnung ----------------------------------------------
            $table->foreignUuid('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // Klartext-Kopie: die Provision bleibt belegbar, auch wenn Vertrag
            // oder Kunde spaeter geloescht werden (Rueckfrage zu einem Storno).
            $table->string('contract_label', 190)->nullable();
            $table->string('customer_label', 190)->nullable();
            $table->string('match_status', 20)->default('offen')->index(); // zugeordnet | offen | manuell
            $table->string('match_reason', 120)->nullable();

            // --- Inhalt --------------------------------------------------
            $table->string('recipient_name', 190)->nullable();     // Provisionsempfaenger
            $table->string('recipient_number', 60)->nullable();    // Vermittlernummer
            $table->string('commission_type', 120)->nullable();    // Abschlussprovision ...
            $table->string('product_name', 190)->nullable();
            $table->string('company', 190)->nullable();            // Gesellschaft
            $table->string('sparte', 60)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('vat_amount', 12, 2)->nullable();
            $table->decimal('reserve_amount', 12, 2)->nullable();  // Stornoreserve
            $table->decimal('paid_amount', 12, 2)->nullable();     // fuer Teilzahlungen
            $table->date('commission_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('payment_date')->nullable();
            $table->string('status', 30)->default('offen')->index();
            $table->string('storno_reason', 255)->nullable();

            // --- Rechnung (Vorbereitung fuer den spaeteren Abgleich) -----
            $table->string('invoice_number', 60)->nullable()->index();
            $table->date('invoice_date')->nullable();
            $table->decimal('invoice_amount', 12, 2)->nullable();
            $table->timestamp('invoice_linked_at')->nullable();
            $table->foreignUuid('invoice_document_id')->nullable()->constrained('documents')->nullOnDelete();

            $table->string('source_file', 255)->nullable();
            $table->text('notes')->nullable();                     // interne Notiz
            // Natuerlicher Schluessel gegen Doppel-Import: Kennung + Art +
            // Datum + Betrag + Datensatznummer der Quelle. Unique heisst:
            // dieselbe Datei zweimal hochladen kann keine zweite Provision
            // erzeugen, auch nicht bei einem Fehler in unserem Code.
            $table->string('dedupe_key', 64)->unique();
            $table->string('row_hash', 64)->nullable();            // unveraendert => "Duplikat"
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'commission_date']);
            $table->index(['contract_id', 'status']);
        });

        // Unveraenderliches Protokoll. Bewusst eine EIGENE Tabelle und nicht
        // der allgemeine ActivityLog: hier stehen Betraege, deshalb gilt
        // dieselbe enge Sichtbarkeit wie fuer die Provisionen selbst.
        // Aus der Oberflaeche gibt es KEINEN Loeschweg (nur lesen).
        Schema::create('commission_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_label', 190)->nullable();   // Name bleibt lesbar
            $table->string('action', 60)->index();
            $table->foreignUuid('commission_id')->nullable()->constrained('contract_commissions')->nullOnDelete();
            $table->foreignUuid('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('internal_contract_number', 60)->nullable()->index();
            $table->string('field', 60)->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('source_file', 255)->nullable();
            $table->foreignUuid('import_id')->nullable()->constrained('commission_imports')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->index();
        });

        // Eigene Berechtigung: Provisionsdaten sind INTERN. Admin darf immer,
        // alle anderen nur mit diesem Recht - eine Rolle allein reicht nicht.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_manage_commissions')->default(false)->after('can_import_export');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_manage_commissions');
        });
        Schema::dropIfExists('commission_audit_logs');
        Schema::dropIfExists('contract_commissions');
        Schema::dropIfExists('commission_import_rows');
        Schema::dropIfExists('commission_imports');
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex(['internal_contract_number']);
            $table->dropColumn('internal_contract_number');
        });
    }
};
