<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 der Unified Conversation Platform - die drei Luecken aus der
 * Bestandsaufnahme vom 17.09.2026.
 *
 * REIN ADDITIV: eine neue Tabelle, drei neue Spalten, kein bestehender
 * Datensatz wird angefasst und kein bestehender Lesepfad geaendert.
 */
return new class extends Migration {
    public function up(): void
    {
        /*
         * INTERNE NOTIZ AN DER UNTERHALTUNG (Auftrag Abschnitt 13).
         *
         * WARUM EINE EIGENE TABELLE und nicht ein Feld an
         * `customer_messages`: die Trennung "erreicht den Kunden nie"
         * muss STRUKTURELL sein, nicht durch eine Bedingung. Eine Notiz
         * in derselben Tabelle wie die Nachrichten waere nur so lange
         * unsichtbar, wie jede Abfrage im Portal an das `where` denkt -
         * und die naechste neue Abfrage denkt nicht daran. Hier kann das
         * Kundenportal die Notiz gar nicht laden: es kennt die Tabelle
         * nicht.
         *
         * WARUM NICHT `customer_notes`: die haengen am KUNDEN. Eine
         * Notiz zum Vorgang ("wartet auf Bestaetigung der Werkstatt")
         * gehoert an die Unterhaltung; am Kunden stuende sie noch in
         * zwei Jahren.
         */
        Schema::create('conversation_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('conversation_id');
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            // Der Urheber. nullOnDelete: scheidet ein Mitarbeiter aus,
            // bleibt die Notiz - sie ist Teil des Vorgangs, nicht seine
            // Privatsache.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');

            /*
             * Sichtbarkeit fuer den SPAETEREN externen Support (Phase 10
             * der Planung). `internal` = nur eigene Leute; `support` =
             * auch der beauftragte Dienstleister.
             *
             * Die Spalte entsteht JETZT, obwohl es die Rolle noch nicht
             * gibt: sie nachtraeglich einzufuehren hiesse, den gesamten
             * Altbestand an Notizen in einen Zustand zu setzen, den
             * niemand geprueft hat. Die strengere Vorgabe kostet heute
             * nichts und ist spaeter nicht mehr nachzuholen.
             */
            $table->string('visibility', 20)->default('internal');

            // BEWUSST NUR created_at, kein updated_at: eine interne
            // Notiz wird nicht still gepflegt. Eine Spalte, die es nicht
            // gibt, kann auch nicht heimlich beschrieben werden -
            // dieselbe Regel wie bei `signature_events`.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'created_at']);
        });

        /*
         * HERKUNFT DER KUNDENZUORDNUNG (Auftrag Abschnitt 8).
         *
         * Bisher sah man einer Kanal-Identitaet nicht an, WIE sie
         * entstanden ist. Ein Mensch, der die Akte ausgewaehlt hat, und
         * eine Telefonnummer, die zufaellig zu genau einem Kunden
         * passte, standen als derselbe Datensatz da - obwohl das eine
         * ein Beleg und das andere ein Indiz ist.
         *
         * BEWUSST SPALTEN statt einer zweiten Tabelle: die Identitaet
         * IST die Zuordnung, es gibt genau eine Zeile je (Konto,
         * Kennung). Eine Tabelle `identity_links` daneben waere eine
         * zweite Quelle fuer dieselbe Aussage und koennte auseinander-
         * laufen - dieselbe Ueberlegung wie bei der Signaturgruppe
         * (10.09.2026).
         */
        Schema::table('customer_channel_identities', function (Blueprint $table) {
            // identity | phone_exact | email_exact | manual
            // NULL = Altbestand: die Herkunft wurde damals nicht
            // vermerkt. Bewusst NICHT rueckwirkend als "ungeprueft"
            // gesetzt - das haette den gesamten Bestand mit einer
            // Warnung versehen, die niemand veranlasst hat, und die
            // echten Faelle waeren darin untergegangen.
            $table->string('match_method', 20)->nullable()->after('external_username');
            $table->foreignId('verified_by')->nullable()->after('match_method')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_notes');

        Schema::table('customer_channel_identities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['match_method', 'verified_at']);
        });
    }
};
