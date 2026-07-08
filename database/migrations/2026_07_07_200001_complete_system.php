<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('contracts', 'premium_amount')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->decimal('premium_amount', 10, 2)->nullable()->after('notes');
                $table->enum('premium_interval', ['monthly','quarterly','yearly','once'])->default('monthly')->after('premium_amount');
                $table->string('pdf_path')->nullable()->after('premium_interval');
                $table->string('lexoffice_id')->nullable()->after('pdf_path');
            });
        }
        if (!Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('customer_timeline')) {
            Schema::create('customer_timeline', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('customer_id');
                $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
                $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
                $table->string('type');
                $table->string('title');
                $table->text('description')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('appointments')) {
            Schema::create('appointments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('customer_id');
                $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
                $table->foreignId('assigned_to')->constrained('users')->onDelete('cascade');
                $table->string('title');
                $table->text('notes')->nullable();
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->enum('status', ['scheduled','completed','cancelled'])->default('scheduled');
                $table->timestamps();
            });
        }
    }
    public function down(): void {}
};
