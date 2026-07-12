<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bannerverwaltung-Ausbau:
 * - Statistiken (Impressions, Klicks, letzter Ausspielzeitpunkt) + Tageswerte
 * - Klick-Ziel (interner Pfad oder externe URL, Ziel-Fenster)
 * - Entwurfsstatus (Draft) und Schließen-Verhalten (dismiss)
 * - Audit: wer hat erstellt/zuletzt geändert
 * - banner_user_views: eindeutige Betrachter je Banner + "geschlossen bis"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->boolean('is_draft')->default(false)->after('is_active');
            $table->string('link_url')->nullable()->after('media_type');
            $table->string('link_target', 6)->default('self')->after('link_url'); // self|blank
            $table->unsignedInteger('dismiss_days')->nullable()->after('link_target'); // null = kein Schließen-Button
            $table->unsignedBigInteger('total_impressions')->default(0)->after('sort_order');
            $table->unsignedBigInteger('total_clicks')->default(0)->after('total_impressions');
            $table->timestamp('last_shown_at')->nullable()->after('total_clicks');
            $table->string('created_by')->nullable()->after('last_shown_at');
            $table->string('updated_by')->nullable()->after('created_by');
        });

        Schema::create('banner_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('banner_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unique(['banner_id', 'date']);
        });

        Schema::create('banner_user_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('banner_id')->constrained()->cascadeOnDelete();
            $table->string('user_id')->index();
            $table->unsignedInteger('views')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('dismissed_until')->nullable();
            $table->unique(['banner_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_user_views');
        Schema::dropIfExists('banner_daily_stats');
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn([
                'is_draft', 'link_url', 'link_target', 'dismiss_days',
                'total_impressions', 'total_clicks', 'last_shown_at',
                'created_by', 'updated_by',
            ]);
        });
    }
};
