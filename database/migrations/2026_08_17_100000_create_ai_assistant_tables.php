<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KI-Kundenassistent (Betreiber-Auftrag 17.08.2026, Spezifikation
 * Abschnitte 15/16/19/22; Plan: docs/KI_KUNDENASSISTENT_INTEGRATIONSPLAN.md).
 *
 * Bewusst NUR Steuer- und Protokolltabellen: Antworten, Vorgaenge und
 * Dokumentenanforderungen des Assistenten laufen in die BESTEHENDEN
 * Tabellen (customer_messages, tickets, document_requests) - derselbe Weg
 * wie bei einem Mitarbeiter. So bleibt die vorhandene Kundenkommunikation
 * unveraendert und nichts muss doppelt gepflegt werden.
 *
 * 1) ai_conversations   Steuerstand je Kunde: KI aktiv / Uebergabe noetig /
 *                       welcher Mitarbeiter hat uebernommen (Abschnitt 16).
 * 2) ai_assistant_logs  Audit jeder KI-Runde (Abschnitt 22) - OHNE
 *                       Roh-Prompt (Datenminimierung, wie bei ai_decisions).
 * 3) ai_knowledge_entries  freigegebene Wissensbasis (Abschnitt 19); was
 *                       hier nicht steht, darf die KI nicht behaupten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Genau EIN Steuerstand je Kunde (Abschnitt 16: "Pro Kunde /
            // Konversation"). Die Unterhaltung selbst ist der Portal-Chat.
            $table->uuid('customer_id')->unique();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();

            // Abschnitt 15/16: solange handover_required gesetzt ist, sendet
            // die KI KEINE weiteren automatischen Antworten - erst wenn ein
            // Mitarbeiter uebernimmt oder die KI bewusst reaktiviert wird.
            $table->boolean('ai_active')->default(true);
            $table->boolean('handover_required')->default(false);
            $table->string('handover_reason', 60)->nullable();
            $table->timestamp('handover_at')->nullable();
            $table->unsignedBigInteger('assigned_employee_id')->nullable();
            $table->foreign('assigned_employee_id')->references('id')->on('users')->nullOnDelete();

            $table->string('last_ai_action', 80)->nullable();
            // Kann Kundendaten enthalten (letzte Antwort / Zusammenfassung
            // fuer den Mitarbeiter) -> verschluesselt at rest.
            $table->text('last_ai_response')->nullable();
            $table->text('summary')->nullable();
            $table->timestamp('last_ai_at')->nullable();

            // Kostenbremse (Abschnitt 30/32): Zaehler der automatischen
            // Antworten; wird zurueckgesetzt, wenn ein Mitarbeiter
            // uebernimmt oder die KI wieder aktiviert wird.
            $table->unsignedInteger('auto_reply_count')->default(0);
            $table->timestamps();

            $table->index(['handover_required', 'updated_at']);
        });

        Schema::create('ai_assistant_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id')->nullable();
            $table->foreign('conversation_id')->references('id')->on('ai_conversations')->nullOnDelete();
            $table->uuid('customer_id')->nullable();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            // Ausloesende Kundennachricht und die erzeugte Antwort. Kein
            // cascadeOnDelete auf die Antwort: das Protokoll ueberlebt eine
            // geloeschte Nachricht (Nachvollziehbarkeit).
            $table->uuid('customer_message_id')->nullable();
            $table->uuid('reply_message_id')->nullable();

            $table->string('intent', 60)->nullable();
            $table->boolean('in_scope')->default(true);
            // answered | refused_out_of_scope | escalated | fallback | skipped
            $table->string('outcome', 40);
            $table->boolean('handover')->default(false);
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->foreign('employee_id')->references('id')->on('users')->nullOnDelete();

            // Welche Tools wurden gerufen, welche Aktionen ausgefuehrt
            // (Abschnitt 22). Klartext-Listen ohne Kundendaten.
            $table->json('tools')->nullable();
            $table->json('actions')->nullable();
            // Feinheiten (Ablehnungsgrund, Fehlermeldung, Kennzahlen) -
            // kann PII-Fragmente enthalten -> verschluesselt.
            $table->text('detail')->nullable();

            $table->string('provider', 30)->nullable();
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['outcome', 'created_at']);
        });

        Schema::create('ai_knowledge_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            // Kategorie zur Pflege (prozess, faq, dokumente, produkt,
            // eskalation) - die Suche laeuft ueber Titel/Inhalt/Stichwoerter.
            $table->string('category', 40)->default('faq');
            $table->text('content');
            // 'de', 'ar', 'en' oder null = sprachneutral/fuer alle.
            $table->string('language', 5)->nullable();
            $table->string('keywords', 500)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['active', 'category']);
        });

        // Kennzeichnung im bestehenden Chat: der Kunde MUSS erkennen, dass
        // zunaechst ein Assistent antwortet (Abschnitt 26), der Mitarbeiter
        // sieht dieselbe Nachricht als KI-Antwort (Abschnitt 27).
        Schema::table('customer_messages', function (Blueprint $table) {
            $table->boolean('ai_generated')->default(false)->after('from_staff');
        });
    }

    public function down(): void
    {
        Schema::table('customer_messages', function (Blueprint $table) {
            $table->dropColumn('ai_generated');
        });
        Schema::dropIfExists('ai_knowledge_entries');
        Schema::dropIfExists('ai_assistant_logs');
        Schema::dropIfExists('ai_conversations');
    }
};
