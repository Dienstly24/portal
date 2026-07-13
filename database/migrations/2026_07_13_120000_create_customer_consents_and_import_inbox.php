<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Einwilligungsbasierte E-Mail-Verbindung (Variante A / Weiterleitung,
 * DSGVO Art. 6 Abs. 1 lit. a). Grundlage: docs/KONZEPT_EMAIL_EINWILLIGUNG_DSGVO.md.
 *
 * - customer_consents: nachweisbare, getrennte, widerrufbare Einwilligung
 *   (Art. 7). Speichert Textversion, IP und User-Agent als Nachweis sowie
 *   ein pro Einwilligung eindeutiges, geheimes Import-Token.
 * - email_accounts.is_customer_import: markiert das Sammelpostfach, an das
 *   Kunden vertragsbezogene Mails per Auto-Weiterleitung an
 *   import+<token>@... schicken. Nur solche Konten laufen durch die
 *   einwilligungs- und whitelist-gepruefte Import-Pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_consents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            // email_processing | marketing | ... (erweiterbar, keine neue Parallel-Logik)
            $table->string('type')->default('email_processing');
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('consent_text_version')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            // portal_settings | portal_registration | ...
            $table->string('source')->nullable();
            // Geheimes Token fuer die persoenliche Import-Adresse (nur email_processing).
            $table->string('import_token', 64)->nullable()->unique();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->index(['customer_id', 'type']);
        });

        Schema::table('email_accounts', function (Blueprint $table) {
            // Sammelpostfach fuer kundenseitige Weiterleitungen (Variante A).
            $table->boolean('is_customer_import')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->dropColumn('is_customer_import');
        });
        Schema::dropIfExists('customer_consents');
    }
};
