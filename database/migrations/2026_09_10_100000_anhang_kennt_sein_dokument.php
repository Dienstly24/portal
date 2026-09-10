<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ein Chat-Anhang und eine Akten-Unterlage sind ZWEI Dinge - der Anhang
 * ist, was jemand geschickt hat; die Unterlage ist, was der Betrieb als
 * Nachweis fuehrt. Nicht jedes Foto im Chat ist eine Unterlage.
 *
 * Die Bruecke fehlte aber ganz: ein per WhatsApp geschickter
 * Versicherungsschein war in der Unterhaltung sichtbar und in der
 * Kundenakte unauffindbar - er lief nie durch Kategorisierung,
 * Duplikatspruefung oder Dokumentenanalyse.
 *
 * Diese Spalte IST die Bruecke und zugleich der Schutz gegen doppelte
 * Uebernahme: ist sie gesetzt, wurde der Anhang bereits uebernommen.
 * `nullOnDelete`, weil das Loeschen einer Unterlage den Chatverlauf nie
 * beschaedigen darf - der Anhang bleibt, er ist dann nur wieder
 * "nicht uebernommen".
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_message_attachments', function (Blueprint $table) {
            $table->uuid('document_id')->nullable()->after('external_media_id');
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::table('customer_message_attachments', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
            $table->dropIndex(['document_id']);
            $table->dropColumn('document_id');
        });
    }
};
