<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der KI-Assistent wird KANALUNABHAENGIG (Auftrag Abschnitte 48/49/55/63).
 *
 * WARUM: `ai_conversations.customer_id` war UNIQUE - es gab genau EINEN
 * KI-Steuerstand je Kunde. Solange der Portal-Chat der einzige Kanal war,
 * stimmte das. Mit mehreren Kanaelen ist es sichtbar falsch: schreibt
 * derselbe Kunde ueber WhatsApp UND Instagram, teilen sich beide
 * Unterhaltungen einen Zustand - wer die KI im einen Vorgang pausiert,
 * schaltet sie im anderen mit ab, ohne es zu sehen. Auch die
 * Kostengrenze (`auto_reply_count`) zaehlte quer ueber alle Kanaele.
 *
 * NAMENSFALLE, deshalb ausgeschrieben: es gibt jetzt ZWEI Dinge namens
 * "conversation" - `conversations` (die Unterhaltung) und
 * `ai_conversations` (der KI-STEUERSTAND). `ai_assistant_logs.conversation_id`
 * zeigt auf den STEUERSTAND. Die neue Spalte heisst deshalb
 * `omnichannel_conversation_id` und nie nur `conversation_id`.
 *
 * Nichts wird geloescht, nichts umgehaengt: der Bestand behaelt seinen
 * Steuerstand am Kunden und laeuft unveraendert weiter.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->uuid('omnichannel_conversation_id')->nullable()->after('customer_id');
            $table->foreign('omnichannel_conversation_id')
                ->references('id')->on('conversations')->cascadeOnDelete();

            // Je Unterhaltung genau ein Steuerstand. Der Bestand traegt hier
            // NULL - und NULL gilt in beiden Datenbanken als "immer
            // verschieden", was hier ausnahmsweise genau richtig ist: alle
            // Altzeilen bleiben nebeneinander gueltig.
            $table->unique('omnichannel_conversation_id', 'ai_conv_omni_unique');
        });

        // Das UNIQUE auf customer_id faellt - ein Kunde kann jetzt mehrere
        // Steuerstaende haben (einen je Unterhaltung, plus den alten
        // kundenweiten als Vorgabe). Der Index bleibt als reiner Suchindex.
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropUnique('ai_conversations_customer_id_unique');
            $table->index('customer_id', 'ai_conversations_customer_idx');
        });

        // KI-Betriebsart je Ebene (Abschnitt 63). NULL heisst ERBEN - nur
        // eine ausdruecklich gesetzte Ebene wirkt. Ohne diese Regel waere
        // "nicht gesetzt" von "ausgeschaltet" nicht zu unterscheiden, und
        // ein leeres Feld haette die globale Vorgabe still ueberstimmt.
        Schema::table('channels', function (Blueprint $table) {
            $table->string('ai_mode', 20)->nullable()->after('is_active');
        });
        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->string('ai_mode', 20)->nullable()->after('is_active');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->string('ai_mode', 20)->nullable();
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('ai_mode', 20)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', fn (Blueprint $t) => $t->dropColumn('ai_mode'));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('ai_mode'));
        Schema::table('channel_accounts', fn (Blueprint $t) => $t->dropColumn('ai_mode'));
        Schema::table('channels', fn (Blueprint $t) => $t->dropColumn('ai_mode'));

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropIndex('ai_conversations_customer_idx');
            $table->dropUnique('ai_conv_omni_unique');
            $table->dropForeign(['omnichannel_conversation_id']);
            $table->dropColumn('omnichannel_conversation_id');
            $table->unique('customer_id', 'ai_conversations_customer_id_unique');
        });
    }
};
