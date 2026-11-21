<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatische Freigabe der Facebook-Empfehlungen (Betreiber-Wunsch
 * 21.11.2026: "keine Zeit, selbst auszuwaehlen"). Das Merkmal haelt fest,
 * dass die Freigabe von der Regel kam und nicht von einem Menschen.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('facebook_bewertungen', function (Blueprint $table) {
            $table->boolean('auto_freigegeben')->default(false)->after('geprueft_at');
        });
    }

    public function down(): void
    {
        Schema::table('facebook_bewertungen', function (Blueprint $table) {
            $table->dropColumn('auto_freigegeben');
        });
    }
};
