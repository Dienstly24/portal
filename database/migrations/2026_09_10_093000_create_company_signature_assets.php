<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UNTERNEHMENSSIGNATUREN: die hinterlegte Unterschrift des Betriebs, der
 * Firmenstempel und das Logo.
 *
 * BEWUSST KEINE ZEILE IN signature_signers. Ein Unterzeichner ist ein
 * MENSCH mit E-Mail, Zugangstoken, Zustimmung, IP und Zeitpunkt - ein
 * Stempel hat davon nichts. Beides in eine Tabelle zu zwingen hiesse, eine
 * Grafik im Protokoll wie eine abgegebene Willenserklaerung aussehen zu
 * lassen; genau diese Verwechslung ist im Streitfall teuer. Deshalb eine
 * eigene Tabelle, ein eigener Ort im Dokument und ein eigener Vermerk im
 * Protokoll ("eingesetzt von <Mitarbeiter>", nicht "unterschrieben von").
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('company_signature_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            // unterschrift | stempel | logo
            $table->string('type', 20)->index();
            // Immer auf der PRIVATEN Platte, wie alles in diesem Modul.
            $table->string('path');
            $table->unsignedInteger('width')->default(0);
            $table->unsignedInteger('height')->default(0);
            $table->unsignedInteger('bytes')->default(0);
            $table->char('hash', 64);
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            // foreignId, NICHT uuid: users.id ist ein bigint. SQLite prueft
            // Spaltentypen nicht und liess die Fremdschluessel-Bedingung
            // durchgehen - MySQL lehnt sie ab (errno 150). Dieselbe Form wie
            // in den uebrigen Signatur-Tabellen.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['type', 'active']);
        });

        Schema::table('signature_fields', function (Blueprint $table) {
            // Ein Feld gehoert ENTWEDER einem Unterzeichner ODER traegt ein
            // Firmenbild. Beides zugleich waere ein Widerspruch, und der
            // Dienst weist ihn ab.
            $table->uuid('company_asset_id')->nullable()->after('signature_signer_id');
            $table->foreign('company_asset_id')->references('id')->on('company_signature_assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('signature_fields', function (Blueprint $table) {
            $table->dropForeign(['company_asset_id']);
            $table->dropColumn('company_asset_id');
        });
        Schema::dropIfExists('company_signature_assets');
    }
};
