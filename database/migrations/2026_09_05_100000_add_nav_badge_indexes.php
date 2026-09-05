<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UX-3: Indexe fuer die zehn Zaehl-Abfragen der Seitenleiste.
 *
 * WARUM GERADE DIESE: die Badges der Beraterwelt sind die einzigen Abfragen,
 * die auf JEDER Seite laufen - vor dem Zwischenspeicher zehnmal je
 * Seitenaufruf, danach immer noch alle 30 Sekunden je Mitarbeiter. Eine
 * Abfrage in dieser Frequenz ohne Index ist der teuerste Full Table Scan im
 * ganzen Projekt, und sie waechst mit dem Bestand.
 *
 * Die Spaltenreihenfolge folgt der Abfrage in `App\Support\Navigation\
 * NavBadges::compute()`: erst die Gleichheitsbedingung, dann der Bereich
 * bzw. das Feld, das die Zeilenmenge am staerksten einschraenkt.
 *
 * BEWUSST NICHT indiziert:
 * - `documents.customer_id IS NULL` (Eingang) - der Bestand ohne Kunde ist
 *   per Definition klein und schrumpft bei jeder Zuordnung; der bestehende
 *   Index (customer_id, created_at) aus ARCH-1 traegt die Abfrage bereits.
 * - `tickets(status, created_at)` und `documents(uploaded_by)` - bestehen
 *   schon aus ARCH-1, ein zweiter Baum darueber kostet nur Schreibzeit.
 * - Die `whereIn(customer_id, ...)`-Einschraenkung der portfolio-begrenzten
 *   Mitarbeiter: sie steht in JEDER dieser Abfragen an zweiter Stelle. Sie
 *   als fuehrende Spalte zu indizieren wuerde die Abfrage des Admins (ohne
 *   diese Bedingung) verschlechtern, und die Kundenliste ist kurz.
 *
 * Idempotent und auf SQLite wie MySQL lauffaehig - es entstehen nur Indexe,
 * keine Spalten und keine Daten werden veraendert.
 */
return new class extends Migration {
    /** @var array<string, array<string, array<int, string>>> */
    private const INDEXES = [
        // Badge "Termine heute": whereDate(starts_at) + status.
        // Der bestehende appointments_customer_idx traegt nur die Kundenakte.
        'appointments' => [
            'appointments_starts_status_idx' => ['starts_at', 'status'],
        ],

        // Badge "Aufgaben": assigned_to + status != done + due_date <= heute.
        // assigned_to zuerst: es schneidet die Tabelle auf einen Mitarbeiter.
        'tasks' => [
            'tasks_assigned_due_idx' => ['assigned_to', 'due_date'],
        ],

        // Badge "Kundenchat": from_staff = false + read_at IS NULL.
        // Zwei sehr selektive Bedingungen, bisher ohne jeden Index.
        'customer_messages' => [
            'customer_messages_unread_idx' => ['from_staff', 'read_at'],
        ],

        // Badge "E-Mail-Vorschlaege": match_status = 'suggested'.
        'email_messages' => [
            'email_messages_match_status_idx' => ['match_status'],
        ],

        // Badge "Kundenaenderungen": status = 'pending' (+ Portfolio).
        'customer_change_requests' => [
            'customer_change_requests_status_idx' => ['status'],
        ],

        // Badge "Anforderungen": status = 'uploaded' (+ Portfolio seit UX-3).
        'document_requests' => [
            'document_requests_status_idx' => ['status'],
        ],

        // Badge "Gutschriften": status = 'pending_review'.
        'commissions' => [
            'commissions_status_idx' => ['status'],
        ],

        // Badge "Team-Chat": user_id + Vergleich gegen last_read_at.
        'internal_conversation_participants' => [
            'internal_conv_participants_user_idx' => ['user_id', 'last_read_at'],
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
    }

    public function down(): void
    {
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
