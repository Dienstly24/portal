<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social-Publishing Phase 2 (Meta Graph API): direktes Posten auf die
 * eigene Facebook-Seite und das Instagram-Business-Konto.
 * - posts.scheduled_for: geplanter Auto-Versand (Command social:publish-scheduled)
 * - channels.external_post_id/url: der tatsaechlich erstellte Beitrag
 * - channels.publish_error: verstaendliche Fehlermeldung des letzten Versuchs
 * - channels.auto_attempted_at: der Auto-Versand versucht es genau EINMAL
 *   (nie-doppelt-posten-Schutz); danach ist Erneut-Versuchen eine bewusste
 *   Mitarbeiter-Aktion.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('banner_social_posts', function (Blueprint $table) {
            $table->dateTime('scheduled_for')->nullable()->after('target_url');
        });

        Schema::table('banner_social_channels', function (Blueprint $table) {
            $table->string('external_post_id')->nullable()->after('published_by');
            $table->string('external_url', 500)->nullable()->after('external_post_id');
            $table->text('publish_error')->nullable()->after('external_url');
            $table->dateTime('auto_attempted_at')->nullable()->after('publish_error');
        });
    }

    public function down(): void
    {
        Schema::table('banner_social_posts', function (Blueprint $table) {
            $table->dropColumn('scheduled_for');
        });
        Schema::table('banner_social_channels', function (Blueprint $table) {
            $table->dropColumn(['external_post_id', 'external_url', 'publish_error', 'auto_attempted_at']);
        });
    }
};
