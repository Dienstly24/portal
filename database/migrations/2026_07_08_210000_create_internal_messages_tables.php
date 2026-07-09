<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internes Mitarbeiter-Chat- und Notizsystem.
 * Nachrichten sind ausschließlich für Mitarbeiter bestimmt und werden
 * an keiner Stelle an das Kundenportal ausgeliefert.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('internal_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('message');
            $table->string('type', 10)->default('chat'); // chat | note
            $table->json('mentioned_users')->nullable();
            // Soft delete + deleted_by für das Audit-Log (wer hat wann gelöscht)
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['customer_id', 'type', 'created_at']);
        });

        Schema::create('internal_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('message_id');
            $table->foreign('message_id')->references('id')->on('internal_messages')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('internal_notifications');
        Schema::dropIfExists('internal_messages');
    }
};
