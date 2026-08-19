<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zwei-Faktor-Anmeldung fuer die Beraterwelt (Betreiber-Vorgabe
 * 18.08.2026 "Sicherheit sehr hoch").
 *
 * Bisher stand hinter /admin genau ein Passwort - und dahinter die
 * vollstaendigen Kunden-, Gesundheits- und Bankdaten sowie Ausweiskopien.
 * Ein einziges abgefischtes Passwort haette gereicht. ExtraBasicAuth war
 * ausdruecklich nur die Uebergangsloesung "bis echtes 2FA existiert".
 *
 * Spalten:
 * - two_factor_secret: das gemeinsame Geheimnis, VERSCHLUESSELT abgelegt
 *   (Cast im Modell). Wer die Datenbank liest, kann damit nichts anfangen.
 * - two_factor_recovery_codes: Ersatzcodes, verschluesselt. Jeder gilt
 *   genau einmal - Telefon verloren heisst nicht Konto verloren.
 * - two_factor_confirmed_at: erst gesetzt, wenn der Mitarbeiter EINEN
 *   gueltigen Code eingegeben hat. Ohne diese Bestaetigung gilt die
 *   Einrichtung als nicht abgeschlossen; sonst sperrt sich jemand aus,
 *   der den QR-Code zwar angezeigt, aber nie gescannt hat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('must_change_password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
