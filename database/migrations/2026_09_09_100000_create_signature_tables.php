<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Natives E-Signatur-Modul (Betreiber-Auftrag 09.09.2026).
 *
 * DER KERNGEDANKE STEHT IM SCHEMA: eine Signaturanfrage ist ein EIGENES
 * Geschaeftsobjekt, kein Anhaengsel des Kunden. `customer_id` und
 * `contract_id` sind deshalb NULLBAR - und das ist keine Bequemlichkeit,
 * sondern die Anforderung: der Betrieb schickt regelmaessig etwas zur
 * Unterschrift, BEVOR es einen Kunden gibt (Interessent, Vermittler,
 * Zeuge). Wer die Zuordnung zur Bedingung macht, zwingt den Mitarbeiter,
 * vor dem Versand eine Kundenakte anzulegen - und erzeugt damit genau die
 * Karteileichen, die das Portal an anderer Stelle muehsam wieder
 * zusammenfuehrt. Zugeordnet wird NACH dem Unterschreiben, von einem
 * Menschen, per Klick.
 *
 * Bewusst KEINE zweite Dokumententabelle: das fertige, unterschriebene PDF
 * wird bei der Zuordnung ein ganz normales `documents`-Objekt und erscheint
 * damit von selbst in der Kundenakte, im Portal und in der Suche. Solange es
 * keinen Kunden gibt, liegt es unter seiner Signaturanfrage - dort gehoert
 * es hin, denn ohne Akte gibt es keine Akte, in die es koennte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('status', 24)->default('draft');

            // Optionale Bindung an den Bestand. NULL ist der Normalfall bei
            // einer eigenstaendigen Anfrage und bleibt es, bis ein Mensch
            // zuordnet.
            $table->uuid('customer_id')->nullable();
            $table->uuid('contract_id')->nullable();
            $table->uuid('completed_document_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Das ORIGINAL wird nie veraendert - es ist der Bezugspunkt jeder
            // spaeteren Pruefung. Der Hash steht daneben, damit sich das
            // belegen laesst und nicht bloss behaupten.
            $table->string('original_path');
            $table->string('original_name');
            $table->char('original_hash', 64);
            $table->unsignedBigInteger('original_size')->default(0);
            $table->unsignedSmallInteger('page_count')->default(1);

            $table->string('signed_path')->nullable();
            $table->char('signed_hash', 64)->nullable();
            $table->unsignedBigInteger('signed_size')->nullable();

            // 'sequential' = einer nach dem anderen, 'parallel' = alle sofort.
            $table->string('signing_order', 16)->default('sequential');
            $table->boolean('require_email_verification')->default(true);

            // Freier Zusatztext, den der Unterzeichner VOR dem Bestaetigen
            // sieht. Konfigurierbar, weil die rechtliche Einordnung je
            // Anwendungsfall verschieden ist - das System behauptet von sich
            // aus KEINE qualifizierte elektronische Signatur.
            $table->text('consent_text')->nullable();

            $table->string('document_type', 60)->nullable();
            $table->string('reference', 120)->nullable();
            $table->text('note')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('customer_id');
            $table->index('contract_id');
            $table->index('created_by');
            $table->index('expires_at');
        });

        Schema::create('signature_signers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('signature_request_id');
            $table->string('name');
            $table->string('email');
            $table->unsignedSmallInteger('signing_order')->default(1);
            $table->string('status', 24)->default('pending');

            // NUR der Hash des Zugangs-Tokens. Wer die Datenbank liest, kann
            // damit KEINE Unterschriftsseite oeffnen - genau das ist der
            // Zweck. Derselbe Grundsatz wie beim Abmelde- und beim
            // Registrierungs-Token.
            $table->char('token_hash', 64)->nullable()->unique();
            $table->timestamp('token_created_at')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('token_revoked_at')->nullable();

            // Zweiter Schritt: ein Einmal-Code an dieselbe Adresse. Er belegt,
            // dass die Person Zugriff auf das Postfach hat, an das eingeladen
            // wurde - ein weitergeleiteter Link allein belegt das nicht.
            $table->char('verification_hash', 64)->nullable();
            $table->timestamp('verification_expires_at')->nullable();
            $table->unsignedTinyInteger('verification_attempts')->default(0);
            $table->timestamp('verified_at')->nullable();

            $table->timestamp('invited_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->unsignedSmallInteger('reminder_count')->default(0);
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('decline_reason', 500)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->foreign('signature_request_id')->references('id')->on('signature_requests')->cascadeOnDelete();
            $table->index(['signature_request_id', 'signing_order']);
            $table->index('email');
        });

        Schema::create('signature_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('signature_request_id');
            $table->uuid('signature_signer_id')->nullable();
            $table->string('type', 24);
            $table->unsignedSmallInteger('page')->default(1);

            // ANTEILIG zur Seite (0..1), nicht in Pixeln: der Editor zeigt die
            // Seite je nach Bildschirm verschieden gross. Ein Pixelwert waere
            // auf dem Telefon ein anderes Feld als auf dem Bildschirm.
            $table->decimal('pos_x', 8, 6)->default(0);
            $table->decimal('pos_y', 8, 6)->default(0);
            $table->decimal('width', 8, 6)->default(0.2);
            $table->decimal('height', 8, 6)->default(0.05);

            $table->boolean('required')->default(true);
            $table->string('label', 120)->nullable();
            $table->text('value')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->foreign('signature_request_id')->references('id')->on('signature_requests')->cascadeOnDelete();
            $table->foreign('signature_signer_id')->references('id')->on('signature_signers')->nullOnDelete();
            $table->index(['signature_request_id', 'page']);
        });

        // AUDIT: nur anlegen, nie aendern. Deshalb kein updated_at - eine
        // Spalte, die es nicht gibt, kann auch nicht still gepflegt werden.
        // Die Zeilen haengen mit nullOnDelete an Anfrage und Unterzeichner:
        // die Historie ueberlebt das Loeschen des Vorgangs, so wie bei den
        // Vermittler-Zuordnungsereignissen.
        Schema::create('signature_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('signature_request_id')->nullable();
            $table->uuid('signature_signer_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 48);
            $table->string('actor', 160)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('signature_request_id')->references('id')->on('signature_requests')->nullOnDelete();
            $table->foreign('signature_signer_id')->references('id')->on('signature_signers')->nullOnDelete();
            $table->index(['signature_request_id', 'created_at']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_events');
        Schema::dropIfExists('signature_fields');
        Schema::dropIfExists('signature_signers');
        Schema::dropIfExists('signature_requests');
    }
};
