<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portal-Status-Tracking (Kundenportal-Login-Flow):
 * - invitation_sent_at: wann die Einladungs-/Willkommensmail rausging
 * - first_login_at: erster erfolgreicher Portal-Login
 * - portal_password_set_at: wann ein NUTZBARES Passwort etabliert wurde
 *   (Startpasswort per Geburtsdatum, Admin-Vergabe oder Passwort-Reset).
 *   NULL = Konto existiert nur technisch (z. B. Import) - Login unmöglich.
 *
 * Backfill: Wer schon eingeloggt war, hatte zwangsläufig ein nutzbares
 * Passwort - last_login_at wird als bestmögliche Näherung übernommen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('invitation_sent_at')->nullable()->after('last_login_at');
            $table->timestamp('first_login_at')->nullable()->after('invitation_sent_at');
            $table->timestamp('portal_password_set_at')->nullable()->after('first_login_at');
        });

        DB::table('users')->whereNotNull('last_login_at')->update([
            'first_login_at' => DB::raw('last_login_at'),
            'portal_password_set_at' => DB::raw('last_login_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['invitation_sent_at', 'first_login_at', 'portal_password_set_at']);
        });
    }
};
