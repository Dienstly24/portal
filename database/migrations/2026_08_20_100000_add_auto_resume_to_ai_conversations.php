<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wiederaufnahme des Assistenten nach einer Uebernahme
 * (Betreiber-Vorgabe 20.08.2026).
 *
 * Bisher galt: wer uebernimmt, schaltet die KI fuer DEN KUNDEN dauerhaft
 * stumm - auch fuer ein voellig neues Anliegen Wochen spaeter. Gemeldet
 * wurde genau das: der Kunde schreibt am naechsten Tag eine neue Frage
 * und niemand antwortet automatisch.
 *
 * Neu: eine Uebernahme gilt dem VORGANG, nicht dem Kunden. Drei Spalten
 * halten fest, wann die KI von selbst zurueckkommen darf:
 *  auto_resume       false = bewusst dauerhaft aus (Knopf "Dauerhaft aus")
 *  resume_not_before Ruhefrist; jede Mitarbeiter-Nachricht schiebt sie vor
 *  resume_ticket_id  der Vorgang, dessen Abschluss die KI freigibt
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->boolean('auto_resume')->default(true)->after('assigned_employee_id');
            $table->timestamp('resume_not_before')->nullable()->after('auto_resume');
            $table->uuid('resume_ticket_id')->nullable()->after('resume_not_before');
            $table->timestamp('resumed_at')->nullable()->after('resume_ticket_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropColumn(['auto_resume', 'resume_not_before', 'resume_ticket_id', 'resumed_at']);
        });
    }
};
