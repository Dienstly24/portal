<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optionale Anbieterliste je Leistungsseite (ein Name pro Zeile), die als
 * ruhiges Laufband ("Marquee") angezeigt wird. Nur Namen als Text - keine
 * fremden Logos. Über die Adminoberflaeche pflegbar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_pages', function (Blueprint $table) {
            $table->text('providers')->nullable()->after('body_ar');
        });
    }

    public function down(): void
    {
        Schema::table('service_pages', function (Blueprint $table) {
            $table->dropColumn('providers');
        });
    }
};
