<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Omnichannel Phase B - die Unterhaltung wird ein DATENSATZ.
 *
 * WARUM: bis heute ist "die Unterhaltung mit einem Kunden" eine ABFRAGE
 * (`customer_messages where customer_id = X`), kein Objekt. Deshalb kann
 * ihr nichts anhaften: kein Zustand, kein Zustaendiger, kein Abschluss,
 * keine Historie. Genau das ist der Grund, warum eine Nachricht heute
 * niemandem gehoert und niemand sie schliessen kann.
 *
 * Diese Migration legt nur an. Sie aendert KEINE bestehende Zeile und
 * KEINEN bestehenden Lesepfad - das Nachtragen des Bestands macht
 * bewusst ein eigener, wiederholbarer Befehl
 * (`messaging:unterhaltungen-nachtragen`).
 */
return new class extends Migration {
    public function up(): void
    {
        // Kanal-DEFINITION. Die Faehigkeiten stehen als DATEN, nicht als
        // Bedingungen im Kern: der Conversation Engine fragt "kann dieser
        // Kanal Medien?", nie "ist dieser Kanal WhatsApp?".
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name');
            $table->string('driver', 40);
            $table->boolean('is_active')->default(true);
            $table->json('capabilities')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // Ein KONTO innerhalb eines Kanals. Bewusst mehrere je Kanal
        // moeglich (zwei WhatsApp-Nummern, zwei Instagram-Konten).
        Schema::create('channel_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Kennung der Plattform (Phone-Number-ID, Seiten-ID ...).
            $table->string('external_account_id')->nullable();
            // Zugangsdaten NUR hier und NUR verschluesselt (Cast im Model).
            // Nie im Repository, nie im Frontend, nie im Log.
            $table->text('credentials')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['channel_id', 'external_account_id'], 'channel_accounts_external_unique');
        });

        // EIN Kunde, VIELE Kanal-Identitaeten. Der Kern von Omnichannel:
        // ohne diese Tabelle wuerde die Kundenakte mit jeder neuen Kanal-
        // Anbindung eine weitere Spalte bekommen (whatsapp_id,
        // instagram_id, telegram_id ...) und nie wieder schrumpfen.
        Schema::create('customer_channel_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_user_id');
            $table->string('external_username')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            // Dieselbe Kennung darf innerhalb EINES Kontos nur einem Kunden
            // gehoeren - sonst landet die Antwort beim Falschen.
            $table->unique(['channel_account_id', 'external_user_id'], 'cci_account_user_unique');
            $table->index(['channel_id', 'external_user_id'], 'cci_channel_user_idx');
            $table->index('customer_id', 'cci_customer_idx');
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // NULLABLE mit Absicht: der interne Mitarbeiter-Chat hat keinen
            // Kunden, und eine eingehende Nachricht von einer unbekannten
            // Nummer darf nicht verloren gehen, nur weil die Akte fehlt.
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_account_id')->nullable()->constrained()->nullOnDelete();

            $table->string('external_conversation_id')->nullable();
            $table->string('external_user_id')->nullable();

            // Der aktuell Zustaendige DIESER Unterhaltung - bewusst getrennt
            // vom Betreuer des KUNDEN (employee_customers.is_primary): eine
            // Uebernahme durch den Support gilt dem Vorgang, nicht dem
            // Kundenverhaeltnis.
            $table->foreignId('assigned_employee_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 20)->default('open');
            // Nur fuer den internen Chat, der einen Betreff traegt.
            $table->string('subject')->nullable();

            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('reopened_at')->nullable();

            // Mehrbenutzer-Betrieb: wer schreibt gerade an dieser
            // Unterhaltung. Ein weicher Hinweis mit Ablauf, keine harte
            // Sperre - ein abgestuerzter Browser darf eine Unterhaltung
            // nicht dauerhaft blockieren.
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();

            $table->timestamps();

            // Die Inbox sortiert ueber alle Kanaele nach Aktivitaet.
            $table->index(['status', 'last_message_at'], 'conversations_status_activity_idx');
            $table->index(['customer_id', 'last_message_at'], 'conversations_customer_idx');
            $table->index(['assigned_employee_id', 'status'], 'conversations_assignee_idx');
            $table->index(['channel_id', 'last_message_at'], 'conversations_channel_idx');
            // Eine externe Unterhaltung existiert je Konto genau einmal.
            $table->unique(['channel_account_id', 'external_conversation_id'], 'conversations_external_unique');
        });

        // Historie jeder Zustaendigkeitsaenderung. Ohne sie ist nach einer
        // Uebernahme nicht mehr belegbar, wer wann verantwortlich war.
        Schema::create('conversation_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('changed_by_employee_id')->nullable()->constrained('users')->nullOnDelete();
            // auto_betreuer | takeover | reassign | unassign | manual
            $table->string('action', 20)->default('reassign');
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['conversation_id', 'created_at'], 'conv_assignments_idx');
        });

        // Idempotenz-Register. Webhooks werden von JEDER Plattform
        // mehrfach zugestellt - ohne diese Tabelle entsteht bei jeder
        // Wiederholung eine zweite Nachricht im Chat des Kunden.
        Schema::create('channel_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('external_event_id');
            $table->string('kind', 40)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            // FALLE, die ein zusammengesetzter UNIQUE-Index nicht loest:
            // SQLite UND MySQL behandeln NULL als "immer verschieden".
            // Ein Ereignis ohne zugeordnetes Konto - genau der Fall vor
            // der Kontoerkennung - waere damit beliebig oft
            // beanspruchbar, und die Idempotenz haette ausgerechnet dort
            // ein Loch, wo sie gebraucht wird. Deshalb ein
            // ZUSAMMENGESETZTER Schluessel als eigene, nicht-nullbare
            // Spalte.
            $table->string('dedupe_key', 300)->unique();
            $table->index('created_at', 'channel_events_created_idx');
        });

        $now = now();
        // Die beiden Kanaele, die es HEUTE schon gibt - sie tragen den
        // Bestand. Weitere Kanaele entstehen ueber die Oberflaeche bzw.
        // ihre eigene Migration, nicht hier.
        DB::table('channels')->insert([
            [
                'key' => 'internal',
                'name' => 'Interner Chat',
                'driver' => 'internal',
                'is_active' => true,
                'sort' => 10,
                'capabilities' => json_encode([
                    'supportsMedia' => false,
                    'supportsReadReceipts' => true,
                    'supportsDeliveryReceipts' => false,
                    'supportsCustomers' => false,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'portal',
                'name' => 'Kundenportal',
                'driver' => 'portal',
                'is_active' => true,
                'sort' => 20,
                'capabilities' => json_encode([
                    'supportsMedia' => true,
                    'supportsReadReceipts' => true,
                    'supportsDeliveryReceipts' => false,
                    'supportsCustomers' => true,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_events');
        Schema::dropIfExists('conversation_assignments');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('customer_channel_identities');
        Schema::dropIfExists('channel_accounts');
        Schema::dropIfExists('channels');
    }
};
