<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Document Upload & KI-Analyse:
 * - documents bekommt KI-Felder (Status, erkannter Typ, extrahierte Daten)
 *   und page_count fuer Mehrseiten-Scans.
 * - customer_id wird nullable: Mitarbeiter koennen Dokumente ohne
 *   Kundenzuordnung in den Dokumenten-Eingang hochladen; die Zuordnung
 *   passiert nach der KI-Analyse (Matching + Freigabe).
 * - category wird vom Alt-Enum auf String umgestellt (das Enum aus der
 *   Basis-Migration kannte police/identity/claim nicht).
 * - ai_decisions bekommt document_id, damit Dokument-Analysen im selben
 *   Freigabe-Gateway protokolliert werden wie E-Mail-Klassifikationen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // none = klassischer Upload ohne Analyse; pending -> processing -> done | failed
            $table->string('ai_status', 12)->default('none')->after('file_size');
            $table->string('ai_type', 40)->nullable()->after('ai_status');
            $table->unsignedTinyInteger('ai_confidence')->nullable()->after('ai_type');
            $table->string('ai_summary', 500)->nullable()->after('ai_confidence');
            // text statt json: der Inhalt wird verschluesselt gespeichert
            // (encrypted:array-Cast), da er IBAN/Versichertennummern
            // enthalten kann - gleiche Schutzstufe wie die Kundenfelder.
            $table->text('ai_extracted')->nullable()->after('ai_summary');
            $table->string('ai_error', 300)->nullable()->after('ai_extracted');
            $table->timestamp('ai_processed_at')->nullable()->after('ai_error');
            $table->unsignedSmallInteger('page_count')->nullable()->after('ai_processed_at');

            $table->index('ai_status');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('customer_id')->nullable()->change();
            $table->string('category', 30)->change();
        });

        Schema::table('ai_decisions', function (Blueprint $table) {
            $table->uuid('document_id')->nullable()->after('email_message_id');
            // nullOnDelete statt cascade: das Freigabe-Protokoll bleibt als
            // Audit-Trail erhalten, auch wenn das Dokument geloescht wird.
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_decisions', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
            $table->dropIndex(['document_id']);
            $table->dropColumn('document_id');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['ai_status']);
            $table->dropColumn([
                'ai_status', 'ai_type', 'ai_confidence', 'ai_summary',
                'ai_extracted', 'ai_error', 'ai_processed_at', 'page_count',
            ]);
        });
        // customer_id bleibt nullable und category bleibt String: ein
        // verlustfreies Zurueckdrehen waere bei vorhandenen Eingangs-
        // Dokumenten (customer_id NULL) nicht moeglich.
    }
};
