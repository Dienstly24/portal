<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grundlage für das Partnerportal:
 * - partners.user_id  -> optionaler Login-Account (role=partner)
 * - partners.logo_path -> Firmenlogo des Partners (im Portal sichtbar)
 * - customers.partner_id -> ordnet einen Kunden einem Partner zu (dessen
 *   Portal ihn dann – strikt gescoped – sehen darf)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('user_id')->nullable()->after('id')->index();
            $table->string('logo_path')->nullable()->after('notes');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('partner_id')->nullable()->after('user_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['user_id', 'logo_path']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('partner_id');
        });
    }
};
