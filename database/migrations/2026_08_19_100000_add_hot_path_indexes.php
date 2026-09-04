<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indizes auf den Spalten, die bei fast jedem Aufruf gefiltert werden
 * (Audit 18.08.2026, Punkt B3/H2). Rein additiv - keine Daten werden
 * angefasst, kein Verhalten aendert sich.
 *
 * Warum diese und keine anderen:
 * - users.role: 43 Stellen filtern darauf. Die Tabelle haelt Personal UND
 *   alle Kundenkonten; ohne Index waechst jede Rollenabfrage linear mit
 *   der Kundenzahl.
 * - employee_customers: der Dreh- und Angelpunkt des Portfolio-Modells,
 *   gelesen bei praktisch jedem Admin-Request. (Unter MySQL legt der
 *   Fremdschluessel bereits einen Index an; SQLite tut das NICHT - dort
 *   laufen die Tests, und ein zusammengesetzter Index nuetzt auch MySQL.)
 * - documents/tickets/tasks/ticket_messages: die vier Listen, die mit dem
 *   Datenbestand am schnellsten wachsen.
 *
 * Jeder Schritt ist einzeln abgesichert: bestehende Indizes (aus
 * Fremdschluesseln oder frueheren Migrationen) duerfen die Migration
 * nicht abbrechen.
 */
return new class extends Migration {
    /** @var array<string, array<string, array<int, string>>> Tabelle => Indexname => Spalten */
    private const INDEXES = [
        'users' => [
            'users_role_idx' => ['role'],
        ],
        'employee_customers' => [
            'employee_customers_user_customer_idx' => ['user_id', 'customer_id'],
            'employee_customers_customer_idx' => ['customer_id'],
        ],
        'documents' => [
            'documents_customer_idx' => ['customer_id'],
        ],
        'tickets' => [
            'tickets_customer_idx' => ['customer_id'],
        ],
        'ticket_messages' => [
            'ticket_messages_ticket_idx' => ['ticket_id'],
        ],
        'tasks' => [
            'tasks_assigned_idx' => ['assigned_to'],
            'tasks_customer_idx' => ['customer_id'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $name => $columns) {
                // Fehlt eine Spalte (aelterer Stand), wird der Index
                // uebersprungen statt die Migration zu sprengen.
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        continue 2;
                    }
                }

                try {
                    Schema::table($table, function (Blueprint $blueprint) use ($name, $columns) {
                        $blueprint->index($columns, $name);
                    });
                } catch (Throwable $e) {
                    // Index existiert bereits (z.B. aus einem Fremdschluessel).
                }
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
                try {
                    Schema::table($table, function (Blueprint $blueprint) use ($name) {
                        $blueprint->dropIndex($name);
                    });
                } catch (Throwable $e) {
                    // War nie angelegt - nichts zu tun.
                }
            }
        }
    }
};
