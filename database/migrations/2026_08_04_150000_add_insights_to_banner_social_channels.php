<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social-Publishing Phase 3: Kennzahlen der veroeffentlichten Beitraege
 * (Likes/Kommentare/Shares/Reichweite) direkt am Kanal - geholt ueber die
 * Meta-API (social:refresh-insights), damit der Betreiber Meta nicht
 * oeffnen muss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banner_social_channels', function (Blueprint $table) {
            $table->json('insights')->nullable()->after('auto_attempted_at');
            $table->dateTime('insights_refreshed_at')->nullable()->after('insights');
        });
    }

    public function down(): void
    {
        Schema::table('banner_social_channels', function (Blueprint $table) {
            $table->dropColumn(['insights', 'insights_refreshed_at']);
        });
    }
};
