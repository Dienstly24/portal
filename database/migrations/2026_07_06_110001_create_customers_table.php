<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('customer_number')->unique();
            $table->date('birth_date')->nullable();
            $table->string('address')->nullable();
            $table->string('iban')->nullable();
            $table->string('marital_status')->nullable();
            $table->string('phone')->nullable();
            $table->string('preferred_lang')->default('de');
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('customers'); }
};
