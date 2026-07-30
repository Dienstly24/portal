<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DSGVO-Nachweis fuer Website-Anfragen: Zeitpunkt, IP und der exakte
 * Einwilligungstext, dem der Absender zugestimmt hat (Arbeitsauftrag P0-1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('consent_given_at')->nullable()->after('guest_phone');
            $table->string('consent_ip', 45)->nullable()->after('consent_given_at');
            $table->text('consent_text')->nullable()->after('consent_ip');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['consent_given_at', 'consent_ip', 'consent_text']);
        });
    }
};
