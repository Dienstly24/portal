<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('access_level', ['full','limited'])->default('full')->after('role');
            $table->boolean('can_see_all_customers')->default(true)->after('access_level');
            $table->boolean('can_manage_contracts')->default(true)->after('can_see_all_customers');
            $table->boolean('can_manage_tickets')->default(true)->after('can_manage_contracts');
            $table->boolean('can_approve_changes')->default(false)->after('can_manage_tickets');
            $table->boolean('can_send_emails')->default(false)->after('can_approve_changes');
            $table->boolean('can_import_export')->default(false)->after('can_send_emails');
        });

        Schema::create('employee_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->uuid('customer_id');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('employee_customers');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['access_level','can_see_all_customers','can_manage_contracts','can_manage_tickets','can_approve_changes','can_send_emails','can_import_export']);
        });
    }
};
