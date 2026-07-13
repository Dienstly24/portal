<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal-Verbesserung Punkt 2: Bankverbindung erweitern.
 * Viele Versicherungen benoetigen zusaetzlich Bankname und BIC.
 * IBAN/Kontoinhaber bestehen bereits; hier kommen Bankname + BIC dazu.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'bank_name')) {
                $table->string('bank_name')->nullable()->after('account_holder');
            }
            if (!Schema::hasColumn('customers', 'bic')) {
                $table->string('bic', 11)->nullable()->after('bank_name');
            }
        });
    }

    public function down(): void {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'bic']);
        });
    }
};
