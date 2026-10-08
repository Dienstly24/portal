<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gemeinsam genutzte Kontaktdaten (PR-4 des Dubletten-Plans, M2 des
 * Berichts vom 03.10.2026): eine Familien-E-Mail, ein Festnetzanschluss,
 * das Konto der Eltern oder ein Mehrfamilienhaus sind KEIN Hinweis auf
 * eine Dublette. Ist ein Wert hier eingetragen, bildet er kein
 * Verdachtspaar mehr.
 *
 * Gespeichert wird NIE der Wert selbst, nur ein HMAC (die IBAN ist
 * verschluesselt, und eine Telefonnummer waere ungeschuetzt in Sekunden
 * erraten) plus eine maskierte Anzeige ("…3000").
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('geteilte_kontaktdaten', function (Blueprint $table) {
            $table->id();
            $table->string('art', 20);
            $table->char('wert_hash', 64);
            $table->string('anzeige', 80);
            $table->string('notiz', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['art', 'wert_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geteilte_kontaktdaten');
    }
};
