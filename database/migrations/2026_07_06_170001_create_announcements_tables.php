<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('tarifrechner_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('category');
            $table->string('title');
            $table->string('url');
            $table->string('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('announcements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->text('body');
            $table->enum('priority', ['normal','important','urgent'])->default('normal');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('tarifrechner_links');
        Schema::dropIfExists('announcements');
    }
};
