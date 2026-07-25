<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vermittler-Provisionen (AUSGANG) - getrennt von `commissions`:
 * commissions = eingehende Provisionsgutschriften VON Gesellschaften/Partnern,
 * provisions  = Verguetungen AN eigene Mitarbeiter oder Vertriebspartner fuer
 * geworbene Neukunden/-vertraege. Empfaenger ist GENAU EINER von beiden
 * (user_id ODER partner_id). Betraege werden manuell bestaetigt (HITL),
 * Status: offen -> ausgezahlt (oder storniert; Historie bleibt erhalten).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Empfaenger: Mitarbeiter ...
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // ... oder Vertriebspartner
            $table->uuid('partner_id')->nullable();
            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
            // Optionaler Bezug: geworbener Kunde / Abrechnungszeitraum
            $table->uuid('customer_id')->nullable();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('EUR');
            $table->string('status')->default('offen'); // offen|ausgezahlt|storniert
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['partner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisions');
    }
};
