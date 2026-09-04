<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ARCH-1: Fehlende Indexe fuer die tatsaechlich genutzten Abfragen.
 *
 * WARUM DIESE UND KEINE ANDEREN: die Liste stammt aus einer Auszaehlung der
 * WHERE-/JOIN-/ORDER-BY-Bedingungen im Code, nicht aus "jede Spalte indizieren".
 * Ein Index kostet bei JEDEM Schreibvorgang - ein Index ohne Abfrage dahinter
 * ist reiner Verlust.
 *
 * BEWUSST NICHT indiziert: die LIKE-'%wort%'-Felder aus `Customer::scopeSearch`
 * (address_city, address_street, company_name ...). Ein fuehrender Platzhalter
 * kann keinen B-Baum nutzen - ein Index dort taeuscht Wirkung nur vor. Die
 * GLEICHHEITS-Bedingungen derselben Spalten aus `DuplicateDetectionService`
 * (email2, phone, mobile, address_zip) profitieren dagegen sehr wohl.
 *
 * Idempotent (Schema::hasIndex) und auf SQLite wie MySQL lauffaehig; es werden
 * nur Indexe angelegt/entfernt, keine Spalten oder Daten veraendert.
 */
return new class extends Migration {
    /**
     * Anzulegende Indexe: Tabelle => [Indexname => [Spalten]].
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const INDEXES = [
        // --- customers -------------------------------------------------
        // user_id ist mit Abstand die haeufigste Bedingung im Projekt
        // (Relation user(), whereHas('user'), User->customer) und war bisher
        // voellig unindiziert - jeder Zugriff war ein Full Table Scan.
        'customers' => [
            'customers_user_id_idx' => ['user_id'],
            // Gleichheits-Vorfilter der Dublettenerkennung.
            'customers_email2_idx' => ['email2'],
            'customers_phone_idx' => ['phone'],
            'customers_mobile_idx' => ['mobile'],
            'customers_address_zip_idx' => ['address_zip'],
            // Neukunden-Bericht (Zeitraum) und Dubletten-Scan (latest()).
            'customers_created_at_idx' => ['created_at'],
            // Neukunden-Bericht: Anleger und Werber.
            'customers_created_by_idx' => ['created_by'],
            'customers_acquired_by_idx' => ['acquired_by'],
            'customers_acquired_by_partner_idx' => ['acquired_by_partner_id'],
        ],

        // --- contracts -------------------------------------------------
        // customer_id/status und customer_id/stage bestehen bereits.
        'contracts' => [
            // Ablauf-/Kuendigungslaeufe scannen bisher den ganzen Bestand.
            'contracts_end_date_idx' => ['end_date'],
            'contracts_status_end_date_idx' => ['status', 'end_date'],
            // Zuordnung eines Dokuments zum Bestandsvertrag (gleiche
            // Gesellschaft) und Sortierung der Vertragsliste.
            'contracts_insurer_idx' => ['insurer'],
        ],

        // --- documents -------------------------------------------------
        'documents' => [
            // Deckt BEIDES ab: Dokumente einer Kundenakte (nach Datum) und den
            // Eingang (customer_id IS NULL, latest()). Ersetzt den reinen
            // customer_id-Index, der unten entfernt wird.
            'documents_customer_created_idx' => ['customer_id', 'created_at'],
            // Eingangs-Sicht der Mitarbeiter ohne Gesamtsicht.
            'documents_uploaded_by_idx' => ['uploaded_by'],
        ],

        // --- tickets ---------------------------------------------------
        'tickets' => [
            // Vorgangsliste: Status-Filter + Standardsortierung created_at.
            // Ersetzt den reinen status-Index (unten entfernt).
            'tickets_status_created_idx' => ['status', 'created_at'],
            // "Alle"-Reiter sortiert ohne Status-Filter.
            'tickets_created_at_idx' => ['created_at'],
            // "Meine Vorgaenge" / "Nicht zugewiesen".
            'tickets_assigned_to_idx' => ['assigned_to'],
            // Ueberfaellig-Zaehler (Bereichsbedingung auf due_at).
            'tickets_due_at_idx' => ['due_at'],
        ],

        // --- Beziehungen auf customer_id/contract_id ohne Index ---------
        // Jede hasMany-Relation fragt genau ueber diese Spalte ab.
        'customer_vehicles' => ['customer_vehicles_customer_idx' => ['customer_id']],
        'customer_family' => ['customer_family_customer_idx' => ['customer_id']],
        // notes()/timeline() sortieren zusaetzlich nach created_at (latest()).
        'customer_notes' => ['customer_notes_customer_created_idx' => ['customer_id', 'created_at']],
        'customer_timeline' => ['customer_timeline_customer_created_idx' => ['customer_id', 'created_at']],
        'appointments' => ['appointments_customer_idx' => ['customer_id']],
        'favorite_customers' => ['favorite_customers_customer_idx' => ['customer_id']],
        'customer_views' => ['customer_views_customer_idx' => ['customer_id']],
        'email_messages' => ['email_messages_customer_idx' => ['customer_id']],
        'document_requests' => ['document_requests_contract_idx' => ['contract_id']],
        'tasks' => ['tasks_contract_idx' => ['contract_id']],
        'commissions' => ['commissions_contract_idx' => ['contract_id']],
        'provisions' => ['provisions_customer_idx' => ['customer_id']],
        // Provisions-/Abrechnungsimporte: wachsen je Datei um Tausende Zeilen.
        'contract_commissions' => ['contract_commissions_customer_idx' => ['customer_id']],
        'commission_import_rows' => [
            'commission_import_rows_customer_idx' => ['customer_id'],
            'commission_import_rows_contract_idx' => ['contract_id'],
        ],
        'commission_reference_links' => ['commission_reference_links_contract_idx' => ['contract_id']],
        'vermittler_settlements' => [
            'vermittler_settlements_customer_idx' => ['customer_id'],
            'vermittler_settlements_contract_idx' => ['contract_id'],
        ],
        'vermittler_match_events' => ['vermittler_match_events_contract_idx' => ['contract_id']],
        'commission_audit_logs' => ['commission_audit_logs_contract_idx' => ['contract_id']],
        // Rueckrichtung der "kein Duplikat"-Markierung: der bestehende
        // UNIQUE(a,b) traegt nur die Suche ueber a.
        'customer_relationships' => ['customer_relationships_b_idx' => ['customer_b_id']],
    ];

    /**
     * Ueberfluessige Einzelspalten-Indexe: ihre Spalte ist bereits die
     * FUEHRENDE Spalte eines zusammengesetzten Index, der dieselben Abfragen
     * bedient. Ein zweiter Baum darueber kostet nur Schreibzeit und Platz.
     * Die ersten beiden Eintraege werden durch die Indexe oben ersetzt, die
     * uebrigen waren schon vorher doppelt vorhanden.
     *
     * @var array<string, array{0: string, 1: array<int, string>}>
     */
    private const REDUNDANT = [
        // Neu gedeckt durch (customer_id, created_at) bzw. (status, created_at).
        'documents_customer_idx' => ['documents', ['customer_id']],
        'tickets_status_idx' => ['tickets', ['status']],
        // Schon vorher doppelt: fuehrende Spalte eines bestehenden Composite.
        'vermittler_settlements_match_result_index' => ['vermittler_settlements', ['match_result']],
        'contract_commissions_status_index' => ['contract_commissions', ['status']],
        'commission_reference_links_pool_index' => ['commission_reference_links', ['pool']],
        'external_references_referenceable_type_referenceable_id_index' => [
            'external_references', ['referenceable_type', 'referenceable_id'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $name => $columns) {
                if (Schema::hasIndex($table, $name)) {
                    continue;
                }
                // Fehlende Spalte (aelterer Bestand) darf die Migration nicht
                // scheitern lassen - dann entsteht der Index einfach nicht.
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        continue 2;
                    }
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }

        foreach (self::REDUNDANT as $name => [$table, $columns]) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::REDUNDANT as $name => [$table, $columns]) {
            if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
                continue;
            }
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
        }

        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
