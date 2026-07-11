<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rohspeicherung jeder eingehenden E-Mail, bevor Kategorisierung/
 * Matching läuft (Architekturplan Abschnitt 3/4). match_status:
 * unmatched -> suggested -> confirmed, plus category aus der
 * Klassifikations-Pipeline. Kein Duplikat zu Ticket/InternalMessage -
 * das ist die reine E-Mail-Quelle, aus der Tickets/Tasks erst
 * abgeleitet werden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('email_account_id')->constrained('email_accounts')->cascadeOnDelete();
            $table->string('message_uid'); // IMAP-UID bzw. Message-ID-Header
            $table->string('from_address');
            $table->string('from_name')->nullable();
            $table->string('to_address')->nullable();
            $table->string('subject')->nullable();
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->timestamp('received_at')->nullable();

            // Klassifikations-/Matching-Ergebnis (Abschnitt 4/5/13)
            $table->string('category')->nullable(); // versicherung|fonds_finanz|energie|dokumente|provisionen|kundenanfrage|sonstige
            $table->string('match_status')->default('unmatched'); // unmatched|suggested|confirmed
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->unsignedTinyInteger('match_score')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('raw_headers')->nullable();

            $table->timestamps();
            $table->unique(['email_account_id', 'message_uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
