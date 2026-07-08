<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('customer_family', 'geschlecht')) {
            Schema::table('customer_family', function (Blueprint $table) {
                $table->string('geschlecht', 1)->nullable()->after('relation');
            });
        }
    }
    public function down(): void {
        if (Schema::hasColumn('customer_family', 'geschlecht')) {
            Schema::table('customer_family', function (Blueprint $table) {
                $table->dropColumn('geschlecht');
            });
        }
    }
};
