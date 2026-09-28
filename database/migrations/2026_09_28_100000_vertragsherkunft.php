<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vertragsherkunft: Eigenvertrag, Fremdvertrag (nur Dokumentation) oder
 * uebernommen (Betreiber-Auftrag 28.09.2026).
 *
 * Anlass: ein Kunde will seinen ADAC-Vertrag loswerden, wir erfassen ihn als
 * gekuendigten VORVERTRAG und vermitteln den neuen (DA Direkt). Wochen
 * spaeter weiss niemand mehr, dass der ADAC-Vertrag nie unserer war - und
 * bei einer Rueckfrage des Kunden arbeitet jemand an einem Vertrag, fuer den
 * wir weder Mandat noch Courtage haben.
 *
 * ALTBESTAND: jeder vorhandene Vertrag wird 'brokered' - das ist der
 * Normalfall und die Annahme, unter der er bisher ohnehin gefuehrt wurde.
 * Dass es nur eine ANNAHME ist, haelt `origin_verified = false` fest; die
 * Pruefliste im Dashboard arbeitet sie ab. Ein Formular setzt den Wert beim
 * Anlegen auf true (dort hat ein Mensch aktiv gewaehlt), automatische
 * Anlagewege (Dokumenten-Eingang) lassen ihn auf false - dort hat niemand
 * gewaehlt.
 *
 * NUR EINE VERKETTUNGS-SPALTE: der Nachfolger zeigt auf seinen Vorgaenger
 * (`replaces_contract_id`). "Ersetzt durch" wird daraus abgeleitet. Zwei
 * Spalten fuer dieselbe Aussage (A ersetzt B / B ersetzt durch A) koennten
 * auseinanderlaufen - dieselbe Ueberlegung wie bei der Signaturgruppe.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('origin', 20)->default('brokered')->after('stage')->index();
            $table->boolean('origin_verified')->default(false)->after('origin')->index();
            $table->string('previous_broker', 150)->nullable()->after('origin_verified');
            $table->text('origin_note')->nullable()->after('previous_broker');
            $table->date('transfer_date')->nullable()->after('origin_note');
            $table->boolean('cancellation_submitted_by_us')->default(false)->after('transfer_date');
            $table->foreignUuid('replaces_contract_id')->nullable()->after('cancellation_submitted_by_us')
                ->constrained('contracts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_contract_id');
            $table->dropIndex(['origin']);
            $table->dropIndex(['origin_verified']);
            $table->dropColumn(['origin', 'origin_verified', 'previous_broker', 'origin_note',
                'transfer_date', 'cancellation_submitted_by_us']);
        });
    }
};
