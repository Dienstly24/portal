<?php

namespace App\Services\Lexoffice;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Wandelt einen rohen Lexoffice-/Lexware-Kontakt (API-Array) in das
 * normalisierte Datenformat für den CustomerAutoCreationService um.
 *
 * Behebt gezielt die Schwächen des alten Imports:
 * - liest ALLE E-Mail-Kübel (business, office, private, other)
 * - zerlegt die Anschrift in strukturierte Felder (Straße/Hausnr./PLZ/Ort)
 * - erkennt Geburtsdatum UND IBAN aus dem Notizfeld
 * - übernimmt die Lexoffice-Nummer als externe Referenz (nicht als interne
 *   Kundennummer) und setzt customer_type korrekt
 * - schreibt KEINE Tags in marital_status (Fehler des Altimports)
 */
class LexofficeContactMapper
{
    /**
     * @param  array<string,mixed>  $c  Roher Lexoffice-Kontakt
     * @return array<string,mixed>|null  Normalisierte Daten oder null (unbrauchbar)
     */
    public function map(array $c): ?array
    {
        $isCompany = !empty($c['company']['name']);
        $firstName = $c['person']['firstName'] ?? '';
        $lastName = $c['person']['lastName'] ?? '';
        $name = $isCompany
            ? $c['company']['name']
            : trim($firstName . ' ' . $lastName);

        if (trim((string) $name) === '') {
            return null; // ohne Namen kein sinnvoller Datensatz
        }

        $note = (string) ($c['note'] ?? '');

        $data = [
            'full_name'     => $name,
            'first_name'    => $firstName ?: null,
            'last_name'     => $lastName ?: null,
            'email'         => $this->firstEmail($c),
            'phone'         => $this->firstPhone($c),
            'customer_type' => $isCompany ? 'firma' : 'privat',
            'company_name'  => $isCompany ? $name : null,
            'birth_date'    => $this->birthDateFromNote($note),
            'iban'          => $this->ibanFromNote($note),
        ];

        // Anschrift strukturiert übernehmen.
        $addr = $c['addresses']['billing'][0] ?? $c['addresses']['shipping'][0] ?? null;
        if ($addr) {
            [$street, $houseNumber] = $this->splitStreet((string) ($addr['street'] ?? ''));
            $data['street'] = $street ?: null;
            $data['house_number'] = $houseNumber ?: null;
            $data['zip'] = trim((string) ($addr['zip'] ?? '')) ?: null;
            $data['city'] = trim((string) ($addr['city'] ?? '')) ?: null;
        }

        // Externe Referenzen: Lexoffice-Kundennummer + Kontakt-ID.
        $refs = [];
        $lexNumber = $c['roles']['customer']['number'] ?? null;
        if ($lexNumber) {
            $refs[] = ['type' => 'lexoffice_number', 'value' => $lexNumber, 'source' => 'lexoffice'];
            // Quellnummer bleibt Teil der internen Nummer: "25" + Original.
            $data['import_number'] = (string) $lexNumber;
        }
        if (!empty($c['id'])) {
            $refs[] = ['type' => 'lexoffice_id', 'value' => $c['id'], 'source' => 'lexoffice'];
        }
        $data['external_references'] = $refs;

        return $data;
    }

    /** Erste vorhandene E-Mail aus allen Kübeln (inkl. 'office', das der Altimport übersah). */
    private function firstEmail(array $c): ?string
    {
        foreach (['business', 'office', 'private', 'other'] as $bucket) {
            $val = $c['emailAddresses'][$bucket][0] ?? null;
            if ($val) {
                return strtolower(trim($val));
            }
        }
        return null;
    }

    private function firstPhone(array $c): ?string
    {
        foreach (['business', 'office', 'mobile', 'private', 'other'] as $bucket) {
            $val = $c['phoneNumbers'][$bucket][0] ?? null;
            if ($val) {
                return trim($val);
            }
        }
        return null;
    }

    /** Geburtsdatum aus dem Notizfeld (TT.MM.JJJJ) -> Y-m-d. */
    private function birthDateFromNote(string $note): ?string
    {
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $note, $m)) {
            try {
                return Carbon::createFromFormat('d.m.Y', "{$m[1]}.{$m[2]}.{$m[3]}")->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    /** Deutsche IBAN aus dem Notizfeld (mit oder ohne Leerzeichen). */
    private function ibanFromNote(string $note): ?string
    {
        if (preg_match('/DE(?:\s?\d){20}/i', $note, $m)) {
            return strtoupper(preg_replace('/\s+/', '', $m[0]));
        }
        return null;
    }

    /**
     * Zerlegt "Keplerstr. 32a" in ["Keplerstr.", "32a"]. Ohne erkennbare
     * Hausnummer bleibt alles Straße.
     *
     * @return array{0:string,1:string}
     */
    private function splitStreet(string $street): array
    {
        $street = trim($street);
        if ($street === '') {
            return ['', ''];
        }
        if (preg_match('/^(.*?)\s+(\d+\s*[a-zA-Z]?)$/', $street, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        return [$street, ''];
    }
}
