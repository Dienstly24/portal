<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verknuepft Aufgaben mit ihrer HERKUNFT: der ausloesenden E-Mail und
 * (falls vorhanden) dem betroffenen Vertrag. Damit kann ein Mitarbeiter
 * aus der Aufgabenliste direkt die Original-E-Mail oeffnen ("was wird
 * verlangt?") statt nur einen generischen Beschreibungstext zu sehen
 * (Architekturplan Abschnitt 9: Task <-> Contract-Bindung fehlte bisher).
 * Additiv, nullable - bestehende Aufgaben bleiben unveraendert gueltig.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->uuid('email_message_id')->nullable()->after('customer_id');
            $table->uuid('contract_id')->nullable()->after('email_message_id');

            $table->foreign('email_message_id')->references('id')->on('email_messages')->nullOnDelete();
            $table->foreign('contract_id')->references('id')->on('contracts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['email_message_id']);
            $table->dropForeign(['contract_id']);
            $table->dropColumn(['email_message_id', 'contract_id']);
        });
    }
};
