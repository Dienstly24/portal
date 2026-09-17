<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 der Unified Conversation Platform: EINE Unterhaltung kann
 * MEHRERE Kanaele tragen (Auftrag Abschnitte 2 und 7).
 *
 * Bis hierher galt: eine Unterhaltung = ein Kanal. Schreibt derselbe
 * Kunde heute ueber WhatsApp und morgen im Portal, entstehen zwei
 * Unterhaltungen - und der Mitarbeiter sieht die Vorgeschichte nicht,
 * obwohl es dieselbe Sache ist.
 *
 * DREI SCHRITTE, alle additiv:
 *
 * 1. JEDE NACHRICHT TRAEGT IHREN KANAL. Bisher stand er nur an der
 *    Unterhaltung - was genau so lange reicht, wie eine Unterhaltung
 *    einen Kanal hat. Ohne diese Spalte waere nach einer
 *    Zusammenfuehrung nicht mehr feststellbar, WOHER eine Nachricht kam
 *    (Auftrag Abschnitt 7) und WOHIN die Antwort gehoert.
 *
 * 2. `conversation_channels` sagt, WELCHE Kanaele eine Unterhaltung
 *    umfasst - mit Gegenstelle je Kanal (die Rufnummer bei WhatsApp ist
 *    nicht die Kennung im Portal) und mit dem Vermerk, WIE der Kanal
 *    dazukam.
 *
 * 3. `conversations.last_channel_id` haelt den zuletzt benutzten Kanal.
 *    `channel_id` bleibt, bedeutet aber ab jetzt der ERSTE Kanal.
 *
 * Es wird KEINE bestehende Zeile geaendert. Den Bestand traegt ein
 * eigener, wiederholbarer Befehl nach (`messaging:kanal-nachtragen`) -
 * dieselbe Trennung wie bei Omnichannel Phase B.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_messages', function (Blueprint $table) {
            // NULLABLE: der Altbestand hat sie nicht, und der Nachtrag
            // ist ein eigener Schritt. Wo sie fehlt, gilt der Kanal der
            // Unterhaltung (`CustomerMessage::channelId()`) - dadurch
            // funktioniert alles auch VOR dem Nachtrag.
            $table->foreignId('channel_id')->nullable()->after('conversation_id')
                ->constrained()->nullOnDelete();
            $table->foreignId('channel_account_id')->nullable()->after('channel_id')
                ->constrained()->nullOnDelete();

            $table->index(['channel_id', 'created_at'], 'customer_messages_channel_idx');
        });

        Schema::create('conversation_channels', function (Blueprint $table) {
            $table->id();
            $table->uuid('conversation_id');
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_account_id')->nullable()->constrained()->nullOnDelete();

            // Die Gegenstelle IN DIESEM Kanal. Sie gehoert hierher und
            // nicht an die Unterhaltung: dieselbe Person ist bei WhatsApp
            // eine Rufnummer und im Portal eine Benutzer-Kennung. Eine
            // einzige Spalte an der Unterhaltung koennte nur eine davon
            // tragen - und die Antwort ginge an die falsche Adresse.
            $table->string('external_user_id')->nullable();
            $table->string('external_conversation_id')->nullable();

            // WIE kam dieser Kanal dazu? `initial` = die Unterhaltung
            // begann hier; `manual` = ein Mitarbeiter hat verbunden;
            // `auto` = die Automatik hat anhand der Kundenakte verbunden.
            // Ohne diese Angabe liesse sich eine falsche Verbindung
            // spaeter nicht einordnen - und niemand wuesste, ob ein
            // Mensch sie zu verantworten hat.
            $table->string('join_method', 20)->default('initial');
            $table->foreignId('joined_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();

            $table->timestamp('first_message_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            /*
             * EIN Kanal-Konto je Unterhaltung nur EINMAL.
             *
             * Bewusst als EINE zusammengesetzte Spalte und nicht als
             * UNIQUE ueber (Unterhaltung, Kanal, Konto): SQLite UND MySQL
             * behandeln NULL als "immer verschieden", ein Kanal OHNE
             * Konto (Portal, interner Chat) waere also beliebig oft
             * eintragbar gewesen. Genau dieselbe Falle wie bei
             * `channel_events.dedupe_key` (06.09.2026).
             */
            $table->string('link_key', 120);
            $table->unique('link_key', 'conversation_channels_link_unique');

            $table->index(['conversation_id', 'last_message_at'], 'conv_channels_conversation_idx');
            // Der Weg, den der Eingang geht: Kanal + Gegenstelle -> Unterhaltung.
            $table->index(['channel_id', 'external_user_id'], 'conv_channels_lookup_idx');
            $table->index(['channel_account_id', 'external_conversation_id'], 'conv_channels_external_idx');
        });

        Schema::table('conversations', function (Blueprint $table) {
            // `channel_id` bleibt, heisst ab jetzt aber "erster Kanal".
            // Ihn umzubenennen haette jeden bestehenden Lesepfad
            // angefasst, ohne eine einzige Frage besser zu beantworten.
            $table->foreignId('last_channel_id')->nullable()->after('channel_id')
                ->constrained('channels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_channel_id');
        });

        Schema::dropIfExists('conversation_channels');

        Schema::table('customer_messages', function (Blueprint $table) {
            $table->dropIndex('customer_messages_channel_idx');
            $table->dropConstrainedForeignId('channel_id');
            $table->dropConstrainedForeignId('channel_account_id');
        });
    }
};
