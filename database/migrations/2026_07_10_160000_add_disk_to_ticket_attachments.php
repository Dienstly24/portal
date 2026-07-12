<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Punkt 5: Ticket-Anhänge sicher (privat) speichern; Bestand bleibt public lesbar. */
return new class extends Migration {
    public function up(): void {
        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->string('disk', 20)->default('public');
        });
    }
    public function down(): void {
        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->dropColumn('disk');
        });
    }
};
