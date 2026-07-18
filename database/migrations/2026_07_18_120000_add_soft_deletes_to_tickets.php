<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Papierkorb fuer Tickets (Soft Delete): Loeschen verschiebt das Ticket
 * zunaechst in den Papierkorb (wiederherstellbar), endgueltiges Loeschen
 * ist ein separater Admin-Schritt. Analog zur Kundenloeschung gilt:
 * Mitarbeiter/Support loeschen NIE.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('tickets', function (Blueprint $table) {
            $table->softDeletes()->index();
        });
    }
    public function down(): void {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
