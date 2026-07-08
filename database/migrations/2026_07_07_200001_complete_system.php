<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        // Guard each column individually: pdf_path already exists from
        // create_contracts_table, so the previous single guard on
        // premium_amount caused a duplicate-column error on fresh installs.
        Schema::table('contracts', function (Blueprint $table) {
            if (!Schema::hasColumn('contracts', 'premium_amount')) {
                $table->decimal('premium_amount', 10, 2)->nullable()->after('notes');
            }
            if (!Schema::hasColumn('contracts', 'premium_interval')) {
                $table->enum('premium_interval', ['monthly','quarterly','yearly','once'])->default('monthly');
            }
            if (!Schema::hasColumn('contracts', 'pdf_path')) {
                $table->string('pdf_path')->nullable();
            }
            if (!Schema::hasColumn('contracts', 'lexoffice_id')) {
                $table->string('lexoffice_id')->nullable();
            }
        });
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
