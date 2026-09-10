<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ZUSAETZLICHE IDENTITAETSPRUEFUNG je Signaturanfrage:
 * keine / Geburtsdatum / E-Mail-Bestaetigung.
 *
 * Aus dem bisherigen Ja-Nein-Schalter require_email_verification wird EIN
 * Wahlfeld. Zwei Spalten fuer dieselbe Frage waeren zwei Wahrheiten, die
 * frueher oder spaeter auseinanderlaufen - die alte Spalte faellt deshalb
 * weg, und das Modell bietet den alten Namen weiter als abgeleiteten Wert
 * an, damit kein Aufrufer bricht.
 *
 * BEWUSST KEIN SMS-WEG (Betreiber-Vorgabe): das waere ein weiterer
 * Dienstleister, ein weiterer Vertrag und eine weitere Datenspur.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->string('identity_check', 20)->default('email')->after('require_email_verification');
        });

        // Bestand uebernehmen, bevor die alte Spalte faellt.
        DB::table('signature_requests')->where('require_email_verification', true)->update(['identity_check' => 'email']);
        DB::table('signature_requests')->where('require_email_verification', false)->update(['identity_check' => 'keine']);

        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropColumn('require_email_verification');
        });

        Schema::table('signature_signers', function (Blueprint $table) {
            // Das Geburtsdatum steht VERSCHLUESSELT hier, nicht als Hash:
            // ein Geburtsdatum hat nur rund 40.000 plausible Werte, ein
            // Hash davon ist offline in Sekunden geraten. Gelesen wird es
            // nur zum Vergleich - nie angezeigt, nie versendet, nie in eine
            // URL geschrieben.
            $table->text('dob_check')->nullable()->after('verified_at');
            $table->unsignedTinyInteger('dob_attempts')->default(0)->after('dob_check');
            $table->timestamp('dob_blocked_until')->nullable()->after('dob_attempts');
            $table->timestamp('dob_verified_at')->nullable()->after('dob_blocked_until');
        });
    }

    public function down(): void
    {
        Schema::table('signature_signers', function (Blueprint $table) {
            $table->dropColumn(['dob_check', 'dob_attempts', 'dob_blocked_until', 'dob_verified_at']);
        });
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->boolean('require_email_verification')->default(true);
        });
        DB::table('signature_requests')->update([
            'require_email_verification' => DB::raw("identity_check = 'email'"),
        ]);
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropColumn('identity_check');
        });
    }
};
