<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer die KKH-Beitrittserklaerung (gesetzliche
 * Krankenversicherung). Dieses Formular ist ueber alle Kunden hinweg identisch
 * aufgebaut - nur die Werte unterscheiden sich. Es wird als Bild-PDF ("Print
 * to PDF", ohne Textebene) hochgeladen und laesst sich nach OCR per fester
 * Regel lesen: kostenlos und zuverlaessig, statt jedes Formular teuer und
 * unzuverlaessig an die Bild-KI (Vision) zu schicken.
 *
 * Layout-Besonderheit: der ausgefuellte WERT steht jeweils in der Zeile UEBER
 * der Feldbeschriftung. Stark formatierte Felder (Datumsangaben, KV-/RV-Nummer)
 * werden zusaetzlich per Format erkannt - unabhaengig von der OCR-Zeilenlage.
 * Alle Werte durchlaufen dieselbe harte Feldvalidierung wie die KI-Antwort;
 * Unsicheres bleibt leer statt falsch. Kinder werden bewusst NICHT gelesen
 * (Betreiber-Regel: oft ungenau, wird manuell gepflegt).
 */
class KkhBeitrittserklaerungParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    private const INSURER = 'KKH Kaufmaennische Krankenkasse';

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $upper = mb_strtoupper($text);
        // Nur zustaendig fuer die KKH-Beitrittserklaerung.
        if (!str_contains($upper, 'BEITRITTSERKL') || !str_contains($upper, 'KKH')) {
            return null;
        }
        if (!str_contains($upper, 'KRANKENVERSICHERUNGSNUMMER') && !str_contains($upper, 'MITGLIEDSCHAFTSBEGINN')) {
            return null;
        }

        $this->lines = array_map('trim', preg_split('/\R/', $text) ?: []);

        $person = $this->parsePerson();
        $health = $this->parseHealth();
        $versicherung = $this->parseInsurance($health);

        // Ohne belastbare Kern-Felder lieber der normalen Analyse ueberlassen.
        if ($person === [] && ($health['health_insurance_number'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        return [
            'type' => 'beitrittserklaerung',
            'confidence' => 70,
            'summary' => 'KKH-Beitrittserklaerung (gesetzliche Krankenversicherung)'
                . ($name !== '' ? ' - ' . $name : '')
                . ' - Felder gratis aus dem Formular gelesen (ohne KI).'
                . (isset($health['previous_insurer']) ? ' Zuvor versichert bei ' . $health['previous_insurer'] . '.' : ''),
            'title' => 'Beitrittserklaerung KKH' . ($name !== '' ? ' ' . $name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $versicherung,
                'gesundheit' => $health,
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

        // Name: die WERT-Zeile steht ueber der Beschriftung "Nachname Vorname".
        // OCR haengt oft die rechte Spalte an ("... Verwandschaftsverhaeltnis
        // zum Arbeitgeber ja") -> nur die FUEHRENDEN Namens-Tokens nehmen und
        // beim ersten Formular-Wort abbrechen.
        $name = $this->nameAbove('Nachname Vorname');
        if ($name !== null) {
            $parts = preg_split('/\s+/', $name) ?: [];
            $raw['last_name'] = array_shift($parts);
            $raw['first_name'] = implode(' ', $parts) ?: null;
        }

        // Geburtsdatum / Geburtsort / Geschlecht stehen in EINER Wertzeile.
        $birthLine = $this->valueAbove('Geburtsdatum Geburtsort');
        if ($birthLine !== null) {
            if (preg_match('/(\d{2}\.\d{2}\.\d{4})/', $birthLine, $m)) {
                $raw['birth_date'] = $this->germanDate($m[1]);
            }
            $raw['gender'] = $this->gender($birthLine);
            // Geburtsort = zwischen Datum und Geschlecht.
            $place = preg_replace('/^.*?\d{2}\.\d{2}\.\d{4}\s*/u', '', $birthLine);
            $place = preg_split('/\b(M[äa]nnlich|Weiblich|Divers)\b/u', (string) $place)[0] ?? '';
            $place = trim(preg_replace('/[^\p{L}\s.\-]/u', '', $place));
            if (mb_strlen($place) >= 2) {
                $raw['birth_place'] = $place;
            }
        }

        // Familienstand + Staatsangehoerigkeit stehen in EINER Wertzeile.
        $famLine = $this->valueAbove('Familienstand Staatsangeh');
        if ($famLine !== null) {
            $tokens = preg_split('/\s+/', $famLine) ?: [];
            $raw['marital_status'] = $this->maritalStatus($tokens[0] ?? '');
            if (isset($tokens[1]) && preg_match('/^\p{L}{3,}$/u', $tokens[1])) {
                $raw['nationality'] = $tokens[1];
            }
        }

        // Adresse: Strasse + Hausnummer. Auf "Hausnummer" ankern (das Label),
        // nicht auf "Stra" - das traefe schon die Wertzeile "... Strasse ...".
        $streetLine = $this->valueAbove('Hausnummer');
        if ($streetLine !== null && preg_match('/^(.*?\p{L})\s+(\d+\s*[a-zA-Z]?)\s*$/u', $streetLine, $m)) {
            $raw['street'] = trim($m[1]);
            $raw['house_number'] = preg_replace('/\s+/', '', $m[2]);
        }
        // PLZ + Ort (erste "Postleitzahl Ort"-Zeile mit gueltiger PLZ). Ort nur
        // GROSS-geschriebene Woerter, damit OCR-Muell ("Rendsburg ri .")
        // abgeschnitten wird.
        $zipLine = $this->firstValueAboveMatching('Postleitzahl Ort', '/\b(\d{5})\s+([A-ZÄÖÜ][\p{L}\-]+(?:\s+[A-ZÄÖÜ][\p{L}\-]+)*)/u');
        if ($zipLine !== null) {
            $raw['zip'] = $zipLine[1];
            $raw['city'] = trim($zipLine[2]);
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseHealth(): array
    {
        $raw = [
            'health_insurance_company' => self::INSURER,
            'health_insurance_type' => 'gesetzlich',
        ];

        // Krankenversicherungsnummer: 1 Buchstabe + 9 Ziffern (z.B. A004167047).
        if (preg_match('/\b([A-Z]\d{9})\b/', $this->text(), $m)) {
            $raw['health_insurance_number'] = $m[1];
        }
        // Renten-/Sozialversicherungsnummer: 8 Ziffern + 1 Buchstabe + 3 Ziffern
        // (z.B. 26150104A016). Die 12-stellige RV-Nummer ist eindeutig genug,
        // um sie per Format aus dem Text zu ziehen.
        if (preg_match('/\b(\d{8}[A-Z]\d{3})\b/', $this->text(), $m)) {
            $raw['pension_number'] = $m[1];
        }
        // Vorversicherung (zuletzt versichert bei ...): Wert ueber dem EXAKTEN
        // Feldlabel "Vorversicherung" (nicht der Ueberschrift "Angaben zur ...").
        $prev = $this->valueAboveExact('Vorversicherung');
        if ($prev !== null && preg_match('/\p{L}{2,}/u', $prev) && mb_strlen($prev) <= 60
            && stripos($prev, 'versichert') === false) {
            $raw['previous_insurer'] = trim($prev);
        }

        return $this->validatedHealth(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @param array<string,mixed> $health @return array<string,mixed> */
    private function parseInsurance(array $health): array
    {
        $raw = [
            'sparte' => 'krankenversicherung',
            'insurer' => $health['health_insurance_company'] ?? self::INSURER,
        ];
        // Mitgliedschaftsbeginn = Versicherungsbeginn.
        $beginLine = $this->valueAbove('Mitgliedschaftsbeginn');
        if ($beginLine !== null && preg_match('/(\d{2}\.\d{2}\.\d{4})/', $beginLine, $m)) {
            $raw['start_date'] = $this->germanDate($m[1]);
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    private function text(): string
    {
        return implode("\n", $this->lines);
    }

    /** Index der ersten Zeile, die $needle enthaelt. */
    private function labelIndex(string $needle): ?int
    {
        foreach ($this->lines as $i => $line) {
            if ($line !== '' && mb_stripos($line, $needle) !== false) {
                return $i;
            }
        }
        return null;
    }

    /** Naechste nicht-leere Zeile UEBER der ersten Zeile mit $needle. */
    private function valueAbove(string $needle): ?string
    {
        $i = $this->labelIndex($needle);
        if ($i === null) {
            return null;
        }
        for ($j = $i - 1; $j >= 0; $j--) {
            if (trim($this->lines[$j]) !== '') {
                return trim($this->lines[$j]);
            }
        }
        return null;
    }

    /** Wie valueAbove, aber das Label muss EXAKT (getrimmt) gleich sein. */
    private function valueAboveExact(string $label): ?string
    {
        foreach ($this->lines as $i => $line) {
            if (mb_strtolower(trim($line)) === mb_strtolower($label)) {
                for ($j = $i - 1; $j >= 0; $j--) {
                    if (trim($this->lines[$j]) !== '') {
                        return trim($this->lines[$j]);
                    }
                }
                return null;
            }
        }
        return null;
    }

    /**
     * Sucht ueber ALLEN Vorkommen von $needle die erste Wertzeile darueber, die
     * $pattern erfuellt (z.B. eine gueltige "PLZ Ort"-Zeile).
     * @return array<int,string>|null Treffer-Gruppen von $pattern
     */
    private function firstValueAboveMatching(string $needle, string $pattern): ?array
    {
        foreach ($this->lines as $i => $line) {
            if ($line === '' || mb_stripos($line, $needle) === false) {
                continue;
            }
            for ($j = $i - 1; $j >= 0; $j--) {
                if (trim($this->lines[$j]) === '') {
                    continue;
                }
                if (preg_match($pattern, $this->lines[$j], $m)) {
                    return $m;
                }
                break; // nur die unmittelbar darueberliegende Wertzeile pruefen
            }
        }
        return null;
    }

    /**
     * Nimmt die FUEHRENDEN Namens-Tokens aus den bis zu drei Zeilen ueber
     * $needle: gross geschriebene Buchstaben-Woerter (2-16 Zeichen), abgebrochen
     * beim ersten Formular-Wort oder Nicht-Namens-Token. So bleibt der Name
     * sauber, auch wenn OCR die rechte Formularspalte an die Zeile anhaengt.
     */
    private function nameAbove(string $needle): ?string
    {
        $i = $this->labelIndex($needle);
        if ($i === null) {
            return null;
        }
        for ($j = $i - 1, $seen = 0; $j >= 0 && $seen < 3; $j--) {
            $line = trim($this->lines[$j]);
            if ($line === '') {
                continue;
            }
            $seen++;
            $name = [];
            foreach (preg_split('/\s+/', $line) ?: [] as $token) {
                $isName = preg_match('/^[A-ZÄÖÜ][\p{L}\-]{1,15}$/u', $token) === 1
                    && !$this->isFormWord($token);
                if ($isName) {
                    $name[] = $token;
                    if (count($name) >= 3) break;
                } elseif ($name !== []) {
                    break; // Namens-Sequenz endet (rechte Formularspalte beginnt)
                }
            }
            if (count($name) >= 2) {
                return implode(' ', $name);
            }
        }
        return null;
    }

    /** Bekanntes Formular-Stichwort (kein Name), das neben dem Namen stehen kann. */
    private function isFormWord(string $token): bool
    {
        static $words = [
            'verwandschaftsverhältnis', 'verwandschaftsverhaltnis', 'arbeitgeber',
            'beschäftigt', 'beschaftigt', 'gesellschafter', 'geschäftsführer',
            'geschaftsfuhrer', 'namenszusatz', 'vorsatzwort', 'titel', 'art', 'zum',
        ];
        return in_array(mb_strtolower($token), $words, true);
    }

    private function gender(string $line): ?string
    {
        if (preg_match('/\bM[äa]nnlich\b/u', $line)) return 'male';
        if (preg_match('/\bWeiblich\b/u', $line)) return 'female';
        return null;
    }

    private function maritalStatus(string $token): ?string
    {
        return match (mb_strtolower(trim($token))) {
            'ledig' => 'ledig',
            'verheiratet' => 'verheiratet',
            'geschieden' => 'geschieden',
            'verwitwet' => 'verwitwet',
            default => null,
        };
    }

    private function germanDate(?string $value): ?string
    {
        if ($value !== null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $value, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        return null;
    }
}
