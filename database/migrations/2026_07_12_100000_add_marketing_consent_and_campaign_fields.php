<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-Mail-Marketing Paket A (Verbesserungsplan 2026-07-12):
 * - Abmeldung/Einwilligung am Kunden (UWG §7 / DSGVO): Marketing-Mails
 *   nur mit Einwilligung, Abmelde-Link ohne Login über unsubscribe_token.
 * - Kampagnenstatus als String statt Enum, damit der Queue-Fluss
 *   draft -> scheduled -> sending -> sent abbildbar ist.
 * - scheduled_for für zeitversetzten Versand (Paket B).
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('marketing_consent')->default(true)->after('preferred_lang');
            $table->timestamp('unsubscribed_at')->nullable()->after('marketing_consent');
            $table->string('unsubscribe_token', 64)->nullable()->unique()->after('unsubscribed_at');
        });
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->change();
            $table->timestamp('scheduled_for')->nullable()->after('sent_at');
        });
    }

    public function down(): void {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['marketing_consent', 'unsubscribed_at', 'unsubscribe_token']);
        });
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->dropColumn('scheduled_for');
        });
    }
};
