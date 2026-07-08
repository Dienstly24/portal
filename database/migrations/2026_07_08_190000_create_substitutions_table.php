<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('substitutions')) {
            Schema::create('substitutions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('absent_user_id')->index();
                $table->unsignedBigInteger('substitute_user_id')->index();
                $table->date('from_date');
                $table->date('to_date');
                $table->string('reason')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->timestamps();
            });
        }
    }
    public function down(): void {
        Schema::dropIfExists('substitutions');
    }
};
