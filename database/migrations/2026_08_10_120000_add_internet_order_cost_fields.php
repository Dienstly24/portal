<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Betreiber-Vorgabe 10.08.2026: Der Internet-/DSL-Auftrag (z.B. CHECK24 fuer
 * Vodafone Kabel) soll VOLLSTAENDIG in der Vertragsakte stehen. Es fehlten:
 *
 * - Bereitstellungsgebuehr (einmalige Anschluss-/Aktivierungskosten) und
 *   Versandkosten (einmalig, Router-Versand) - "was kostet die Schaltung".
 * - Mindestlaufzeit in Monaten: beim Auftrag gibt es noch keinen
 *   Anschlusstermin ("schnellstmoeglich"), Beginn/Ablauf des Vertrags sind
 *   also leer - die Laufzeit muss deshalb als eigenes Feld erfasst werden.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('contract_internet_details', function (Blueprint $table) {
            $table->decimal('setup_fee', 8, 2)->nullable()->after('voucher_amount');
            $table->decimal('shipping_fee', 8, 2)->nullable()->after('setup_fee');
            $table->unsignedSmallInteger('min_duration_months')->nullable()->after('shipping_fee');
        });
    }

    public function down(): void {
        Schema::table('contract_internet_details', function (Blueprint $table) {
            $table->dropColumn(['setup_fee', 'shipping_fee', 'min_duration_months']);
        });
    }
};
