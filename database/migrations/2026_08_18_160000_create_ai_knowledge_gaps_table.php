<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wissensluecken: wonach der Assistent gesucht und NICHTS gefunden hat
 * (Betreiber-Auftrag 18.08.2026).
 *
 * Bisher endete ein Fehlschlag stumm - der Assistent uebergab an das Team
 * und niemand erfuhr, dass eine Frage keine hinterlegte Antwort hat.
 * Damit konnte die Wissensbasis nur wachsen, wenn jemand von sich aus auf
 * das richtige Thema kam.
 *
 * Gespeichert wird NUR der Suchbegriff (Stichworte, die das Modell aus der
 * Frage bildet - nicht der Nachrichtentext des Kunden), dazu ein Zaehler.
 * Kein Kundenbezug, keine Nachricht, keine Akte - die Luecke ist eine
 * Aussage ueber UNSERE Wissensbasis, nicht ueber einen Kunden.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_knowledge_gaps', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Normalisierte Fassung fuer die Zusammenfassung gleicher
            // Fragen ("Stromangebote" / "strom angebote" = eine Luecke).
            $table->string('topic_key', 190);
            // Der Suchbegriff in lesbarer Form (zuletzt gesehene Fassung).
            $table->string('topic', 190);
            // 'kunde' = Portal-Assistent, 'website' = Besucher-Assistent.
            $table->string('scope', 20)->default('kunde');
            $table->string('language', 5)->nullable();

            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            // offen | erledigt | ignoriert
            $table->string('status', 20)->default('offen');
            $table->uuid('resolved_entry_id')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->unique(['topic_key', 'scope']);
            $table->index(['status', 'hits']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_gaps');
    }
};
