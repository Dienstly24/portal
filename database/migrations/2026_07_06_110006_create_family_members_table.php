<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('family_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->string('full_name');
            $table->date('birth_date')->nullable();
            $table->string('relation');
            $table->string('doc_path')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('family_members'); }
};
