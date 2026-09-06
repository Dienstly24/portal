<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Omnichannel Phase B, Teil 2: die BESTEHENDE Nachrichtentabelle wird
 * kanalfaehig - bewusst KEINE zweite Nachrichtentabelle daneben.
 *
 * WARUM NICHT NEU: eine zweite `messages` haette den Bestand kopiert.
 * Solange beide existieren, hat jede Frage zwei Antworten, und der
 * KI-Assistent, die Glocke, die Suche, der Portal-Chat und die
 * Kundenakte muessten gleichzeitig umgestellt werden. Die vorhandenen
 * Spalten bilden das Zielmodell ohnehin fast ab: `from_staff` IST die
 * Richtung, `read_at` IST eine Statuszeit.
 *
 * ALLE neuen Spalten sind nullable. Kein bestehender Schreib- oder
 * Lesepfad aendert sich durch diese Migration.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_messages', function (Blueprint $table) {
            // NICHTS GEHT VERLOREN: eine eingehende Nachricht von einer
            // unbekannten Nummer hat noch keine Kundenakte. Blieb die
            // Spalte pflichtig, wuerde ausgerechnet die Nachricht
            // verworfen, die ein Mitarbeiter zuordnen soll - und der
            // Absender bekaeme sie nie zurueck. Die Unterhaltung traegt
            // die Nachricht, bis die Akte gefunden ist.
            $table->uuid('customer_id')->nullable()->change();

            $table->uuid('conversation_id')->nullable()->after('customer_id');
            $table->foreign('conversation_id')->references('id')->on('conversations')->nullOnDelete();

            // `direction` ist die kanalunabhaengige Lesart von `from_staff`.
            // Beide bleiben bestehen und werden gemeinsam geschrieben (eine
            // Quelle, zwei Lesarten) - so laeuft jeder Altaufrufer weiter.
            $table->string('direction', 10)->nullable()->after('from_staff');
            // employee | customer | system | bot - erweiterbar ohne Umbau.
            $table->string('sender_type', 20)->nullable()->after('sender_id');

            $table->string('external_message_id')->nullable()->after('conversation_id');
            $table->string('message_type', 20)->nullable()->after('direction');
            // pending | sent | delivered | read | failed
            $table->string('status', 20)->nullable()->after('message_type');
            $table->json('metadata')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 500)->nullable();

            $table->index(['conversation_id', 'created_at'], 'customer_messages_conversation_idx');
            // Zweite Verteidigungslinie gegen doppelte Webhook-Zustellung:
            // selbst wenn das Ereignis-Register versagt, kann dieselbe
            // Plattform-Nachricht in einer Unterhaltung nur einmal stehen.
            $table->unique(['conversation_id', 'external_message_id'], 'customer_messages_external_unique');
        });

        Schema::table('customer_message_attachments', function (Blueprint $table) {
            // Der MIME-Typ wurde bisher aus der DATEIENDUNG geraten. Was
            // ein Kanal liefert, wissen wir dagegen genau - und bei einer
            // heruntergeladenen Mediendatei gibt es oft gar keine Endung.
            $table->string('type', 20)->nullable()->after('file_name');
            $table->string('mime_type', 120)->nullable()->after('type');
            $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');
            $table->string('external_media_id')->nullable();
            $table->json('metadata')->nullable();
            $table->index('message_id', 'cma_message_idx');
        });

        Schema::table('employee_customers', function (Blueprint $table) {
            // DER Betreuer im Sinne der automatischen Zuweisung.
            //
            // Bewusst hier und nicht als `customers.betreuer_employee_id`:
            // `employee_customers` ist der Dreh- und Angelpunkt des
            // Portfolio-Modells (Sichtbarkeit, Vertretung). Eine zweite
            // Spalte am Kunden waere eine ZWEITE Wahrheit darueber, wer
            // zustaendig ist - beide koennten auseinanderlaufen, und man
            // saehe einer Zeile nicht an, welche gilt. So bleibt es EINE
            // Zuordnung, von der genau eine die primaere ist.
            $table->boolean('is_primary')->default(false)->after('customer_id');
            $table->index(['customer_id', 'is_primary'], 'employee_customers_primary_idx');
        });
    }

    public function down(): void
    {
        Schema::table('employee_customers', function (Blueprint $table) {
            $table->dropIndex('employee_customers_primary_idx');
            $table->dropColumn('is_primary');
        });

        Schema::table('customer_message_attachments', function (Blueprint $table) {
            $table->dropIndex('cma_message_idx');
            $table->dropColumn(['type', 'mime_type', 'file_size', 'external_media_id', 'metadata']);
        });

        Schema::table('customer_messages', function (Blueprint $table) {
            $table->dropUnique('customer_messages_external_unique');
            $table->dropIndex('customer_messages_conversation_idx');
            $table->dropForeign(['conversation_id']);
            $table->uuid('customer_id')->nullable(false)->change();
            $table->dropColumn([
                'conversation_id', 'direction', 'sender_type', 'external_message_id',
                'message_type', 'status', 'metadata', 'sent_at', 'delivered_at',
                'failed_at', 'failure_reason',
            ]);
        });
    }
};
