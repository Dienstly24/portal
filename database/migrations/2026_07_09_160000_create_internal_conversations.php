<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eigenständiger interner Mitarbeiter-Chat (Spec Teil 8), technisch
 * vollständig getrennt von Kundentickets. Es gibt keinerlei Verbindung
 * zu customers oder zum Kundenportal - Nachrichten können strukturell
 * nicht an Kunden gelangen.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('internal_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('subject');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('internal_conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->uuid('conversation_id');
            $table->foreign('conversation_id')->references('id')->on('internal_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id']);
        });

        Schema::create('internal_conversation_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->foreign('conversation_id')->references('id')->on('internal_conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('internal_conversation_messages');
        Schema::dropIfExists('internal_conversation_participants');
        Schema::dropIfExists('internal_conversations');
    }
};
