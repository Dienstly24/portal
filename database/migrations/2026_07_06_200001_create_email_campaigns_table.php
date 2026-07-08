<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('email_campaigns')) {
            Schema::create('email_campaigns', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
                $table->string('subject');
                $table->longText('body');
                $table->string('target');
                $table->enum('status', ['draft','sent','scheduled'])->default('draft');
                $table->integer('sent_count')->default(0);
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('email_logs')) {
            Schema::create('email_logs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('campaign_id')->nullable();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->string('email');
                $table->string('subject');
                $table->string('type');
                $table->enum('status', ['sent','failed'])->default('sent');
                $table->timestamps();
            });
        }
    }
    public function down(): void {
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('email_campaigns');
    }
};
