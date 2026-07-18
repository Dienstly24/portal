<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * documents.contract_id wurde in add_contract_meta als blanke UUID-Spalte
 * ohne Index angelegt. Sie wird bei der Vertragsverknuepfung (Smart Document
 * Upload: linkMatchingContract / createContractFromExtraction) und beim Laden
 * der Vertrags-Dokumente gefiltert. Ohne Index wird das mit wachsender
 * Dokumentzahl zum Full-Table-Scan - hier nachgeruestet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->index('contract_id');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['contract_id']);
        });
    }
};
