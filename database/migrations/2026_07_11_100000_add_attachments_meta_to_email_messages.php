<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit-Fix H1: Anhänge werden ab jetzt zunächst nur als Dateien am
 * E-Mail-Datensatz abgelegt (Meta hier) und erst bei BESTÄTIGTER
 * Kundenzuordnung als Document in die Kundenakte übernommen.
 * Zusätzlich Index auf match_status (Audit M7) - Posteingang, Badge
 * und Prune filtern genau darauf.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->json('attachments_meta')->nullable()->after('raw_headers');
            $table->index('match_status');
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropIndex(['match_status']);
            $table->dropColumn('attachments_meta');
        });
    }
};
