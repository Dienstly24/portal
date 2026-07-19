<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer die Kfz-Unterlagen der ADAC Autoversicherung AG
 * (Beitragsinformation / Rechnung zur Beitragsanpassung). Diese Schreiben sind
 * immer gleich aufgebaut und tragen die Kern-Vertragsdaten eines Kfz-Vertrags:
 * Versicherer, Vertrags-/Versicherungsscheinnummer, amtliches Kennzeichen,
 * Schadenfreiheitsklasse (Haftpflicht), Teilkasko-Baustein, Monatsbeitrag und
 * Gueltigkeitsbeginn.
 *
 * So wird der (zweite) Kfz-Vertrag eines Kunden gratis erkannt und als
 * KFZ-Vertrag zugeordnet - inklusive SF-Klasse, die der Betrieb bisher von
 * Hand nachtragen musste. Alle Werte durchlaufen die harte Feldvalidierung.
 */
class AdacAutoversicherungParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    private const INSURER = 'ADAC Autoversicherung AG';

    public function parse(string $text): ?array
    {
        // Weiche Trennzeichen (soft hyphen) und Aufzaehlungspunkte normalisieren,
        // damit umbrochene Woerter ("Haftpflichtversi\xADcherung") zusammenfinden.
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);

        $upper = mb_strtoupper($text);
        // Nur die ADAC-Schreiben selbst - NICHT das CHECK24-Beratungsprotokoll,
        // das die ADAC nur als moeglichen Versicherer/Zweitwagen erwaehnt.
        if (str_contains($upper, 'CHECK24') || str_contains($upper, 'BERATUNGSPROTOKOLL')) {
            return null;
        }
        if (!str_contains($upper, 'ADAC AUTOVERSICHERUNG')
            || (!str_contains($upper, 'KFZ-VERSICHERUNG') && !str_contains($upper, 'SCHADENFREIHEITSKLASSE'))) {
            return null;
        }

        $lines = array_map('rtrim', preg_split('/\R/', $text) ?: []);

        $person = $this->parsePerson($lines);
        $vehicle = $this->parseVehicle($text);
        $insurance = $this->parseInsurance($text);

        // Ohne belastbaren Kern (Vertragsnummer oder Kennzeichen) lieber der
        // normalen Analyse ueberlassen.
        if (($insurance['contract_number'] ?? null) === null && ($vehicle['license_plate'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $plate = $vehicle['license_plate'] ?? null;
        return [
            'type' => 'kfz_vertrag',
            'confidence' => 75,
            'summary' => 'ADAC-Autoversicherung (Kfz-Vertrag)'
                . ($name !== '' ? ' - ' . $name : '')
                . ($plate !== null ? ' - ' . $plate : '')
                . (isset($vehicle['sf_liability_class']) ? ' - SF ' . $vehicle['sf_liability_class'] . ' (Haftpflicht)' : '')
                . ' - Felder gratis aus dem Schreiben gelesen (ohne KI).',
            'title' => 'ADAC Kfz-Versicherung' . ($name !== '' ? ' ' . $name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $insurance,
                'kfz' => $vehicle,
                'gesundheit' => [],
                'bank' => [],
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /**
     * Anschriftenblock oben im Brief: "Herrn/Frau" -> Name -> Strasse -> PLZ Ort.
     * @param list<string> $lines @return array<string,mixed>
     */
    private function parsePerson(array $lines): array
    {
        $raw = [];
        foreach ($lines as $i => $line) {
            if (!preg_match('/^\s*(Herrn|Herr|Frau)\s*$/u', trim($line))) {
                continue;
            }
            // Folgezeilen (nicht-leer) einsammeln: Name, Strasse, PLZ Ort.
            $block = [];
            for ($j = $i + 1; $j < count($lines) && count($block) < 3; $j++) {
                $val = trim($lines[$j]);
                if ($val !== '') {
                    $block[] = $val;
                }
            }
            if (count($block) >= 3
                && preg_match('/^[A-ZÄÖÜ][\p{L}\-]+(?:\s+[A-ZÄÖÜ][\p{L}\-]+)+$/u', $block[0])
                && preg_match('/^(\d{5})\s+(.+)$/', $block[2], $z)) {
                $nameParts = preg_split('/\s+/', $block[0]) ?: [];
                $raw['last_name'] = array_pop($nameParts);
                $raw['first_name'] = implode(' ', $nameParts) ?: null;
                if (preg_match('/^(.*\D)\s*(\d+(?:\s*[a-zA-Z])?)\s*$/u', $block[1], $s)) {
                    $raw['street'] = trim($s[1]);
                    $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
                } else {
                    $raw['street'] = $block[1];
                }
                $raw['zip'] = $z[1];
                $raw['city'] = trim($z[2]);
                break;
            }
        }
        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseVehicle(string $text): array
    {
        $raw = [];
        // Amtliches Kennzeichen (z.B. "RE-CK 240").
        if (preg_match('/Kennzeichen:?\s*([A-ZÄÖÜ]{1,3}-[A-Z]{1,2}\s?\d{1,4}[A-Z]?)/u', $text, $m)) {
            $raw['license_plate'] = trim($m[1]);
        }
        // SF-Klasse Haftpflicht: im Beitragsvergleich stehen "bisher"/"neu"
        // ("SF 1 ... SF 2") - die letzte (neue) Klasse gilt.
        if (preg_match('/Kfz-Haftpflicht\w*.*?SF\s*\d+(?:\D+SF\s*(\d+))?/us', $text, $m)) {
            $raw['sf_liability_class'] = $m[1] ?? null;
            if (($raw['sf_liability_class'] ?? null) === null
                && preg_match('/Kfz-Haftpflicht\w*.*?SF\s*(\d+)/us', $text, $m2)) {
                $raw['sf_liability_class'] = $m2[1];
            }
        }
        // Teilkasko-Baustein vorhanden?
        if (preg_match('/\bTeilkasko\b/u', $text)) {
            $raw['has_teilkasko'] = true;
        }
        return $this->validatedVehicle(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseInsurance(string $text): array
    {
        $raw = [
            'sparte' => 'kfz',
            'insurer' => self::INSURER,
        ];
        // Vertrags-/Versicherungsnummer (z.B. "AD-9518689572").
        if (preg_match('/\b(AD-?\d{8,})\b/u', $text, $m)) {
            $raw['contract_number'] = strtoupper($m[1]);
        }
        // Beginn/Gueltigkeit ("ab dem 01.06.2026").
        if (preg_match('/ab dem\s+(\d{2})\.(\d{2})\.(\d{4})/u', $text, $m)) {
            $raw['start_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        // Gesamtbeitrag (Monatsbeitrag). Der Betrag steht NACH dem Klammer-
        // zusatz "(inkl. ... Versicherungsteuer ... 12,05 EUR)" - deshalb erst
        // hinter der schliessenden Klammer greifen, nicht den Steuerbetrag.
        if (preg_match('/Gesamtbeitrag[^)]*\)\s*\R?\s*(\d{1,3}(?:\.\d{3})*,\d{2})/u', $text, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = (stripos($text, 'Monatsbeitrag') !== false || stripos($text, 'monatlich') !== false)
                ? 'monthly' : null;
        }
        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }
}
