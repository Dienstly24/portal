<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tickets.type von ENUM auf String umstellen (Audit 07.08.2026).
 *
 * Dieselbe Lehre wie bei tasks.type (07/2026) und tickets.status: ein ENUM
 * bricht auf MySQL still, sobald ein neuer Typ-Wert dazukommt, waehrend die
 * SQLite-Tests gruen bleiben. Ticket::TYPES ist die Quelle der Wahrheit und
 * wird in allen Controllern per `in:`-Validierung erzwungen - die DB soll das
 * nicht doppelt und unflexibel absichern. Rein additiv, bestehende Werte
 * bleiben gueltig (die bisherigen Enum-Werte sind eine Teilmenge des Strings).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('type', 30)->default('other')->change();
        });
    }

    public function down(): void
    {
        // Bewusst kein Rueckbau auf ENUM: ein spaeter ergaenzter Typ-Wert
        // wuerde dabei ungueltig und der Rollback schluege fehl.
    }
};
