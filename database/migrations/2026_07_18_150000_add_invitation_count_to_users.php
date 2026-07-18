<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zaehler, wie oft eine Portal-Einladung an einen Kunden ging. Der
 * automatische Einladungs-Batch (portal:send-invitations) nutzt ihn, um die
 * 7-Tage-Erinnerungen nach einer konfigurierbaren Anzahl Versuche zu stoppen
 * (Schutz der Absender-Reputation - vgl. bekanntes Spam-Thema bei Outlook).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('invitation_count')->default(0)->after('invitation_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('invitation_count');
        });
    }
};
