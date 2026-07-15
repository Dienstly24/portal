<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beitrag/Zahlweise je Vertrag: contracts.premium_interval war ein Enum
 * (monthly|quarterly|yearly|once) OHNE halbjaehrlich. Der Betreiber braucht
 * aber auch die 6-Monats-Zahlweise. Das Enum wird deshalb zu einem String
 * gelockert (analog zur Energie-Detailtabelle) - neue Stufen erfordern dann
 * keine Migration mehr. premium_amount (decimal) bleibt unveraendert.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('premium_interval', 20)->default('monthly')->change();
        });
    }

    public function down(): void {
        // Werte, die das alte Enum nicht kennt, vor dem Zurueckwandeln kappen.
        \Illuminate\Support\Facades\DB::table('contracts')
            ->where('premium_interval', 'semiannual')
            ->update(['premium_interval' => 'monthly']);
        Schema::table('contracts', function (Blueprint $table) {
            $table->enum('premium_interval', ['monthly', 'quarterly', 'yearly', 'once'])
                ->default('monthly')->change();
        });
    }
};
