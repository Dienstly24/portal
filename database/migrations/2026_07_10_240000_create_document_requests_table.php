<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumentenanfragen an Kunden (Architekturplan Abschnitte 9/14,
 * Priorität 7): Mitarbeiter/Workflow fordert ein konkretes Dokument an,
 * der Kunde sieht die Anfrage mit Status und Frist im Portal und lädt
 * direkt dazu hoch. KEIN zweites Dokumentenarchiv - der Upload landet
 * als normales Document, die Anfrage referenziert es nur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->uuid('contract_id')->nullable();
            $table->foreign('contract_id')->references('id')->on('contracts')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('deadline')->nullable();
            // open -> uploaded -> approved | rejected (zurück an Kunde)
            $table->string('status')->default('open');
            $table->uuid('document_id')->nullable();
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();
            $table->text('rejection_note')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_requests');
    }
};
