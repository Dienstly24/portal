<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sichtbare Fehler (Systemzustand-Seite, Teil 2).
 *
 * Ein 500er trifft heute einen echten Nutzer und landet in
 * storage/logs/laravel.log - einer Datei, die im Alltag niemand oeffnet.
 * Der Betreiber erfaehrt davon nur, wenn sich jemand beschwert. Diese
 * Tabelle macht daraus eine Zahl auf einer Seite.
 *
 * Bewusst KEIN Fehler-Archiv: eine Zeile je FINGERABDRUCK (Klasse + Datei +
 * Zeile), die bei jedem weiteren Auftreten nur hochgezaehlt wird. Ein Fehler,
 * der 5000-mal auftritt, ist EIN Problem - und die Tabelle bleibt klein.
 *
 * DATENSCHUTZ: gespeichert werden nur technische Angaben. NIE der
 * Request-Inhalt (Formularfelder, Query, Header, Cookies) und NIE die
 * IP-Adresse - dort stuenden sonst Kundendaten in einer Tabelle, die
 * niemand als personenbezogen erwartet.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('error_events', function (Blueprint $table) {
            $table->id();
            // sha1(Klasse|Datei|Zeile) - der Schluessel, ueber den
            // zusammengefasst wird.
            $table->string('fingerprint', 40)->unique();
            $table->string('exception_class', 191);
            // Gekuerzt: eine Meldung kann Datenbank-Ausschnitte enthalten.
            $table->string('message', 500);
            $table->string('file', 500)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('route', 191)->nullable();
            $table->string('method', 10)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            // Wer war zuletzt betroffen - hilft beim Nachfragen. Kein
            // weiterer Personenbezug.
            $table->unsignedBigInteger('last_user_id')->nullable();
            // Erledigt-Markierung durch einen Menschen; ein erneutes
            // Auftreten oeffnet den Eintrag wieder.
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamps();

            $table->index('last_seen_at');
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_events');
    }
};
