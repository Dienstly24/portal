<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer die deutsche Geburtsurkunde (Standesamt). Das Formular ist
 * einheitlich in Abschnitte gegliedert - Kind, "1. Mutter", "2. Vater" - mit
 * festen Beschriftungen (Geburtsname, Familienname, Vorname(n), Geschlecht).
 * Daraus werden das Kind (Hauptperson) sowie Mutter und Vater gelesen; die
 * Eltern kommen mit ihrer Rolle ("relation") in die Personenliste, damit das
 * Kind anschliessend automatisch mit den Eltern-Kunden verknuepft werden kann.
 *
 * Alle Werte durchlaufen die harte Feldvalidierung; unsichere Felder bleiben
 * leer statt falsch.
 */
class GeburtsurkundeParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        if (mb_stripos($text, 'Geburtsurkunde') === false) {
            return null;
        }

        $this->lines = array_map('rtrim', preg_split('/\R/', $text) ?: []);

        // Abschnittsgrenzen: Kind / Mutter / Vater.
        $kindIdx = $this->headerIndex('/^\s*Kind\b/i');
        $mutterIdx = $this->headerIndex('/Mutter\b/i');
        $vaterIdx = $this->headerIndex('/Vater\b/i');
        $endIdx = $this->headerIndex('/Urkundsperson|Standesbeamt/i') ?? count($this->lines);

        // Kind: Geburtsname (Nachname), Vorname(n), Geschlecht + Geburtsort/-datum.
        $childFrom = $kindIdx ?? 0;
        $childTo = $mutterIdx ?? $endIdx;
        $child = [
            'last_name' => $this->cleanValue($this->labelIn('Geburtsname', $childFrom, $childTo)),
            'first_name' => $this->cleanValue($this->labelIn('Vorname', $childFrom, $childTo)),
        ];
        $sex = $this->labelIn('Geschlecht', $childFrom, $childTo);
        if ($sex !== null) {
            $l = mb_strtolower($sex);
            $child['gender'] = str_contains($l, 'männlich') || str_contains($l, 'maennlich')
                ? 'male'
                : (str_contains($l, 'weiblich') ? 'female' : null);
        }
        // Ort, Tag der Geburt (im Kopf, vor "Kind").
        if (preg_match('/Ort,\s*Tag der Geburt\s+([A-ZÄÖÜ][\p{L}.\- ]+?),?\s+(\d{2})\.(\d{2})\.(\d{4})/u', $this->text(), $m)) {
            $child['birth_place'] = trim($m[1]);
            $child['birth_date'] = $m[4] . '-' . $m[3] . '-' . $m[2];
        }
        $person = $this->validatedPerson(array_filter($child, fn ($v) => $v !== null && $v !== ''));

        // Eltern: Familienname (aktueller Nachname) + Vorname(n), je Abschnitt.
        $personen = [];
        if ($mutterIdx !== null) {
            $to = $vaterIdx ?? $endIdx;
            $mother = $this->parseParent($mutterIdx, $to, 'mutter', 'female');
            if ($mother !== null) {
                $personen[] = $mother;
            }
        }
        if ($vaterIdx !== null) {
            $father = $this->parseParent($vaterIdx, $endIdx, 'vater', 'male');
            if ($father !== null) {
                $personen[] = $father;
            }
        }
        $personen = $this->validatedPersons($personen);

        // Ohne belastbaren Kindsnamen der normalen Analyse/KI ueberlassen.
        if (($person['first_name'] ?? null) === null && ($person['last_name'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $elternNamen = array_map(
            fn ($p) => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')),
            $personen
        );
        return [
            'type' => 'geburtsurkunde',
            'confidence' => 72,
            'summary' => 'Geburtsurkunde'
                . ($name !== '' ? ' - Kind ' . $name : '')
                . (isset($person['birth_date']) ? ' (geb. ' . $this->displayDate($person['birth_date']) . ')' : '')
                . ($elternNamen !== [] ? ' - Eltern: ' . implode(', ', array_filter($elternNamen)) : '')
                . ' - Felder gratis aus der Urkunde gelesen (ohne KI).',
            'title' => 'Geburtsurkunde' . ($name !== '' ? ' ' . $name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => [],
                'kfz' => [],
                'gesundheit' => [],
                'bank' => [],
                'personen' => $personen,
                'energie' => [],
            ],
        ];
    }

    /**
     * Einen Elternteil aus seinem Abschnitt lesen (Familienname = aktueller
     * Nachname; Vorname(n) ohne den Klammerzusatz "(Vorname und Vatersnamen)").
     *
     * @return array<string,mixed>|null
     */
    private function parseParent(int $from, int $to, string $relation, string $gender): ?array
    {
        $last = $this->cleanValue($this->labelIn('Familienname', $from, $to));
        $first = $this->cleanValue($this->labelIn('Vorname', $from, $to));
        if ($last === null && $first === null) {
            return null;
        }
        return array_filter([
            'first_name' => $first,
            'last_name' => $last,
            'gender' => $gender,
            'relation' => $relation,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Wert eines Labels innerhalb eines Zeilenbereichs [$from, $to). */
    private function labelIn(string $label, int $from, int $to): ?string
    {
        for ($i = max(0, $from); $i < min($to, count($this->lines)); $i++) {
            if (preg_match('/^\s*' . preg_quote($label, '/') . '(?:\(n\))?\s*:?\s{2,}(\S.*?)\s*$/u', $this->lines[$i], $m)) {
                return trim($m[1]);
            }
        }
        return null;
    }

    /** Klammerzusatz ("(Vorname und Vatersnamen)") und Rand-Reste entfernen. */
    private function cleanValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $value));
        $value = trim((string) preg_replace('/\s{2,}.*$/u', '', $value));
        return $value === '' ? null : $value;
    }

    private function headerIndex(string $pattern): ?int
    {
        foreach ($this->lines as $i => $line) {
            if (preg_match($pattern, $line)) {
                return $i;
            }
        }
        return null;
    }

    private function displayDate(string $iso): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) ? $m[3] . '.' . $m[2] . '.' . $m[1] : $iso;
    }

    private function text(): string
    {
        return implode("\n", $this->lines);
    }
}
