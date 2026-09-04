<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vormerkung einer Selbst-Registrierung (Audit SEC-1).
 *
 * Bisher legte POST /register sofort einen vollwertigen User UND eine
 * Kundenakte MIT Kundennummer an und meldete den Absender direkt an -
 * ohne dass jemals bewiesen war, dass die E-Mail-Adresse ueberhaupt dem
 * Absender gehoert. Ein Bot konnte damit in Serie echte Kundenakten
 * erzeugen und den laufenden Nummernkreis (JJ + 5-stellig) belegen.
 *
 * Die Vormerkung ist der Zwischenzustand, den es vorher nicht gab: hier
 * liegen die Angaben, bis der Bestaetigungslink geklickt wurde. Erst
 * dann - und nur dann - entstehen User, Kundenakte und Kundennummer.
 *
 * Bewusst NICHT ueber `users.email_verified_at` geloest: dann existierte
 * das Konto (und damit ein Login-Ziel und ein Enumerationsziel) schon vor
 * der Bestaetigung, und alle ueber PortalAccessService eingeladenen
 * Bestandskunden - die sich nie selbst registriert haben - waeren
 * schlagartig "unbestaetigt" gewesen.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pending_registrations', function (Blueprint $table) {
            $table->id();

            // Die Adresse, an die der Bestaetigungslink geht.
            $table->string('email')->unique();

            $table->string('first_name', 120);
            $table->string('last_name', 120);
            $table->date('birth_date')->nullable();

            // Bereits gehasht (bcrypt). Es liegt zu keinem Zeitpunkt ein
            // Klartext-Passwort in der Datenbank.
            $table->string('password');

            // Nur der HASH des Bestaetigungstokens. Wer die Tabelle liest,
            // kann damit keinen fremden Link nachbauen - dieselbe Regel wie
            // bei password_reset_tokens.
            $table->string('token_hash', 64)->index();

            // Wunsch aus dem Formular; die eigentliche Einwilligung wird
            // erst bei der Bestaetigung erfasst (mit der IP von DANN).
            $table->boolean('email_consent')->default(false);
            $table->string('preferred_lang', 5)->default('de');

            // Nachvollziehbarkeit + Missbrauchserkennung.
            $table->string('register_ip', 45)->nullable();

            // Wie oft wurde der Link schon erneut angefordert? Deckelt das
            // Zuspammen einer fremden Adresse ueber "erneut senden".
            $table->unsignedSmallInteger('send_count')->default(1);
            $table->timestamp('last_sent_at')->nullable();

            // Gueltigkeit des Links. Abgelaufene Vormerkungen raeumt
            // `registrierungen:aufraeumen` weg - danach ist die Adresse
            // wieder frei.
            $table->timestamp('expires_at')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_registrations');
    }
};
