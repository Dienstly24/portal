<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'last_login_at')) $table->timestamp('last_login_at')->nullable();
            if (!Schema::hasColumn('users', 'portal_reminder_sent_at')) $table->timestamp('portal_reminder_sent_at')->nullable();
        });
    }
    public function down(): void {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'last_login_at')) $table->dropColumn('last_login_at');
            if (Schema::hasColumn('users', 'portal_reminder_sent_at')) $table->dropColumn('portal_reminder_sent_at');
        });
    }
};
