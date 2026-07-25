<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provisions-Management (Betreiber-Vorgabe 25.07.2026): Provisionen werden
 * bei Vertragsanlage AUTOMATISCH erzeugt und durchlaufen einen
 * Freigabe-Workflow. Erweiterungen an `provisions`:
 * - contract_id:        ausloesender Vertrag (nullOnDelete - die Provision
 *                       bleibt als Buchungshistorie IMMER erhalten)
 * - contract_type:      Sparte, denormalisiert (ueberlebt Vertragsloeschung)
 * - insurer:            Gesellschaft/Anbieter, denormalisiert
 * - type:               neuvertrag | storno | bonus | abzug | manuell
 * - related_provision_id: verknuepft die Storno-GEGENBUCHUNG (negativer
 *                       Betrag) mit der Original-Provision - Originale werden
 *                       NIE geloescht oder veraendert (Buchhaltung)
 * - approved_by/-at:    Freigabe (Status offen -> freigegeben -> ausgezahlt)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisions', function (Blueprint $table) {
            $table->uuid('contract_id')->nullable()->after('customer_id');
            $table->foreign('contract_id')->references('id')->on('contracts')->nullOnDelete();
            $table->string('contract_type', 60)->nullable()->after('contract_id');
            $table->string('insurer')->nullable()->after('contract_type');
            $table->string('type', 30)->default('manuell')->after('insurer');
            $table->uuid('related_provision_id')->nullable()->after('type');
            $table->foreign('related_provision_id')->references('id')->on('provisions')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');

            $table->index(['contract_id', 'type']);
            $table->index(['type', 'status']);
            $table->index('contract_type');
        });
    }

    public function down(): void
    {
        Schema::table('provisions', function (Blueprint $table) {
            $table->dropForeign(['contract_id']);
            $table->dropForeign(['related_provision_id']);
            $table->dropForeign(['approved_by']);
            $table->dropIndex(['contract_id', 'type']);
            $table->dropIndex(['type', 'status']);
            $table->dropIndex(['contract_type']);
            $table->dropColumn([
                'contract_id', 'contract_type', 'insurer', 'type',
                'related_provision_id', 'approved_by', 'approved_at',
            ]);
        });
    }
};
