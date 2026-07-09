<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Code Review (Final Polish Punkt 9): family_members war ein nie
 * genutztes Duplikat von customer_family - kein Code-Pfad hat je
 * hineingeschrieben oder gelesen. Sicherheitsnetz: Die Tabelle wird
 * nur entfernt, wenn sie tatsächlich leer ist; andernfalls bleibt sie
 * unangetastet (und wird nur geloggt).
 */
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('family_members')) {
            return;
        }
        if (DB::table('family_members')->count() > 0) {
            \Log::warning('family_members enthält Daten und wurde NICHT entfernt - bitte manuell prüfen.');
            return;
        }
        Schema::dropIfExists('family_members');
    }

    public function down(): void {
        // bewusst leer - die Tabelle war ungenutzt
    }
};
