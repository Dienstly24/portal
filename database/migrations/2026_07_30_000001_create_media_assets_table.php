<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medienverwaltung der Website (/admin/medien): Jedes Bild gehoert optional
 * zu genau einem "Slot" (fester Platz auf der Website). Hochladen + Slot
 * waehlen + Alt-Text pflegen ersetzt FTP und Code-Aenderungen komplett.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            // Fester Platz auf der Website (config/website.php 'slots').
            // NULL = Bild liegt nur in der Bibliothek/im Archiv.
            $table->string('slot')->nullable()->index();
            $table->string('title');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            // Alt-Texte sind PFLICHT (BFSG/Barrierefreiheit + SEO).
            $table->string('alt_de', 500);
            $table->string('alt_ar', 500);
            // Optionaler Bildnachweis (Quelle/Fotograf) fuer die Nachweis-Seite.
            $table->string('credit', 500)->nullable();
            // Original (privat, Archiv) + erzeugte Web-Varianten (public).
            $table->string('original_path');
            $table->json('variants')->nullable();
            $table->string('processing_status', 20)->default('pending'); // pending|ready|failed
            $table->text('processing_error')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes(); // Papierkorb: 30 Tage wiederherstellbar
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
