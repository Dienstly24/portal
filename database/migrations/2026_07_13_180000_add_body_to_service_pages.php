<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ausführlicher, redaktioneller Inhalt je Leistungsseite (DE/AR) - fuer mehr
 * Tiefe und SEO. Leichtes Markup: "## " = Zwischenüberschrift, "- " =
 * Aufzählungspunkt, Leerzeile = neuer Absatz. Über die Adminoberflaeche
 * pflegbar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_pages', function (Blueprint $table) {
            $table->text('body_de')->nullable()->after('highlights_ar');
            $table->text('body_ar')->nullable()->after('body_de');
        });
    }

    public function down(): void
    {
        Schema::table('service_pages', function (Blueprint $table) {
            $table->dropColumn(['body_de', 'body_ar']);
        });
    }
};
