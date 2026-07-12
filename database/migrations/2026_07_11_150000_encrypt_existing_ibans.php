<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Verschlüsselt bestehende Klartext-IBANs, damit der neue 'encrypted'-Cast
 * auf Customer::iban / iban2 sie lesen kann. Bankdaten liegen dadurch nicht
 * mehr im Klartext in der DB (DSGVO). Bereits verschlüsselte Werte werden
 * übersprungen (idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['iban', 'iban2'] as $column) {
            DB::table('customers')
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->select('id', $column)
                ->chunkById(200, function ($rows) use ($column) {
                    foreach ($rows as $row) {
                        // Schon verschlüsselt? Dann nichts tun.
                        try {
                            Crypt::decryptString($row->$column);
                            continue;
                        } catch (DecryptException $e) {
                            // Klartext -> jetzt verschlüsseln
                        }
                        DB::table('customers')->where('id', $row->id)->update([
                            $column => Crypt::encryptString($row->$column),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Rollback: wieder als Klartext ablegen.
        foreach (['iban', 'iban2'] as $column) {
            DB::table('customers')
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->select('id', $column)
                ->chunkById(200, function ($rows) use ($column) {
                    foreach ($rows as $row) {
                        try {
                            $plain = Crypt::decryptString($row->$column);
                            DB::table('customers')->where('id', $row->id)->update([$column => $plain]);
                        } catch (DecryptException $e) {
                            // war bereits Klartext
                        }
                    }
                });
        }
    }
};
