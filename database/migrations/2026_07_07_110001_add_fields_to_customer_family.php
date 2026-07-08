<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('customer_family', function (Blueprint $table) {
            $table->string('krankenversicherung_nr')->nullable()->after('birth_date');
            $table->string('steuer_nr')->nullable()->after('krankenversicherung_nr');
        });
    }
    public function down(): void {
        Schema::table('customer_family', function (Blueprint $table) {
            $table->dropColumn(['krankenversicherung_nr','steuer_nr']);
        });
    }
};
