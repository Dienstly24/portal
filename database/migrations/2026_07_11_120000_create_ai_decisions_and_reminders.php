<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: KI-Entscheidungsprotokoll (Architekturplan Abschnitte 12/13/19)
 * + Erinnerungs-Tracking für Dokumentenanfrage-Fristen.
 *
 * ai_decisions ist das Freigabe-Gateway für KI-Vorschläge: JEDE
 * Modellausgabe landet hier als 'suggested' und wird erst durch eine
 * Mitarbeiter-Entscheidung wirksam - nie direkt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('email_message_id')->nullable();
            $table->foreign('email_message_id')->references('id')->on('email_messages')->cascadeOnDelete();
            $table->string('skill');           // z. B. classify_email
            $table->string('model')->nullable();
            $table->string('input_hash', 64);  // SHA-256 statt Klartext (Datenminimierung)
            $table->json('output');            // validiertes Ergebnis-JSON
            $table->unsignedTinyInteger('confidence')->nullable(); // 0-100
            $table->string('status')->default('suggested'); // suggested|accepted|rejected
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['skill', 'status']);
        });

        Schema::table('document_requests', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('reviewed_at');
            $table->timestamp('overdue_notified_at')->nullable()->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->dropColumn(['reminder_sent_at', 'overdue_notified_at']);
        });
        Schema::dropIfExists('ai_decisions');
    }
};
