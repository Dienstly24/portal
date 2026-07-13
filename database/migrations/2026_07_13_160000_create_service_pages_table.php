<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leistungsseiten (oeffentlich unter /leistungen/{slug}): pro Leistung eine
 * eigene Seite mit Definition, Kurzinfos und FAQ - zweisprachig (DE/AR) und
 * vollstaendig ueber die Adminoberflaeche pflegbar. Das Anfrageformular auf
 * jeder Seite erzeugt ein Ticket im System.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('category')->nullable();     // Gruppierung, z. B. versicherung/kfz/energie
            $table->string('icon', 16)->nullable();      // Emoji fuer Karten/Hero

            $table->string('title_de');
            $table->string('title_ar')->nullable();
            $table->string('subtitle_de')->nullable();
            $table->string('subtitle_ar')->nullable();

            $table->text('intro_de')->nullable();        // "Was ist ...": Definition
            $table->text('intro_ar')->nullable();
            $table->text('highlights_de')->nullable();   // Kurzinfos, eine pro Zeile
            $table->text('highlights_ar')->nullable();
            $table->json('faq')->nullable();             // [{q_de,q_ar,a_de,a_ar}, ...]

            $table->string('image_path')->nullable();
            $table->string('meta_description_de')->nullable();
            $table->string('meta_description_ar')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_pages');
    }
};
