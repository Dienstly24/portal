<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Passwort-Haertung (Betreiber-Vorgabe 18.08.2026).
 *
 * - password_changed_at: wann das Passwort ZULETZT bewusst geaendert wurde.
 *   Unterscheidet sich absichtlich von portal_password_set_at: letzteres
 *   setzt auch das SYSTEM beim Einladungsversand (Startpasswort =
 *   Geburtsdatum). password_changed_at setzt nur der Mensch selbst - erst
 *   damit ist belegbar, dass ein Konto ein wirklich privates Passwort hat.
 *
 * - must_change_password: erzwingt beim naechsten Login den Wechsel.
 *   Gesetzt bei allen system-vergebenen Passwoertern (Geburtsdatum,
 *   Admin-Reset, CLI-Vergabe). Bisher stand das Startpasswort = Geburtsdatum
 *   unbegrenzt - ein Datum, das auf jedem Ausweis und in jedem Vertrag
 *   steht und damit faktisch oeffentlich ist.
 *
 * Backfill: Wer sich schon eingeloggt UND ein Passwort gesetzt hat, wird
 * nicht rueckwirkend zum Wechsel gezwungen (das waere eine Zwangsaktion
 * fuer den gesamten Bestand). Fuer Kundenkonten, deren Passwort noch das
 * vom System vergebene Geburtsdatum ist (portal_password_set_at gesetzt,
 * aber noch NIE eingeloggt), wird der Wechsel dagegen vorgemerkt.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_changed_at')->nullable()->after('portal_password_set_at');
            $table->boolean('must_change_password')->default(false)->after('password_changed_at');
        });

        // Bestand: bereits eingeloggte Konten gelten als "hat ein eigenes
        // Passwort" - bestmoegliche Naeherung, ohne jemanden auszusperren.
        DB::table('users')->whereNotNull('first_login_at')->update([
            'password_changed_at' => DB::raw('first_login_at'),
        ]);

        // Kunden mit System-Startpasswort, die sich noch nie angemeldet
        // haben: beim ersten Login ist ein eigenes Passwort faellig.
        DB::table('users')
            ->where('role', 'customer')
            ->whereNull('first_login_at')
            ->whereNotNull('portal_password_set_at')
            ->update(['must_change_password' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password_changed_at', 'must_change_password']);
        });
    }
};
