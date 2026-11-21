<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empfehlungen von der eigenen Facebook-Seite (Betreiber-Wunsch 21.11.2026).
 *
 * Gespeichert wird nur, was die Website zeigen darf: der GEKUERZTE Name
 * ("Ahmad A." - nie der volle Name, nie die Facebook-Kennung der Person),
 * der Text, die Art der Empfehlung und das Datum. Gezeigt wird eine Zeile
 * erst nach Freigabe durch einen Menschen.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('facebook_bewertungen', function (Blueprint $table) {
            $table->id();
            // Kennung der Empfehlung bei Facebook (open_graph_story.id) bzw.
            // ein Hash aus Zeitpunkt + Text, wenn Facebook keine liefert.
            $table->string('fb_kennung', 120)->unique();
            $table->string('name_kurz', 80)->nullable();
            $table->text('text')->nullable();
            $table->string('empfehlung', 12)->nullable(); // positive|negative
            $table->unsignedTinyInteger('sterne')->nullable();
            $table->timestamp('verfasst_at')->nullable();
            $table->string('status', 12)->default('neu'); // neu|freigegeben|abgelehnt
            $table->foreignId('geprueft_von')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('geprueft_at')->nullable();
            // Bei Facebook nicht mehr vorhanden (geloescht/zurueckgezogen):
            // wird nie mehr gezeigt, die Zeile bleibt als Nachweis.
            $table->timestamp('entfernt_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'entfernt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_bewertungen');
    }
};
