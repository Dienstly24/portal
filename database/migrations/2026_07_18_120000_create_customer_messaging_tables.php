<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Direktnachrichten Berater <-> Kunde (Portal-Chat mit Anhaengen) sowie
// wiederverwendbare Nachrichten-/E-Mail-Vorlagen mit Platzhaltern.
return new class extends Migration {
    public function up(): void {
        Schema::create('customer_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            // Absender: Staff-User ODER Kunden-User; bleibt bei geloeschtem
            // Account lesbar (nullOnDelete statt Kaskade).
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('from_staff')->default(true);
            // Gelesen-Zeitpunkt der GEGENSEITE (Kunde liest Staff-Nachricht
            // bzw. Staff liest Kundenantwort).
            $table->timestamp('read_at')->nullable();
            // Gewaehlter E-Mail-Begleitversand: none | hint | full
            $table->string('email_mode', 10)->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'created_at']);
            $table->index(['customer_id', 'from_staff', 'read_at']);
        });

        Schema::create('customer_message_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('message_id')->constrained('customer_messages')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('disk', 20)->default('local');
            $table->timestamps();
        });

        Schema::create('message_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            // kunde = Nachrichten/E-Mails an Kunden, gesellschaft = an
            // Versicherer/Anbieter (freier Empfaenger im E-Mail-Composer).
            $table->string('category', 20)->default('kunde');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('lang', 2)->default('de');
            $table->unsignedInteger('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['category', 'sort']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('customer_message_attachments');
        Schema::dropIfExists('customer_messages');
        Schema::dropIfExists('message_templates');
    }
};
