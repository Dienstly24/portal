<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partnerverwaltung + Provisionen (Architekturplan Abschnitte 10/16,
 * Umsetzung Priorität 6). Partner sind Makler-/Vertriebspartner und
 * Gesellschaften, von denen Provisionsgutschriften eingehen - bewusst
 * KEIN zweites Kundenverzeichnis (Kunden bleiben in customers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('partner_number')->nullable()->unique();
            $table->string('contact_email')->nullable();
            // Absender-Domains für die automatische Erkennung eingehender
            // Mails (Architekturplan Abschnitt 16), z. B. ["fondsfinanz.de"]
            $table->json('email_domains')->nullable();
            $table->string('iban')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('partner_id');
            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
            $table->uuid('contract_id')->nullable();
            $table->foreign('contract_id')->references('id')->on('contracts')->nullOnDelete();
            $table->string('credit_note_number')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->date('statement_date')->nullable();
            // HITL (Architekturplan Abschnitt 13): Buchungen sind
            // Ein-Klick-Bestätigung - nie automatisch in Lexoffice.
            $table->string('status')->default('pending_review'); // pending_review|booked|rejected
            $table->string('lexoffice_voucher_id')->nullable();
            $table->uuid('email_message_id')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'status']);
            // Dieselbe Gutschrift desselben Partners nicht doppelt erfassen
            $table->unique(['partner_id', 'credit_note_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('partners');
    }
};
