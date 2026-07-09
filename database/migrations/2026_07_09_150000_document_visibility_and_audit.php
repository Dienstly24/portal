<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumentenmanagement (Spec Teil 7):
 * - visibility: 'customer' (im Portal sichtbar) oder 'internal'
 *   (NUR Mitarbeiter). Bestand bleibt 'customer' (bisheriges Verhalten).
 *   Über die Sichtbarkeit entscheiden ausschließlich Mitarbeiter.
 * - uploaded_by / updated_by für den Audit-Trail; Ansichten werden
 *   über activity_logs protokolliert (document_viewed).
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('visibility', 10)->default('customer'); // customer|internal
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('file_size')->nullable();
        });
    }
    public function down(): void {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uploaded_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['visibility', 'file_size']);
        });
    }
};
