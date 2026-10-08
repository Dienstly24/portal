<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Haushalt (PR-5b des Dubletten-/Familien-Plans, 08.10.2026).
 *
 * Ein Haushalt ist eine GRUPPE von Kundenakten, die zusammen wohnen und
 * wirtschaften - kein Paar wie customer_relationships und keine Rolle wie
 * customer_family_relations. Jede Akte bleibt vollstaendig eigenstaendig
 * (eigene Kundennummer, Vertraege, Dokumente, Portalzugang).
 *
 * Mitgliedschaften werden NIE geloescht, sondern beendet: `valid_until` ist
 * der Tag des AUSZUGS (erster Tag, an dem die Person NICHT mehr dazugehoert -
 * halb-offenes Intervall wie bei der Wechsel-Kette der Vertraege).
 * Je Haushalt und Kunde EINE Zeile; ein Wiedereinzug oeffnet sie erneut.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('haushalte', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120)->nullable();
            // 'manuell' (Kundenakte) oder 'uebernahme' (aus "Gleicher Haushalt"
            // per haushalte:aus-beziehungen-bilden) - nur Uebernahmen darf der
            // Befehl wieder zuruecknehmen.
            $table->string('herkunft', 20)->default('manuell');
            $table->string('notiz', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('haushalt_mitglieder', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('haushalt_id')->constrained('haushalte')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->boolean('hauptansprechpartner')->default(false);
            $table->boolean('beitragszahler')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['haushalt_id', 'customer_id']);
            $table->index(['customer_id', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('haushalt_mitglieder');
        Schema::dropIfExists('haushalte');
    }
};
