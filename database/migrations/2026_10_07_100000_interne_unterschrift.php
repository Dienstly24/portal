<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interne Unterschrift (Betreiber-Auftrag 04./07.10.2026, Teil B).
 *
 * Ein Mitarbeiter oder der Geschaeftsfuehrer unterschreibt das Dokument
 * SELBST, bevor es an den Kunden geht. Rein ADDITIV - keine bestehende
 * Zeile wird geaendert:
 *
 *  - users.can_sign_for_company / signatur_funktion: das Recht "Darf fuer
 *    das Unternehmen unterschreiben" und die Funktion, die im Bestaetigungs-
 *    satz steht ("... als Ahmad Albhre, Geschaeftsfuehrer").
 *  - user_signatures: die hinterlegte Unterschrift (und Paraphe) je Person.
 *    Ersetzen ARCHIVIERT die alte Zeile - alte Protokolle verweisen weiter
 *    auf ihren Hash.
 *  - signature_handoffs: "Auf dem Handy unterschreiben" - kurzlebiger,
 *    einmal nutzbarer Zugang, an Konto UND Sitzung gebunden.
 *  - signature_internal_signings: das Protokoll jeder internen Unterschrift
 *    (append-only, kein updated_at): wer, Funktion, Zeit, IP, Geraet,
 *    2FA-Nachweis, Bild-Hash und Erstellungsweg, Dokument-Hash davor/danach.
 *  - signature_fields.internal_user_id/user_signature_id/intern_beschriftung:
 *    das Feld "Meine Unterschrift" gehoert einem MITARBEITER, nicht einem
 *    Unterzeichner.
 *  - signature_requests.zwischenstand_*: das Dokument MIT den internen
 *    Unterschriften. Der Kunde sieht und unterschreibt diese Fassung; das
 *    fertige PDF ist eine Fortschreibung davon (Original -> Zwischenstand
 *    -> Endfassung, drei Hashes).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_sign_for_company')->default(false);
            $table->string('signatur_funktion', 80)->nullable();
        });

        Schema::create('user_signatures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 16); // unterschrift | paraphe
            $table->string('path');
            $table->string('hash', 64);
            $table->string('method', 16); // gezeichnet | hochgeladen | handy_qr
            $table->string('device', 160)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'kind', 'active']);
        });

        Schema::create('signature_handoffs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('session_hash', 64);
            $table->string('kind', 16)->default('unterschrift');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('image_path')->nullable();
            $table->string('device', 160)->nullable();
            $table->timestamps();
        });

        Schema::create('signature_internal_signings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('signature_request_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160);
            $table->string('funktion', 80);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('reauth_method', 24);
            $table->timestamp('reauth_at')->nullable();
            $table->string('image_method', 16);
            $table->string('image_hash', 64);
            $table->uuid('user_signature_id')->nullable();
            $table->string('document_hash_before', 64);
            $table->string('document_hash_after', 64);
            $table->text('bestaetigung');
            $table->json('felder');
            $table->timestamp('created_at')->nullable();
            $table->foreign('signature_request_id')->references('id')->on('signature_requests')->cascadeOnDelete();
            $table->index('signature_request_id');
        });

        Schema::table('signature_fields', function (Blueprint $table) {
            $table->foreignId('internal_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('user_signature_id')->nullable();
            $table->boolean('intern_beschriftung')->default(true);
        });

        Schema::table('signature_requests', function (Blueprint $table) {
            $table->string('zwischenstand_path')->nullable();
            $table->string('zwischenstand_hash', 64)->nullable();
            $table->boolean('send_after_internal')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropColumn(['zwischenstand_path', 'zwischenstand_hash', 'send_after_internal']);
        });
        Schema::table('signature_fields', function (Blueprint $table) {
            $table->dropConstrainedForeignId('internal_user_id');
            $table->dropColumn(['user_signature_id', 'intern_beschriftung']);
        });
        Schema::dropIfExists('signature_internal_signings');
        Schema::dropIfExists('signature_handoffs');
        Schema::dropIfExists('user_signatures');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['can_sign_for_company', 'signatur_funktion']);
        });
    }
};
