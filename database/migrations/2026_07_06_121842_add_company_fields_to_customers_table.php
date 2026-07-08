<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('preferred_lang');
            $table->string('company_type')->nullable()->after('company_name');
            $table->string('customer_type')->default('privat')->after('company_type');
            $table->string('mobile')->nullable()->after('phone');
        });
    }
    public function down(): void {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['company_name','company_type','customer_type','mobile']);
        });
    }
};
