<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review Punkt 1: Anrede und Geschlecht speichern doppelte Information.
 * Die Anrede wird entfernt; das Geschlecht ist die einzige Datenquelle.
 * Vor dem Entfernen wird gender verlustfrei aus salutation befüllt,
 * wo es noch leer ist (herr->male, frau->female, divers->diverse).
 */
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('customers', 'salutation')) {
            return;
        }
        DB::table('customers')->whereNull('gender')->where('salutation', 'herr')->update(['gender' => 'male']);
        DB::table('customers')->whereNull('gender')->where('salutation', 'frau')->update(['gender' => 'female']);
        DB::table('customers')->whereNull('gender')->where('salutation', 'divers')->update(['gender' => 'diverse']);

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('salutation');
        });
    }

    public function down(): void {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('salutation', 10)->nullable();
        });
    }
};
