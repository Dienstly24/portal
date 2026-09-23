<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rechnungen/Gutschriften des Vermittlers (TARIFCHECK24) als Beleg der
 * Zahlung (Betreiber-Auftrag 23.09.2026).
 *
 * Ablauf des Betriebs: Vertrag mit Referenz-Nr. -> monatliche CSV mit Id und
 * Status -> Rechnung als PDF/Bild. Erst die Rechnung BELEGT, dass die
 * Provision gezahlt wurde; der Status 4 der CSV ist eine Meldung des
 * Vermittlers, kein Beleg.
 *
 * ZWEISTUFIG wie jeder Import mit Folgen: die Rechnung wird zuerst gelesen
 * und als ENTWURF abgelegt (Ergebnis je Zeile in `result`), geschrieben wird
 * erst nach der Bestaetigung. Die Datei selbst bleibt als Beleg auf der
 * PRIVATEN Platte.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('vermittler_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('filename', 255);
            $table->string('file_path', 255)->nullable();
            // sha256: dieselbe Rechnung zweimal hochzuladen wird erkannt.
            $table->string('file_hash', 64)->index();
            $table->string('invoice_number', 80)->nullable()->index();
            $table->date('invoice_date')->nullable();
            $table->decimal('total_amount', 12, 2)->nullable();
            $table->string('status', 20)->default('entwurf')->index();
            $table->string('source', 20)->nullable();
            $table->unsignedInteger('rows_found')->default(0);
            $table->unsignedInteger('rows_confirmed')->default(0);
            $table->unsignedInteger('rows_deviation')->default(0);
            $table->unsignedInteger('rows_open')->default(0);
            $table->json('result')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('vermittler_settlements', function (Blueprint $table) {
            $table->foreignUuid('invoice_id')->nullable()->after('import_id')
                ->constrained('vermittler_invoices')->nullOnDelete();
            $table->decimal('invoice_amount', 12, 2)->nullable()->after('provision');
            $table->timestamp('payment_confirmed_at')->nullable()->after('invoice_amount');
        });
    }

    public function down(): void
    {
        Schema::table('vermittler_settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropColumn(['invoice_amount', 'payment_confirmed_at']);
        });
        Schema::dropIfExists('vermittler_invoices');
    }
};
