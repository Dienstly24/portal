<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KI-Anbieter aus der Oberflaeche pflegbar (Auftrag Abschnitte 81/95/101).
 *
 * Bewusst als eigene Tabelle und nicht als weitere SystemSettings:
 * ein Zugang ist mehr als ein Wert (Schluessel, Modell, Grenzen, aktiv
 * ja/nein), und er ist ein GEHEIMNIS - dieselbe Bauform wie
 * `channel_accounts`, damit im Projekt genau eine Art existiert, wie ein
 * Zugang aussieht.
 *
 * Mehrere Zeilen je Anbieter sind erlaubt (zweiter Zugang, Testzugang),
 * AKTIV ist immer hoechstens einer - das erzwingt der Dienst, nicht die
 * Datenbank: ein UNIQUE waere hier zu starr (beim Wechsel muesste man
 * erst abschalten, bevor man einschalten kann).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_provider_accounts', function (Blueprint $table) {
            $table->id();
            // claude | openai | ... - der Schluessel, den der Adapter
            // ueber name() meldet. KEIN Enum: ein neuer Anbieter darf
            // keine Migration kosten (Abschnitt 101).
            $table->string('provider', 40);
            $table->string('name');
            // Verschluesselt (Cast im Model), nie im Klartext lesbar,
            // nie in der Oberflaeche, nie im Log.
            $table->text('credentials')->nullable();
            // Leer = das Modell des Adapters gilt weiter.
            $table->string('model')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 40)->nullable();
            $table->timestamps();
            $table->index(['provider', 'is_active'], 'ai_provider_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_accounts');
    }
};
