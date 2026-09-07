<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WhatsApp als Kanal (Auftrag Abschnitt 30).
 *
 * Nur eine ZEILE - genau das war der Anspruch der Abstraktion: ein neuer
 * Kanal kostet einen Adapter und einen Eintrag, aber keine Aenderung an
 * Unterhaltung, Nachricht, Zuweisung oder Posteingang.
 *
 * Der Kanal entsteht INAKTIV. Er geht erst in Betrieb, wenn ein Admin
 * unter /admin/kanaele ein Konto mit Zugangsdaten hinterlegt und den
 * Kanal einschaltet - eine Anbindung schaltet sich nicht selbst live.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::table('channels')->where('key', 'whatsapp')->exists()) {
            return;
        }

        DB::table('channels')->insert([
            'key' => 'whatsapp',
            'name' => 'WhatsApp',
            'driver' => 'whatsapp',
            'is_active' => false,
            'sort' => 30,
            'capabilities' => json_encode([
                'supportsMedia' => true,
                'supportsReadReceipts' => true,
                'supportsDeliveryReceipts' => true,
                'supportsCustomers' => true,
                'supportsTemplates' => true,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('channels')->where('key', 'whatsapp')->delete();
    }
};
