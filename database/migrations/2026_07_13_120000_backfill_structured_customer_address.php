<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill der strukturierten Adressfelder (address_street, _house_number,
 * _zip, _city) aus dem alten Freitext-Feld `address`. Zweck: Adressen, die
 * ueber das Adminportal in `address` gespeichert wurden, erscheinen sonst im
 * Kundenportal (das die strukturierten Felder liest) leer. Es werden nur
 * Datensaetze angefasst, deren strukturierte Felder noch komplett leer sind;
 * bereits gepflegte Adressen bleiben unangetastet.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('customers')
            ->whereNotNull('address')
            ->where('address', '!=', '')
            ->where(function ($q) {
                $q->whereNull('address_street')->orWhere('address_street', '');
            })
            ->where(function ($q) {
                $q->whereNull('address_city')->orWhere('address_city', '');
            })
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $parts = $this->splitAddress($row->address);
                    if ($parts['street'] === '' && $parts['zip'] === '' && $parts['city'] === '') {
                        continue;
                    }
                    DB::table('customers')->where('id', $row->id)->update([
                        'address_street'       => $parts['street'] ?: null,
                        'address_house_number' => $parts['house_number'] ?: null,
                        'address_zip'          => $parts['zip'] ?: null,
                        'address_city'         => $parts['city'] ?: null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Kein Rueckbau: Freitext `address` bleibt erhalten, die strukturierten
        // Felder sind additiv und werden nicht geleert.
    }

    /**
     * Zerlegt "Strasse Hausnr, PLZ Ort, Land" in strukturierte Teile.
     * Gleiche Logik wie AdminController::splitAddress.
     */
    private function splitAddress(?string $address): array
    {
        $parts = ['street' => '', 'house_number' => '', 'zip' => '', 'city' => ''];
        if (!$address) {
            return $parts;
        }

        $segments = array_map('trim', explode(',', $address));

        if (isset($segments[0])) {
            if (preg_match('/^(.*?)\s+(\d+\s*[a-zA-Z]?[\-\/]?\d*[a-zA-Z]?)$/u', $segments[0], $m)) {
                $parts['street'] = trim($m[1]);
                $parts['house_number'] = trim($m[2]);
            } else {
                $parts['street'] = $segments[0];
            }
        }

        if (isset($segments[1])) {
            if (preg_match('/^(\d{4,5})\s+(.+)$/u', $segments[1], $m)) {
                $parts['zip'] = $m[1];
                $parts['city'] = trim($m[2]);
            } else {
                $parts['city'] = $segments[1];
            }
        }

        return $parts;
    }
};
