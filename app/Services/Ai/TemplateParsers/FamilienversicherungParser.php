<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer den Fragebogen zur Familienversicherung (gesetzliche
 * Krankenversicherung, u.a. KKH). Das Formular fuehrt das Mitglied und seine
 * familienversicherten Angehoerigen (Ehegatte + Kinder) in einer festen
 * Spaltentabelle - es laesst sich per fester Regel aus der PDF-Textebene lesen
 * (kostenlos, deterministisch).
 *
 * Anders als beim Kfz-Beratungsprotokoll werden hier die Angehoerigen bewusst
 * MITgelesen (Betreiber-Regel: "keine Kinder" gilt nur fuer Kfz). Die
 * gelesenen Personen (data.person = Mitglied, data.personen = Angehoerige)
 * speisen den bestehenden Kranken-Familien-Workflow (Haupt-Frage,
 * familienversichert, Wechseldatum).
 */
class FamilienversicherungParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $upper = mb_strtoupper($text);
        // Fragebogen zur Familienversicherung (Mitglied + Angehoerige).
        if (!str_contains($upper, 'FAMILIENVERSICHERUNG')
            || !str_contains($upper, 'NAME DES MITGLIEDS')
            || !str_contains($upper, 'EHEGATTE')) {
            return null;
        }

        $this->lines = array_map('rtrim', preg_split('/\R/', $text) ?: []);

        $member = $this->parseMember();
        $personen = $this->parseAngehoerige($member['last_name'] ?? null);

        // Ohne Mitglied UND ohne Angehoerige nicht als Template ausgeben.
        if ($member === [] && $personen === []) {
            return null;
        }

        $memberName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
        return [
            'type' => 'familienversicherung',
            'confidence' => 70,
            'summary' => 'Familienversicherung (gesetzliche Krankenversicherung)'
                . ($memberName !== '' ? ' - Mitglied ' . $memberName : '')
                . ' + ' . count($personen) . ' Angehoerige - gratis aus dem Formular gelesen (ohne KI).',
            'title' => 'Familienversicherung' . ($memberName !== '' ? ' ' . $memberName : ''),
            'data' => [
                'person' => $member,
                'personen' => $personen,
                'versicherung' => $this->parseInsurance(),
                'gesundheit' => ['health_insurance_type' => 'gesetzlich'],
                'kfz' => [],
                'bank' => [],
                'energie' => [],
            ],
        ];
    }

    /** @return array<string,mixed> Mitglied (Vorname Nachname). */
    private function parseMember(): array
    {
        $raw = [];
        // "Hussam Eshak" steht ueber der Beschriftung "Vorname Name des Mitglieds".
        $line = $this->valueAbove('Name des Mitglieds');
        if ($line !== null) {
            $tokens = array_values(array_filter(
                preg_split('/\s+/', $line) ?: [],
                fn ($t) => preg_match('/^\p{L}[\p{L}\-]+$/u', $t) === 1
            ));
            if (count($tokens) >= 2) {
                $raw['first_name'] = $tokens[0];
                $raw['last_name'] = implode(' ', array_slice($tokens, 1));
            }
        }
        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Angehoerige aus der Spaltentabelle (Ehegatte | Kind | Kind | Kind).
     * Vorname- und Geburtsort-Zeile stehen spaltenweise (2+ Leerzeichen),
     * die Geburtsdaten (oft eng gesetzt) werden der Reihe nach zugeordnet.
     *
     * @return list<array<string,mixed>>
     */
    private function parseAngehoerige(?string $memberLastName): array
    {
        $names = $this->columnValues('Vorname');
        if ($names === []) {
            return [];
        }
        $places = $this->columnValues('Geburtsort');
        $dates = $this->datesInRow('Geburtsdatum');

        $out = [];
        foreach ($names as $i => $first) {
            $raw = ['first_name' => $first];
            if ($memberLastName) {
                // Angehoerige tragen i.d.R. den Nachnamen des Mitglieds.
                $raw['last_name'] = $memberLastName;
            }
            if (isset($dates[$i])) {
                $raw['birth_date'] = $this->germanDate($dates[$i]);
            }
            if (isset($places[$i])) {
                $raw['birth_place'] = $places[$i];
            }
            $out[] = $raw;
        }

        return $this->validatedPersons($out);
    }

    /** @return array<string,mixed> */
    private function parseInsurance(): array
    {
        $raw = ['sparte' => 'krankenversicherung'];
        if (preg_match('/Beginn der Familienversicherung:?\s*(\d{2}\.\d{2}\.\d{4})/u', $this->text(), $m)) {
            $raw['start_date'] = $this->germanDate($m[1]);
        }
        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Werte einer spaltenweisen Datenzeile (Label + 2+ Leerzeichen getrennte
     * Spalten), gefiltert auf echte Werte (keine Platzhalter/Labels).
     * @return list<string>
     */
    private function columnValues(string $label): array
    {
        foreach ($this->lines as $line) {
            if (!preg_match('/^' . preg_quote($label, '/') . '\s{2,}(.+)$/u', $line, $m)) {
                continue;
            }
            $cells = preg_split('/\s{2,}/', trim($m[1])) ?: [];
            $values = [];
            foreach ($cells as $cell) {
                $cell = trim($cell);
                // Nur ein einzelnes Namens-/Ortswort je Spalte (erstes Token),
                // Platzhalter ("____") und Leerzellen ueberspringen.
                if (preg_match('/^([\p{L}][\p{L}\-]+)/u', $cell, $mm)) {
                    $values[] = $mm[1];
                }
            }
            if ($values !== []) {
                return $values;
            }
        }
        return [];
    }

    /** Alle Datumsangaben (dd.mm.yyyy) einer Zeile in Reihenfolge. @return list<string> */
    private function datesInRow(string $label): array
    {
        foreach ($this->lines as $line) {
            if (mb_stripos($line, $label) === 0 && preg_match_all('/\b(\d{2}\.\d{2}\.\d{4})\b/', $line, $m)) {
                return $m[1];
            }
        }
        return [];
    }

    private function text(): string
    {
        return implode("\n", $this->lines);
    }

    private function labelIndex(string $needle): ?int
    {
        foreach ($this->lines as $i => $line) {
            if (trim($line) !== '' && mb_stripos($line, $needle) !== false) {
                return $i;
            }
        }
        return null;
    }

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

    private function germanDate(?string $value): ?string
    {
        if ($value !== null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $value, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        return null;
    }
}
