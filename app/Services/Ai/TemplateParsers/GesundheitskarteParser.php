<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Parser fuer die Rueckseite der Gesundheitskarte (Europaeische Kranken-
 * versicherungskarte / EHIC). Standardisiertes EU-Layout mit nummerierten
 * Feldern:
 *
 *   3. Name                     -> Nachname
 *   4. Vornamen                 -> Vorname(n)
 *   5. Geburtsdatum             -> TT/MM/JJJJ
 *   6. Persoenliche Kennnummer  -> Krankenversichertennummer (1 Buchstabe + 9 Ziffern)
 *
 * Bewusst NICHT uebernommen (Betreiber-Vorgabe): "7. Kennnummer des Traegers"
 * und "8. Kennnummer der Karte" - werden nicht gebraucht.
 */
class GesundheitskarteParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        if (mb_stripos($text, 'KRANKENVERSICHERUNGSKARTE') === false) {
            return null;
        }

        $this->lines = array_map('trim', preg_split('/\R/', $text) ?: []);

        $raw = [];
        // Nachname (Feld 3) und Vornamen (Feld 4): Wert steht UNTER dem Label.
        $last = $this->valueBelow('/(?:^|\b)3[.\s]*Name\b/i');
        if ($last !== null && preg_match('/^\p{Lu}[\p{L}\-\' ]+$/u', $last)) {
            $raw['last_name'] = $last;
        }
        $first = $this->valueBelow('/Vornamen/i');
        if ($first !== null && preg_match('/^\p{Lu}[\p{L}\-\' ]+$/u', $first)) {
            $raw['first_name'] = $first;
        }
        // Geburtsdatum (Feld 5): TT/MM/JJJJ oder TT.MM.JJJJ.
        $birth = $this->valueBelow('/Geburtsdatum/i');
        if ($birth !== null && preg_match('#(\d{2})[./](\d{2})[./](\d{4})#', $birth, $m)) {
            $raw['birth_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        $raw = $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));

        // Persoenliche Kennnummer (Feld 6) = Krankenversichertennummer:
        // 1 Buchstabe + 9 Ziffern (z.B. F883686827). Eindeutiges Format, daher
        // direkt per Regex - unabhaengig von der OCR-Zeilenlage.
        $health = [];
        if (preg_match('/\b([A-Z]\d{9})\b/', $this->text(), $m)) {
            $health['health_insurance_number'] = $m[1];
            $health['health_insurance_type'] = 'gesetzlich';
        }
        $health = $this->validatedHealth($health);

        // Ohne belastbaren Namen ODER Versichertennummer lieber der KI ueberlassen.
        if (($raw['last_name'] ?? null) === null && ($health['health_insurance_number'] ?? null) === null) {
            return null;
        }

        $name = trim(($raw['first_name'] ?? '') . ' ' . ($raw['last_name'] ?? ''));
        return [
            'type' => 'gesundheitskarte',
            'confidence' => 70,
            'summary' => 'Gesundheitskarte (Krankenversicherungskarte)'
                . ($name !== '' ? ' - ' . $name : '')
                . (isset($health['health_insurance_number']) ? ' - Vers.-Nr. ' . $health['health_insurance_number'] : '')
                . ' - Felder gratis gelesen (ohne KI).',
            'title' => 'Gesundheitskarte' . ($name !== '' ? ' ' . $name : ''),
            'data' => [
                'person' => $raw,
                'gesundheit' => $health,
                'versicherung' => [],
                'kfz' => [],
                'bank' => [],
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    private function text(): string
    {
        return implode("\n", $this->lines);
    }

    /** Naechste nicht-leere Zeile UNTER der ersten Zeile, die $pattern trifft. */
    private function valueBelow(string $pattern): ?string
    {
        foreach ($this->lines as $i => $line) {
            if (preg_match($pattern, $line)) {
                for ($j = $i + 1; $j < count($this->lines); $j++) {
                    $v = trim($this->lines[$j]);
                    if ($v !== '') {
                        return $v;
                    }
                }
                return null;
            }
        }
        return null;
    }
}
