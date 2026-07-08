<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->enum('category', ['contract','invoice','correspondence','other']);
            $table->string('file_name');
            $table->string('file_path');
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('documents'); }
};
