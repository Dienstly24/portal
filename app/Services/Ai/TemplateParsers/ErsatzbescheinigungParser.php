<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Parser fuer die "Ersatzbescheinigung fuer die Gesundheitskarte" der
 * gesetzlichen Krankenkassen (z.B. novitas bkk). Der Brief traegt unten eine
 * kleine Tabelle mit den Kern-Daten des Versicherten:
 *
 *   Name, Vorname des Versicherten | Geburtsdatum
 *   Beginn der Mitgliedschaft      | Krankenversichertennummer
 *   Krankenkasse                   | Institutionskennzeichen
 *
 * Wichtig: die Krankenversichertennummer (1 Buchstabe + 9 Ziffern) steht hier
 * ausgeschrieben - die fehlt auf der Beitrittserklaerung ("siehe Gesundheits-
 * karte"). So fuellt dieses Dokument die Versichertennummer + Krankenkasse.
 */
class ErsatzbescheinigungParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $upper = mb_strtoupper($text);
        if (!str_contains($upper, 'ERSATZBESCHEINIGUNG') || !str_contains($upper, 'GESUNDHEITSKARTE')) {
            return null;
        }

        $this->lines = array_map('trim', preg_split('/\R/', $text) ?: []);

        $person = $this->parsePerson();
        $health = $this->parseHealth();
        $versicherung = $this->parseInsurance($health);

        if (($person['last_name'] ?? null) === null && ($health['health_insurance_number'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        return [
            'type' => 'gesundheitskarte',
            'confidence' => 72,
            'summary' => 'Ersatzbescheinigung fuer die Gesundheitskarte'
                . ($name !== '' ? ' - ' . $name : '')
                . (isset($health['health_insurance_company']) ? ' - ' . $health['health_insurance_company'] : '')
                . (isset($health['health_insurance_number']) ? ' (Vers.-Nr. ' . $health['health_insurance_number'] . ')' : '')
                . ' - gratis gelesen (ohne KI).',
            'title' => 'Ersatzbescheinigung Gesundheitskarte' . ($name !== '' ? ' ' . $name : ''),
            'data' => [
                'person' => $person,
                'gesundheit' => $health,
                'versicherung' => $versicherung,
                'kfz' => [],
                'bank' => [],
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function parsePerson(): array
    {
        $raw = [];

        // Tabelle: Wert steht UNTER "Name, Vorname des Versicherten"
        // -> "Nachname, Vorname [Geburtsdatum]".
        $nameLine = $this->valueBelow('Name, Vorname des Versicherten') ?? $this->valueBelow('Vorname des Versicherten');
        if ($nameLine !== null && preg_match('/^([A-ZÄÖÜ][\p{L}\-\']+),\s*([A-ZÄÖÜ][\p{L}\-\' ]+?)(?:\s+\d{2}[.\/]\d{2}[.\/]\d{4}.*)?$/u', $nameLine, $m)) {
            $raw['last_name'] = trim($m[1]);
            $raw['first_name'] = trim($m[2]);
        }
        // Geburtsdatum steht in derselben Wertzeile (rechte Spalte).
        if ($nameLine !== null && preg_match('/(\d{2})[.\/](\d{2})[.\/](\d{4})/', $nameLine, $m)) {
            $raw['birth_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        // Anschrift aus dem Empfaengerblock (nach "Herr"/"Frau").
        foreach ($this->lines as $i => $line) {
            if (!preg_match('/^\s*(Herr|Herrn|Frau)\b/u', $line)) {
                continue;
            }
            for ($j = $i; $j < min($i + 6, count($this->lines)); $j++) {
                $l = trim($this->lines[$j]);
                if (!isset($raw['street']) && preg_match('/^([A-ZÄÖÜ][\p{L}.\- ]*(?:str|weg|platz|allee|ring|gasse|damm)[\p{L}.]*)\s+(\d+[a-zA-Z]?(?:\/\d+)?)/iu', $l, $s)) {
                    $raw['street'] = trim($s[1]);
                    $raw['house_number'] = $s[2];
                }
                if (!isset($raw['zip']) && preg_match('/\b(\d{5})\s+([A-ZÄÖÜ][\p{L}\-]+)\b/u', $l, $z) && $z[1] !== '47050' && $z[1] !== '47051') {
                    $raw['zip'] = $z[1];
                    $raw['city'] = $z[2];
                }
            }
            break;
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseHealth(): array
    {
        $raw = ['health_insurance_type' => 'gesetzlich'];

        // Krankenversichertennummer: 1 Buchstabe + 9 Ziffern (z.B. S455872364).
        if (preg_match('/\b([A-Z]\d{9})\b/', $this->text(), $m)) {
            $raw['health_insurance_number'] = $m[1];
        }
        // Krankenkasse: Wert unter "Krankenkasse" bzw. neben dem Institutions-
        // kennzeichen; die 9-stellige IK am Ende wird abgeschnitten.
        $kkLine = $this->valueBelow('Institutionskennzeichen') ?? $this->valueBelow('Krankenkasse');
        if ($kkLine !== null) {
            $kk = trim((string) preg_replace('/\s+\d{6,}\s*$/', '', $kkLine));
            if (preg_match('/\p{L}{2,}/u', $kk) && mb_strlen($kk) <= 80 && stripos($kk, 'kennzeichen') === false) {
                $raw['health_insurance_company'] = $kk;
            }
        }

        return $this->validatedHealth(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @param array<string,mixed> $health @return array<string,mixed> */
    private function parseInsurance(array $health): array
    {
        $raw = ['sparte' => 'krankenversicherung'];
        if (isset($health['health_insurance_company'])) {
            $raw['insurer'] = $health['health_insurance_company'];
        }
        // Beginn der Mitgliedschaft = Versicherungsbeginn.
        $begin = $this->valueBelow('Beginn der Mitgliedschaft');
        if ($begin !== null && preg_match('/(\d{2})[.\/](\d{2})[.\/](\d{4})/', $begin, $m)) {
            $raw['start_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    private function text(): string
    {
        return implode("\n", $this->lines);
    }

    /** Naechste nicht-leere Zeile UNTER der ersten Zeile mit $needle. */
    private function valueBelow(string $needle): ?string
    {
        foreach ($this->lines as $i => $line) {
            if ($line !== '' && mb_stripos($line, $needle) !== false) {
                for ($j = $i + 1; $j < count($this->lines); $j++) {
                    if (trim($this->lines[$j]) !== '') {
                        return trim($this->lines[$j]);
                    }
                }
                return null;
            }
        }
        return null;
    }
}
