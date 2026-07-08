<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('added_by')->nullable()->after('notes');
            $table->string('contract_color')->default('green')->after('added_by');
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->string('color')->default('green')->after('category');
            $table->uuid('contract_id')->nullable()->after('color');
        });
        Schema::create('customer_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->text('note');
            $table->enum('type', ['note','task'])->default('note');
            $table->date('due_date')->nullable();
            $table->boolean('is_done')->default(false);
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('customer_notes');
        Schema::table('contracts', function($t) { $t->dropColumn(['added_by','contract_color']); });
        Schema::table('documents', function($t) { $t->dropColumn(['color','contract_id']); });
    }
};
