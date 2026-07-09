<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification Center (Final Polish Punkt 2): Benachrichtigungen können
 * jetzt auch freie Systemmeldungen tragen (title/body/link), zusätzlich
 * zu Mentions (message_id) und Kundenänderungen (change_request_id).
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('internal_notifications', function (Blueprint $table) {
            $table->string('title')->nullable();
            $table->string('body', 500)->nullable();
            $table->string('link')->nullable();
        });
    }
    public function down(): void {
        Schema::table('internal_notifications', function (Blueprint $table) {
            $table->dropColumn(['title', 'body', 'link']);
        });
    }
};
