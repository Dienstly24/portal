<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HISTORISCH oder LIVE - die eine Unterscheidung, auf der der
 * Verlaufs-Import steht (Betreiber-Vorgabe 09.09.2026).
 *
 * Beim Coexistence-Onboarding liefert Meta den bisherigen Schriftwechsel
 * aus der WhatsApp Business App nach. Diese Nachrichten SIND echte
 * Nachrichten - sie gehoeren in die Unterhaltung, in die Suche und in
 * den Zusammenhang, den ein Mitarbeiter braucht.
 *
 * Aber sie sind KEINE Ereignisse: sie sind vor Wochen passiert. Ohne
 * diese Spalte waere jede nachgelieferte Kundennachricht ein frischer
 * Eingang - mit Ungelesen-Zaehler, Glocke, Zuweisung und einer
 * KI-Antwort auf eine Frage von vor drei Monaten. Der Kunde bekaeme
 * beim Anschalten der Anbindung eine Antwortlawine.
 *
 * Standard ist `live`: der gesamte Bestand ist live entstanden, und ein
 * neuer Weg muss sich ausdruecklich als historisch ausweisen.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_messages', function (Blueprint $table) {
            $table->string('source', 20)->default('live')->after('message_type');
            // Die Unterhaltungs-Ansicht trennt nach dieser Spalte, die
            // Inbox zaehlt ueber sie - beides mit einer Bedingung, die
            // ohne Index ueber den ganzen Nachrichtenbestand liefe.
            $table->index(['conversation_id', 'source'], 'customer_messages_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('customer_messages', function (Blueprint $table) {
            $table->dropIndex('customer_messages_source_idx');
            $table->dropColumn('source');
        });
    }
};
