<?php
namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Wie der Standard-Cast "encrypted", aber robust gegen Alt-Bestaende:
 *
 * Vor der Verschluesselung wurden einige Felder (IBAN, Steuer-ID, KV-/RV-
 * Nummer) im Klartext gespeichert. Der Standard-Cast wirft beim Lesen solcher
 * Datensaetze eine DecryptException -> die komplette Kundenakte bzw. das
 * Speichern schlug mit HTTP 500 fehl ("Fehler beim Hinzufuegen").
 *
 * Dieser Cast liefert bei nicht entschluesselbaren Werten den Rohwert zurueck
 * (statt zu crashen) und protokolliert das einmalig. Geschrieben wird immer
 * verschluesselt, sodass sich der Bestand mit jeder Speicherung selbst heilt.
 */
class SafeEncrypted implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            // Alt-Klartext oder mit anderem Key verschluesselt: Rohwert zeigen,
            // damit die Seite funktioniert. Beim naechsten Speichern wird der
            // Wert korrekt verschluesselt abgelegt.
            Log::warning("SafeEncrypted: {$key} nicht entschluesselbar (Alt-Bestand), Rohwert genutzt.");
            return $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return [$key => null];
        }
        return [$key => Crypt::encryptString((string) $value)];
    }
}
