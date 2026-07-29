<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nachweispflicht + automatische Pruefung fuer Kundenaenderungen
 * (Betreiber-Vorgabe 29.07.2026).
 *
 * Sensible Selbstbedienungs-Aenderungen (Bankverbindung, Adresse, Name)
 * duerfen nicht mehr ohne Beleg eingereicht werden. Der Kunde laedt den
 * Nachweis hoch (Ausweis beidseitig, Meldebescheinigung, Kontonachweis),
 * das System liest ihn kostenlos aus (Textebene/OCR) und prueft, ob der
 * BEANTRAGTE Wert wirklich im Dokument steht. Zusaetzlich erfasst der
 * Kunde, AB WANN die Aenderung gilt.
 *
 * Nach der Freigabe werden Mitteilungen an die Gesellschaften des Kunden
 * vorbereitet (je Gesellschaft ein fertiger Text + Nachweis als Anhang),
 * die ein Mitarbeiter nur noch pruefen und versenden muss.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_change_requests', function (Blueprint $table) {
            // "Ab wann gilt die Aenderung" - vom Kunden erfasst, nie geraten.
            $table->date('effective_from')->nullable()->after('new_data');
            // none|missing|pending|verified|partial|mismatch|unreadable
            $table->string('proof_status', 20)->default('none')->after('effective_from');
            $table->text('proof_result')->nullable()->after('proof_status');
            $table->timestamp('proof_checked_at')->nullable()->after('proof_result');
            // Freigabe ohne Mitarbeiter (nur bei vollstaendig geprueftem Nachweis)
            $table->boolean('auto_approved')->default(false)->after('proof_checked_at');
        });

        Schema::create('change_request_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('change_request_id');
            $table->foreign('change_request_id')->references('id')
                ->on('customer_change_requests')->cascadeOnDelete();
            // id_front|id_back|meldebescheinigung|bank_proof|other
            $table->string('kind', 30)->default('other');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('disk', 20)->default('local');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            // pending|match|partial|no_match|unreadable
            $table->string('check_status', 20)->default('pending');
            $table->text('check_result')->nullable();
            $table->timestamps();
            $table->index('change_request_id');
        });

        Schema::create('change_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('change_request_id');
            $table->foreign('change_request_id')->references('id')
                ->on('customer_change_requests')->cascadeOnDelete();
            $table->uuid('customer_id');
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->string('insurer');
            // Vertragsnummern der betroffenen Vertraege (nur Anzeige/Text)
            $table->string('contract_numbers')->nullable();
            $table->string('recipient')->nullable();
            $table->string('subject');
            $table->text('body');
            $table->string('status', 20)->default('pending'); // pending|sent|skipped
            $table->string('channel', 20)->nullable();        // email|post|portal
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['change_request_id', 'status']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_notifications');
        Schema::dropIfExists('change_request_documents');
        Schema::table('customer_change_requests', function (Blueprint $table) {
            $table->dropColumn([
                'effective_from', 'proof_status', 'proof_result',
                'proof_checked_at', 'auto_approved',
            ]);
        });
    }
};
