<?php

namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer den Arbeitsvertrag (auch Anstellungs-/Dienstvertrag).
 * Deutsche Arbeitsvertraege beginnen praktisch immer mit demselben Kopf:
 *
 *   Arbeitsvertrag
 *   Zwischen
 *     <Firma>  [vertreten durch ...]
 *     <Strasse Nr, PLZ Ort>
 *     (im Folgenden: Arbeitgeber)
 *   und
 *     Herrn/Frau <Name>
 *     <Strasse Nr, PLZ Ort>
 *     (im Folgenden: Arbeitnehmer)
 *
 * Gelesen werden: der ARBEITGEBER (Firmenname + Anschrift - das braucht der
 * Betrieb fuer die Kundenakte, z.B. fuer Kranken-Beitritte), der
 * ARBEITNEHMER (Name, Anschrift, Anrede -> Geschlecht) sowie Taetigkeit
 * ("als Bauhelfer eingestellt") und Beginn ("mit Wirkung vom ..."). Der
 * Arbeitnehmer ist die Hauptperson (Kundenzuordnung ueber Name + Adresse).
 *
 * Alle Werte durchlaufen die harte Feldvalidierung; unsichere Felder bleiben
 * leer statt falsch. Findet der Parser weder Arbeitgeber noch Arbeitnehmer,
 * liefert er null und die Analyse laeuft normal weiter (Heuristik/KI).
 */
class ArbeitsvertragParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /** Rechtsformen, an denen der Firmenname erkannt wird. */
    private const LEGAL_FORM = '/\b(GmbH\s*&\s*Co\.?\s*KG|GmbH|gGmbH|mbH|AG|SE|UG|KG|OHG|GbR|e\.\s?K\.|e\.\s?V\.|Einzelunternehmen)(?=[\s,.);]|$)/u';

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $upper = mb_strtoupper($text);

        if (! str_contains($upper, 'ARBEITSVERTRAG') && ! str_contains($upper, 'ANSTELLUNGSVERTRAG')
            && ! str_contains($upper, 'DIENSTVERTRAG')) {
            return null;
        }
        // Ohne die typischen Partei-Marker ist es kein Vertragskopf (z.B. nur
        // eine Erwaehnung "Arbeitsvertrag" in einem anderen Dokument).
        if (! str_contains($upper, 'ARBEITNEHMER') && ! str_contains($upper, 'ARBEITGEBER')) {
            return null;
        }

        $this->lines = array_map('trim', preg_split('/\R/', $text) ?: []);

        $employer = $this->party('/(?:im\s+Folgenden|nachfolgend):?\s*["\'\x{201e}\x{201c}]?\s*(?:der\s+|die\s+)?Arbeitgeber/iu');
        $employee = $this->party('/(?:im\s+Folgenden|nachfolgend):?\s*["\'\x{201e}\x{201c}]?\s*(?:der\s+|die\s+)?Arbeitnehmer/iu');

        $raw = [];

        // Arbeitnehmer = Hauptperson ("Herrn Al Ali Mohammad" + Anschrift).
        foreach ($employee as $line) {
            if (! isset($raw['last_name']) && preg_match('/^(Herrn?|Frau)\s+(\p{Lu}[\p{L}\-\'\x{2019} ]{2,60})$/u', $line, $m)) {
                $raw['gender'] = mb_strtolower($m[1]) === 'frau' ? 'female' : 'male';
                $parts = preg_split('/\s+/', trim($m[2])) ?: [];
                if (count($parts) >= 2) {
                    $raw['last_name'] = array_pop($parts);
                    $raw['first_name'] = implode(' ', $parts);
                } else {
                    $raw['last_name'] = trim($m[2]);
                }
            } elseif (($addr = $this->addressParts($line)) !== null) {
                // Nur fehlende Teile ergaenzen (eine zweite Adresszeile darf
                // z.B. die schon gelesene Strasse nicht ueberschreiben).
                foreach ($addr as $k => $v) {
                    if ($v !== null && $v !== '' && ! isset($raw[$k])) {
                        $raw[$k] = $v;
                    }
                }
            }
        }

        // Arbeitgeber: Firmenname (Zeile mit Rechtsform, sonst die erste
        // Blockzeile) + Anschrift. "vertreten durch <Person>" ist NICHT der
        // Firmenname und nicht die Anschrift.
        $employerName = null;
        $employerAddress = null;
        $skipNext = false;
        foreach ($employer as $line) {
            if ($skipNext) { // Zeile nach "vertreten durch" = Vertreter-Name
                $skipNext = false;
                continue;
            }
            if (preg_match('/^vertreten\s+durch\b\s*(.*)$/iu', $line, $m)) {
                $skipNext = trim($m[1]) === '';
                continue;
            }
            if ($employerName === null && preg_match(self::LEGAL_FORM, $line)
                && ! preg_match('/^\(?im\s+Folgenden/iu', $line)) {
                $employerName = trim($line, " \t,;");
            }
            if ($employerAddress === null && ($addr = $this->addressParts($line)) !== null) {
                $employerAddress = trim(
                    $addr['street'].' '.($addr['house_number'] ?? '')
                ).', '.trim(($addr['zip'] ?? '').' '.($addr['city'] ?? ''));
                $employerAddress = trim($employerAddress, ', ');
            }
        }
        // Fallback ohne Rechtsform: erste Blockzeile, die keine Anschrift und
        // kein Marker ist (konservativ: nur wenn sie wie ein Name aussieht).
        if ($employerName === null) {
            foreach ($employer as $line) {
                if ($this->addressParts($line) === null
                    && ! preg_match('/^\(?im\s+Folgenden|^vertreten\s+durch|^zwischen$|^und$/iu', $line)
                    && preg_match('/^\p{Lu}[\p{L}0-9 .,&\-]{2,80}$/u', $line)) {
                    $employerName = trim($line, " \t,;");
                    break;
                }
            }
        }
        if ($employerName !== null) {
            $raw['employer_name'] = $employerName;
        }
        if ($employerAddress !== null && $employerAddress !== '') {
            $raw['employer_address'] = $employerAddress;
        }

        // Taetigkeit ("als Bauhelfer eingestellt/beschaeftigt") + Beginn.
        if (preg_match('/\bals\s+([\p{L}\/\- ]{2,50}?)\s+(?:eingestellt|besch(?:ae|ä)ftigt|angestellt|t(?:ae|ä)tig)\b/iu', $text, $m)) {
            $raw['occupation'] = trim($m[1]);
        }
        $start = $this->startDate($text);

        $person = $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));

        // Ohne Arbeitnehmer-Namen UND ohne Arbeitgeber lieber der KI ueberlassen.
        if (($person['last_name'] ?? null) === null && ($person['employer_name'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        return [
            'type' => 'arbeitsvertrag',
            'confidence' => 66,
            'summary' => 'Arbeitsvertrag'
                .($name !== '' ? ' - '.$name : '')
                .(isset($person['occupation']) ? ' - als '.$person['occupation'] : '')
                .(isset($person['employer_name'])
                    ? ' - Arbeitgeber '.$person['employer_name']
                        .(isset($person['employer_address']) ? ' ('.$person['employer_address'].')' : '')
                    : '')
                .($start !== null ? ' - Beginn '.$this->displayDate($start) : '')
                .' - Felder gratis aus dem Vertrag gelesen (ohne KI).',
            'title' => 'Arbeitsvertrag'.($name !== '' ? ' '.$name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => [],
                'kfz' => [],
                'gesundheit' => [],
                'bank' => [],
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /**
     * Der Textblock einer Vertragspartei: die (bis zu 6) nicht-leeren Zeilen
     * OBERHALB des Markers "(im Folgenden: Arbeitgeber/Arbeitnehmer)", von
     * oben nach unten. Der Block endet nach oben an "Zwischen"/"und".
     *
     * @return list<string>
     */
    private function party(string $markerPattern): array
    {
        $markerIdx = null;
        foreach ($this->lines as $i => $line) {
            if (preg_match($markerPattern, $line)) {
                $markerIdx = $i;
                break;
            }
        }
        if ($markerIdx === null) {
            return [];
        }

        $block = [];
        for ($j = $markerIdx - 1; $j >= 0 && count($block) < 6; $j--) {
            $line = trim($this->lines[$j]);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(zwischen|und)$/iu', $line)) {
                break;
            }
            array_unshift($block, $line);
        }
        return $block;
    }

    /**
     * Anschrift-Zeile "Beethovenstraße 31, 66126 Saarbrücken" (oder nur
     * "Strasse Nr" / nur "PLZ Ort") -> strukturierte Teile, sonst null.
     *
     * @return array{street:string,house_number:?string,zip:?string,city:?string}|null
     */
    private function addressParts(string $line): ?array
    {
        $line = trim($line, " \t,;");
        if (preg_match('/^(\p{Lu}[\p{L}.\- ]{2,60}?)\s+(\d{1,4}(?:\s?[a-zA-Z])?)\s*,\s*(\d{5})\s+(\p{Lu}[\p{L}.\- ]{2,40})$/u', $line, $m)) {
            return [
                'street' => trim($m[1]),
                'house_number' => trim($m[2]),
                'zip' => $m[3],
                'city' => trim($m[4]),
            ];
        }
        if (preg_match('/^(\p{Lu}[\p{L}.\- ]{2,60}?(?:stra(?:ss|ß)e|weg|allee|platz|gasse|ring|damm|ufer|str\.))\s+(\d{1,4}(?:\s?[a-zA-Z])?)$/iu', $line, $m)) {
            return ['street' => trim($m[1]), 'house_number' => trim($m[2]), 'zip' => null, 'city' => null];
        }
        if (preg_match('/^(\d{5})\s+(\p{Lu}[\p{L}.\- ]{2,40})$/u', $line, $m)) {
            return ['street' => '', 'house_number' => null, 'zip' => $m[1], 'city' => trim($m[2])];
        }
        return null;
    }

    /** Beginn des Arbeitsverhaeltnisses ("mit Wirkung vom 06.07.2026"). */
    private function startDate(string $text): ?string
    {
        if (preg_match('/(?:mit\s+Wirkung\s+vom|Arbeitsverh(?:ae|ä)ltnis\s+beginnt\s+am|wird\s+zum)\s+(\d{1,2})\.(\d{1,2})\.(\d{4})/iu', $text, $m)) {
            $date = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? $date : null;
        }
        return null;
    }

    private function displayDate(string $iso): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) ? $m[3].'.'.$m[2].'.'.$m[1] : $iso;
    }
}
