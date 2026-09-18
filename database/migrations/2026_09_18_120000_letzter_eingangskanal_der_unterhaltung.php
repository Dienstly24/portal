<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Antwortweg braucht eine EINDEUTIGE Quelle.
 *
 * Bisher suchte `ChannelRoutingService::defaultChannelId()` die letzte
 * EINGEHENDE Nachricht per `latest('created_at')`. Die Spalte hat aber
 * keine Sekundenbruchteile: schreibt ein Kunde im selben Sekundenfenster
 * ueber zwei Kanaele (Webhook-Stapel ist der Normalfall, nicht die
 * Ausnahme), ist die Reihenfolge der Zeilen UNBESTIMMT - die Datenbank
 * darf jede der beiden zuerst liefern. Genau so fiel der Test auf MySQL
 * durch, waehrend er auf SQLite bestand.
 *
 * Ein Tiebreaker auf `id` hilft hier NICHT: `customer_messages.id` ist
 * ein ZUFAELLIGES UUID v4 (`Str::uuid()`), nicht zeitlich sortierbar. Er
 * machte das Ergebnis nur wiederholbar falsch statt zufaellig falsch.
 *
 * Deshalb dieselbe Bauform wie beim bereits vorhandenen
 * `last_channel_id`: der Wert wird in der ECHTEN Verarbeitungsreihenfolge
 * geschrieben, wo die Reihenfolge noch bekannt ist. Danach ist der
 * Antwortweg ein Spaltenzugriff und kein Ratespiel.
 *
 * KEIN Nachtrag noetig: bleibt die Spalte leer, faellt der Antwortweg auf
 * `last_channel_id` zurueck - und das ist fuer den Altbestand bereits die
 * beste verfuegbare Antwort (es wurde ebenfalls in Ankunftsreihenfolge
 * geschrieben). Ein Nachtrag muesste dieselbe unbestimmte Sortierung
 * benutzen, die hier gerade abgeschafft wird.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('last_inbound_channel_id')->nullable()
                ->after('last_channel_id')
                ->constrained('channels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_inbound_channel_id');
        });
    }
};
