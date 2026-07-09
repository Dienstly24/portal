<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumente können jetzt auf verschiedenen Disks liegen.
 * Bestand bleibt 'public' (kein Datenverlust, Links funktionieren
 * weiter über den neuen Download-Controller); neue Vertragsdokumente
 * werden auf 'local' (storage/app/private) gespeichert und sind damit
 * nicht mehr per direkter URL erreichbar.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('disk', 20)->default('public');
        });
    }
    public function down(): void {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('disk');
        });
    }
};
