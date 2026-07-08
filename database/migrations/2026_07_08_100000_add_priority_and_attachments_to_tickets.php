<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('tickets', 'priority')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->string('priority', 10)->default('mittel')->after('type');
            });
        }
        if (!Schema::hasTable('ticket_attachments')) {
            Schema::create('ticket_attachments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('ticket_id')->index();
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->string('file_name');
                $table->string('file_path');
                $table->timestamps();
            });
        }
    }
    public function down(): void {
        if (Schema::hasColumn('tickets', 'priority')) {
            Schema::table('tickets', function (Blueprint $table) { $table->dropColumn('priority'); });
        }
        Schema::dropIfExists('ticket_attachments');
    }
};
