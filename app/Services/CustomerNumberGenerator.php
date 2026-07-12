<?php
namespace App\Services;

use App\Models\Customer;

/**
 * Zentraler Kundennummern-Generator – EIN Codepfad für alle Anlagen
 * (Web-Formular, Self-Service, Import, Lexoffice, E-Mail-Pipeline).
 *
 * Schema (Geschäftsvorgabe):
 * - NEUE Kunden: Jahr (2-stellig) + fortlaufende 5-stellige Nummer,
 *   z. B. erste Anlage 2026 -> 2600001, dann 2600002, …
 * - IMPORTIERTE Kunden: Präfix "25" + Original-Nummer der Quellplattform
 *   bleibt erhalten, z. B. Lexoffice-Nr. 1234 -> 251234. So bleibt die
 *   Herkunftsnummer wiedererkennbar; zusätzlich speichert der jeweilige
 *   Importer die Originalnummer als ExternalReference.
 *
 * Alt-Nummern (C-XXXXXXXX) bleiben unverändert gültig – der Generator
 * erzeugt nur neue Nummern, er nummeriert nicht um.
 */
class CustomerNumberGenerator
{
    /** Jahrespräfix für importierte Bestandskunden (Geschäftsvorgabe). */
    public const IMPORT_PREFIX = '25';

    /** Fortlaufende Nummer für NEU registrierte Kunden: JJ + 5-stellig. */
    public function generate(): string
    {
        $prefix = now()->format('y'); // z. B. "26"

        // Höchste bereits vergebene Sequenz dieses Jahres bestimmen.
        // Nur exakt passende Nummern (JJ + 5 Ziffern) zählen – Alt-Nummern
        // (C-…) und Import-Nummern anderer Länge stören nicht.
        $max = Customer::where('customer_number', 'like', $prefix . '%')
            ->pluck('customer_number')
            ->filter(fn ($n) => preg_match('/^' . $prefix . '\d{5}$/', $n))
            ->map(fn ($n) => (int) substr($n, 2))
            ->max() ?? 0;

        do {
            $max++;
            $number = $prefix . str_pad((string) $max, 5, '0', STR_PAD_LEFT);
        } while (Customer::where('customer_number', $number)->exists());

        return $number;
    }

    /**
     * Nummer für einen IMPORTIERTEN Kunden: "25" + Original-Nummer der
     * Quellplattform (nur Ziffern/Buchstaben, führende Nullen bleiben).
     * Kollision oder unbrauchbare Originalnummer -> normale Neu-Nummer.
     */
    public function generateForImport(?string $originalNumber): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', (string) $originalNumber);

        if ($clean === '' || $clean === null) {
            return $this->generate();
        }

        $number = self::IMPORT_PREFIX . $clean;

        if (Customer::where('customer_number', $number)->exists()) {
            // Gleiche Quellnummer doppelt (sollte der Duplikatsschutz vorher
            // fangen) – eindeutig machen statt fehlschlagen.
            $suffix = 2;
            while (Customer::where('customer_number', $number . '-' . $suffix)->exists()) {
                $suffix++;
            }
            return $number . '-' . $suffix;
        }

        return $number;
    }
}
