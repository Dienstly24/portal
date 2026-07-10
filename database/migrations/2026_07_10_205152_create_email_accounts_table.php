<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zentrale Verwaltung von E-Mail-Postfächern (Priorität 1 der
 * KI-Systemerweiterung). Zugangsdaten liegen in `credentials` und
 * werden über einen encrypted-Cast im Model verschlüsselt (siehe
 * App\Models\EmailAccount) - niemals im Klartext, niemals in .env.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email_address')->unique();
            // hostinger_imap | imap | gmail_oauth | microsoft_oauth
            $table->string('provider')->default('imap');
            $table->string('imap_host')->nullable();
            $table->unsignedSmallInteger('imap_port')->nullable();
            $table->string('imap_encryption')->nullable(); // ssl | tls | null
            $table->string('smtp_host')->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_encryption')->nullable();
            $table->string('username')->nullable();
            // Verschlüsselt: IMAP-Passwort ODER OAuth-Refresh-Token (JSON)
            $table->text('credentials')->nullable();
            $table->json('folders')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
