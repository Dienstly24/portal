<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-Service-Kundenmanagement mit Genehmigungsworkflow.
 * Grundprinzip: Kundenanfragen erzeugen NUR einen Change Request -
 * die echten Datensätze (Familie, Adressen, Kontakte, Bank, Verträge)
 * werden erst bei Genehmigung durch einen Mitarbeiter angelegt/geändert.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('customer_change_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20); // family|address|email|phone|bank|contract|profile
            $table->json('old_data')->nullable();
            $table->json('new_data');
            $table->string('status', 10)->default('pending'); // pending|approved|rejected
            $table->timestamp('requested_at')->useCurrent();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->string('type', 20)->default('main'); // main|billing|postal|other
            $table->string('street');
            $table->string('zip', 10);
            $table->string('city');
            $table->string('country')->default('Deutschland');
            $table->timestamps();
            $table->index(['customer_id', 'type']);
        });

        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->string('type', 10); // email|phone
            $table->string('label', 30)->default('privat'); // privat|geschaeftlich|sonstige
            $table->string('value');
            $table->timestamps();
            $table->index(['customer_id', 'type']);
        });

        // Geschlecht (Punkt 3) + Kontoinhaber für Bankänderungen (Punkt 6)
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'gender')) {
                $table->string('gender', 10)->nullable(); // male|female|diverse
            }
            if (!Schema::hasColumn('customers', 'account_holder')) {
                $table->string('account_holder')->nullable();
            }
        });

        // Benachrichtigungen können jetzt auch auf Change Requests zeigen
        Schema::table('internal_notifications', function (Blueprint $table) {
            $table->uuid('message_id')->nullable()->change();
            $table->uuid('change_request_id')->nullable();
            $table->foreign('change_request_id')->references('id')->on('customer_change_requests')->cascadeOnDelete();
        });
    }

    public function down(): void {
        Schema::table('internal_notifications', function (Blueprint $table) {
            $table->dropForeign(['change_request_id']);
            $table->dropColumn('change_request_id');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['gender', 'account_holder']);
        });
        Schema::dropIfExists('customer_contacts');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customer_change_requests');
    }
};
