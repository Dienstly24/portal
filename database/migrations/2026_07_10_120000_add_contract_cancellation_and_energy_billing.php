<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review Punkt 12: Verträge brauchen ein Kündigungsdatum; Energie-
 * verträge Abschlag + Zahlungsintervall. Das Intervall wird an der
 * Energie-Detailtabelle gespeichert, weil es dort vier Stufen gibt
 * (monatlich/vierteljährlich/halbjährlich/jährlich) - der bestehende
 * contracts.premium_interval-Enum kennt kein halbjährlich und bleibt
 * unangetastet, um Bestandsdaten nicht zu brechen.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('contracts', function (Blueprint $table) {
            $table->date('cancellation_date')->nullable()->after('end_date');
        });
        Schema::table('contract_energy_details', function (Blueprint $table) {
            $table->decimal('payment_amount', 8, 2)->nullable(); // Abschlag
            $table->string('payment_interval', 20)->nullable();  // monatlich|vierteljaehrlich|halbjaehrlich|jaehrlich
        });
    }

    public function down(): void {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('cancellation_date');
        });
        Schema::table('contract_energy_details', function (Blueprint $table) {
            $table->dropColumn(['payment_amount', 'payment_interval']);
        });
    }
};
