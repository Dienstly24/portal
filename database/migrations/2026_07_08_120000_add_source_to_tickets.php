<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('tickets', 'source')) $table->string('source', 20)->default('portal')->after('customer_id');
            if (!Schema::hasColumn('tickets', 'guest_name')) $table->string('guest_name')->nullable();
            if (!Schema::hasColumn('tickets', 'guest_email')) $table->string('guest_email')->nullable();
            if (!Schema::hasColumn('tickets', 'guest_phone')) $table->string('guest_phone')->nullable();
        });
        // العمود customer_id لازم يقبل NULL للاستفسارات من غير عملاء
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE tickets MODIFY customer_id CHAR(36) NULL');
    }
    public function down(): void {
        Schema::table('tickets', function (Blueprint $table) {
            foreach (['source','guest_name','guest_email','guest_phone'] as $col) {
                if (Schema::hasColumn('tickets', $col)) $table->dropColumn($col);
            }
        });
    }
};
