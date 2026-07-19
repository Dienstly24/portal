<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Parser fuer einen kompakten KONTAKTDATEN-Block, wie ihn Makler oft als
 * Foto/Screenshot (z.B. aus einer Chat-Nachricht) hochladen:
 *
 *   Hamzeh Jassem 01.01.2005
 *   Unterwerkstr. 39
 *   84032 Altdorf
 *   017680557743
 *   hamzehjassem9@gmail.com
 *   DE53 7425 0000 0041 2922 10
 *
 * Anders als bei laufendem Freitext (wo bewusst KEINE Namen/Adressen gelesen
 * werden) ist das hier ein klar strukturierter, kurzer Block: Name (+ evtl.
 * Geburtsdatum), Anschrift, Telefon, E-Mail und IBAN stehen jeweils in einer
 * eigenen Zeile. Der Parser greift nur, wenn mehrere starke, eindeutige
 * Signale zusammenkommen (E-Mail UND IBAN UND eine "PLZ Ort"-Zeile in einem
 * KURZEN Text) - so werden echte Dokumente (Rechnungen, Briefe mit Bankzeile
 * im Fuss) nicht faelschlich als Kontaktblock gelesen. Die endgueltige Anlage
 * bestaetigt ohnehin ein Mitarbeiter im Review.
 */
class KontaktdatenBlockParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /** Laenger als das ist es kein kompakter Kontaktblock mehr. */
    private const MAX_CHARS = 600;
    private const MAX_LINES = 14;

    public function parse(string $text): ?array
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $text) ?: []),
            fn ($l) => $l !== ''
        ));

        // Nur kurze, kompakte Bloecke.
        if ($lines === [] || count($lines) > self::MAX_LINES || mb_strlen(implode("\n", $lines)) > self::MAX_CHARS) {
            return null;
        }

        $joined = implode("\n", $lines);
        $email = $this->firstEmail($joined);
        $iban = $this->firstIban($joined);
        $zipCity = $this->firstZipCity($lines);

        // Starke Signale muessen zusammenkommen (deliberater Kontaktblock).
        if ($email === null || $iban === null || $zipCity === null) {
            return null;
        }

        $person = $this->parsePerson($lines, $email, $zipCity);
        if (($person['first_name'] ?? null) === null && ($person['last_name'] ?? null) === null) {
            return null; // ohne Namen der normalen Analyse ueberlassen
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        return [
            'type' => 'kontaktdaten',
            'confidence' => 70,
            'summary' => 'Kontaktdaten' . ($name !== '' ? ' - ' . $name : '')
                . ' (Name, Anschrift, Telefon, E-Mail, IBAN gratis gelesen).',
            'title' => 'Kontaktdaten' . ($name !== '' ? ' ' . $name : ''),
            'data' => [
                'person' => $person,
                'bank' => $this->validatedBank(['iban' => $iban]),
                'versicherung' => [],
                'gesundheit' => [],
                'kfz' => [],
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /**
     * @param list<string> $lines
     * @param array{0:int,1:string,2:string} $zipCity [Zeilenindex, PLZ, Ort]
     * @return array<string,mixed>
     */
    private function parsePerson(array $lines, string $email, array $zipCity): array
    {
        $raw = ['email' => $email, 'zip' => $zipCity[1], 'city' => $zipCity[2]];

        // Name (+ evtl. Geburtsdatum) - die erste Zeile, die wie ein Name
        // aussieht (2-4 Grosswoerter, keine Ziffern ausser optional das
        // Geburtsdatum am Ende), vor der PLZ-Zeile.
        $nameIdx = -1;
        for ($i = 0; $i < $zipCity[0]; $i++) {
            if (preg_match('/^([A-ZÄÖÜ][\p{L}\-]+(?:\s+[A-ZÄÖÜ][\p{L}\-]+){1,3})(?:\s+(\d{2}\.\d{2}\.\d{4}))?$/u', $lines[$i], $m)) {
                $parts = preg_split('/\s+/', trim($m[1])) ?: [];
                $raw['first_name'] = array_shift($parts);
                $raw['last_name'] = implode(' ', $parts) ?: null;
                if (!empty($m[2]) && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $m[2], $d)) {
                    $raw['birth_date'] = $d[3] . '-' . $d[2] . '-' . $d[1];
                }
                $nameIdx = $i;
                break;
            }
        }

        // Strasse + Hausnummer: eine Zeile ZWISCHEN Name und PLZ-Zeile, die auf
        // eine Hausnummer endet ("Unterwerkstr. 39") - so wird nicht die
        // Namenszeile (mit Geburtsdatum) faelschlich als Adresse gelesen.
        for ($i = $nameIdx + 1; $i < $zipCity[0]; $i++) {
            $line = $lines[$i];
            if (str_contains($line, '@')) {
                continue;
            }
            if (preg_match('/^([A-ZÄÖÜ].*\D)\s*(\d+(?:\s*[a-zA-Z])?)$/u', $line, $m) && preg_match('/\p{L}{3,}/u', $m[1])) {
                $raw['street'] = trim($m[1]);
                $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $m[2]));
                break;
            }
        }

        // Telefon/Handy: eine Zeile, die (bis auf Trennzeichen) nur aus einer
        // 0-Nummer besteht. Mobil vs. Festnetz routet die Uebernahme spaeter.
        foreach ($lines as $line) {
            $digits = preg_replace('/[\s\/()+-]/', '', $line);
            if (preg_match('/^0\d{9,14}$/', (string) $digits)) {
                $raw['phone'] = $digits;
                break;
            }
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    private function firstEmail(string $text): ?string
    {
        return preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text, $m)
            ? strtolower($m[0]) : null;
    }

    /** Deutsche IBAN (mit oder ohne Leerzeichen). */
    private function firstIban(string $text): ?string
    {
        if (preg_match('/\bDE\d{2}(?:\s?\d{4}){4}\s?\d{2}\b/', $text, $m)) {
            return strtoupper((string) preg_replace('/\s+/', '', $m[0]));
        }
        return null;
    }

    /**
     * Erste "PLZ Ort"-Zeile.
     * @param list<string> $lines
     * @return array{0:int,1:string,2:string}|null
     */
    private function firstZipCity(array $lines): ?array
    {
        foreach ($lines as $i => $line) {
            if (preg_match('/^(\d{5})\s+([A-ZÄÖÜ][\p{L}\-. ]+)$/u', $line, $m)) {
                return [$i, $m[1], trim($m[2])];
            }
        }
        return null;
    }
}
