<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kanal "Website-Chat" fuer Gaeste (Kundenportal gesperrt, 20.11.2026).
 * Rein ergaenzend: eine Zeile in `channels`, nichts wird geaendert oder
 * geloescht. Der Rueckbau entfernt die Zeile nur, solange keine
 * Unterhaltung auf sie zeigt.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::table('channels')->where('key', 'webchat')->exists()) {
            return;
        }

        DB::table('channels')->insert([
            'key' => 'webchat',
            'name' => 'Website-Chat',
            'driver' => 'webchat',
            'is_active' => true,
            'sort' => 25,
            'capabilities' => json_encode([
                'supportsMedia' => false,
                'supportsReadReceipts' => false,
                'supportsDeliveryReceipts' => false,
                'supportsCustomers' => false,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $id = DB::table('channels')->where('key', 'webchat')->value('id');
        if ($id === null) {
            return;
        }
        $benutzt = DB::table('conversations')->where('channel_id', $id)->exists()
            || DB::table('customer_messages')->where('channel_id', $id)->exists();
        if (! $benutzt) {
            DB::table('channels')->where('id', $id)->delete();
        }
    }
};
