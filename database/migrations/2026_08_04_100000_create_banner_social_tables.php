<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social-Publishing fuer die Bannerverwaltung (Phase 1, Betreiber-Auftrag
 * 04.08.2026): Ein Banner bekommt optional EINEN Social-Media-Post
 * (Beitragstext DE/AR + oeffentliches Klick-Ziel) und je gewaehlter
 * Plattform (Facebook/Instagram/TikTok) einen Kanal mit eigenem
 * Tracking-Kurzlink (/s/{code}) und Veroeffentlichungs-Protokoll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banner_social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('banner_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('caption_de')->nullable();
            $table->text('caption_ar')->nullable();
            // Nur oeffentliche Ziele (Website): Portal-interne Pfade sind
            // fuer Social-Besucher ohne Login nicht erreichbar.
            $table->string('target_url', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('banner_social_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('banner_social_post_id')->constrained('banner_social_posts')->cascadeOnDelete();
            $table->string('platform', 20); // facebook|instagram|tiktok
            $table->string('short_code', 20)->unique(); // Kurzlink /s/{code}
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamp('last_click_at')->nullable();
            // Protokoll: wer hat den Beitrag wann tatsaechlich gepostet.
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['banner_social_post_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_social_channels');
        Schema::dropIfExists('banner_social_posts');
    }
};
