<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ausbau des KI-Assistenten zum Verkaufs- und Serviceassistenten
 * (Betreiber-Auftrag 18.08.2026, Plan in docs/KI_VERKAUFSASSISTENT_PLAN.md).
 *
 * Additiv: keine bestehende Spalte wird geaendert oder entfernt. Der
 * bisherige Assistent laeuft mit den Voreinstellungen unveraendert weiter
 * (state = 'NEW', channel = 'portal').
 *
 * 1) ai_conversations bekommt den GESPRAECHSZUSTAND (Abschnitt 12), den
 *    gesammelten Kontext (14) und die Stoerungsanzeige (13).
 * 2) ai_leads      Interessent aus dem Website-Assistenten (20).
 * 3) ai_offers     Angebote je Gespraech, vom Mitarbeiter hinterlegt (5/7).
 * 4) ai_conversation_events  Auditlog getrennt vom Chattext (23).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Ein Interessent ist (noch) KEIN Kunde. Wird aus ihm einer,
            // zeigt customer_id darauf - der Lead bleibt als Herkunft.
            $table->uuid('customer_id')->nullable();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();

            $table->string('source', 30)->default('website');
            $table->string('intent', 40)->nullable();
            $table->string('state', 40)->default('NEW');
            $table->string('service', 60)->nullable();

            // Kontaktangaben, soweit der Besucher sie genannt hat. Kann
            // Personendaten enthalten -> verschluesselt at rest.
            $table->text('contact')->nullable();
            $table->text('address')->nullable();
            $table->text('collected')->nullable();
            // Gespraechsverlauf des Besuchers. Bewusst AM LEAD und nicht in
            // customer_messages: dort haengt jede Zeile an einer Kundenakte,
            // die es hier (noch) nicht gibt.
            $table->text('transcript')->nullable();

            $table->string('customer_status', 30)->nullable();
            $table->string('verification_status', 30)->nullable();
            $table->string('next_action', 120)->nullable();
            $table->uuid('selected_offer_id')->nullable();

            $table->unsignedBigInteger('assigned_employee_id')->nullable();
            $table->foreign('assigned_employee_id')->references('id')->on('users')->nullOnDelete();
            $table->uuid('ticket_id')->nullable();

            $table->timestamps();
            $table->index(['state', 'created_at']);
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            // Abschnitt 12: der Zustand ist ein Feld, nicht der Verlauf.
            $table->string('state', 40)->default('NEW')->after('customer_id');
            $table->string('intent', 40)->nullable()->after('state');
            $table->string('category', 40)->nullable()->after('intent');
            $table->string('channel', 20)->default('portal')->after('category');
            $table->uuid('lead_id')->nullable()->after('channel');

            // Abschnitt 14: gesammelte Angaben ueberleben Fehler und
            // Neustarts. Enthaelt Kundenangaben -> verschluesselt.
            $table->text('collected')->nullable()->after('summary');
            $table->string('verification_status', 30)->nullable()->after('collected');
            $table->uuid('selected_offer_id')->nullable()->after('verification_status');

            // Abschnitt 13: eine Stoerung darf nie unsichtbar sein.
            $table->string('status', 20)->default('running')->after('selected_offer_id');
            $table->string('paused_reason', 200)->nullable()->after('status');
            $table->string('last_successful_step', 60)->nullable()->after('paused_reason');
            $table->string('current_step', 60)->nullable()->after('last_successful_step');
            $table->string('next_action', 120)->nullable()->after('current_step');
            $table->timestamp('last_error_at')->nullable()->after('next_action');

            $table->index(['state', 'updated_at']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('ai_offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id')->nullable();
            $table->foreign('conversation_id')->references('id')->on('ai_conversations')->cascadeOnDelete();
            $table->uuid('lead_id')->nullable();
            $table->foreign('lead_id')->references('id')->on('ai_leads')->cascadeOnDelete();

            // Kurzkennung im Gespraech ("A", "B") - so spricht der Kunde
            // darueber, und so vergleicht die KI.
            $table->string('label', 10);
            $table->string('provider', 120)->nullable();
            $table->string('product', 160);
            $table->string('speed', 60)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('price_period', 20)->default('monat');
            $table->unsignedSmallInteger('duration_months')->nullable();
            $table->text('terms')->nullable();

            // Wer hat das Angebot hinterlegt (Phase 1 immer ein Mensch) und
            // woher kommt es (Phase 2: 'api').
            $table->string('origin', 20)->default('employee');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->timestamp('presented_at')->nullable();
            $table->timestamp('selected_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'label']);
        });

        Schema::create('ai_conversation_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id')->nullable();
            $table->foreign('conversation_id')->references('id')->on('ai_conversations')->cascadeOnDelete();
            $table->uuid('lead_id')->nullable();
            $table->foreign('lead_id')->references('id')->on('ai_leads')->cascadeOnDelete();

            $table->string('event', 60);
            $table->string('actor', 20)->default('ai');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->string('from_state', 40)->nullable();
            $table->string('to_state', 40)->nullable();
            // Kann Feldnamen und Ergebnisse enthalten, nie Rohwerte
            // sensibler Angaben -> trotzdem verschluesselt.
            $table->text('detail')->nullable();

            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_events');
        Schema::dropIfExists('ai_offers');

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropIndex(['state', 'updated_at']);
            $table->dropIndex(['status', 'updated_at']);
            $table->dropColumn([
                'state', 'intent', 'category', 'channel', 'lead_id',
                'collected', 'verification_status', 'selected_offer_id',
                'status', 'paused_reason', 'last_successful_step',
                'current_step', 'next_action', 'last_error_at',
            ]);
        });

        Schema::dropIfExists('ai_leads');
    }
};
