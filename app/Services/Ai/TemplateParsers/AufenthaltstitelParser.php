<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer den deutschen elektronischen Aufenthaltstitel (eAT,
 * "AUFENTHALTSTITEL"/Aufenthaltserlaubnis) aus der OCR-Textebene eines
 * einzelnen Kartenfotos. Die Karte ist bundesweit einheitlich mit festen
 * (zweisprachigen) Beschriftungen aufgebaut: NAMEN/SURNAMES, Vornamen/
 * Forenames, GESCHLECHT/SEX, STAATSANGEHOERIGKEIT/NATIONALITY, GEBURTSDATUM/
 * DATE OF BIRTH, ART DES TITELS, KARTE GUELTIG BIS. Daraus werden Name,
 * Geschlecht, Staatsangehoerigkeit und Geburtsdatum gelesen.
 *
 * Bewusst NUR fuer eine EINZELNE Karte: zeigt ein Foto mehrere Karten (z.B.
 * eine ganze Familie mit mehreren Aufenthaltstiteln und Gesundheitskarten),
 * liefert der Parser null - dann uebernimmt die KI-Vision die korrekte
 * Zuordnung aller Personen (personen-Buendel). So entsteht aus einem
 * Mehr-Karten-Foto nie faelschlich nur EINE Person.
 *
 * Alle Werte durchlaufen die harte Feldvalidierung; unsichere Felder bleiben
 * leer statt falsch.
 */
class AufenthaltstitelParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /**
     * Haeufige Staatsangehoerigkeits-Laendercodes (ISO 3166 alpha-3) -> Land.
     * Nur eindeutige Codes; unbekannte werden als Rohcode uebernommen.
     */
    private const NATIONALITY = [
        'IRQ' => 'Irak', 'SYR' => 'Syrien', 'TUR' => 'Tuerkei', 'AFG' => 'Afghanistan',
        'IRN' => 'Iran', 'RUS' => 'Russland', 'UKR' => 'Ukraine', 'LBN' => 'Libanon',
        'EGY' => 'Aegypten', 'MAR' => 'Marokko', 'TUN' => 'Tunesien', 'DZA' => 'Algerien',
        'JOR' => 'Jordanien', 'PSE' => 'Palaestina', 'SOM' => 'Somalia', 'ERI' => 'Eritrea',
        'PAK' => 'Pakistan', 'IND' => 'Indien', 'DEU' => 'Deutschland',
    ];

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $upper = mb_strtoupper($text);

        // Nur der Aufenthaltstitel (eAT). Personalausweis/Reisepass bewusst
        // ausgeschlossen (eigene Typen).
        if (!str_contains($upper, 'AUFENTHALTSTITEL') && !str_contains($upper, 'AUFENTHALTSERLAUBNIS')
            && !str_contains($upper, 'RESIDENCE PERMIT')) {
            return null;
        }

        // Mehrere Karten auf einem Bild (Familie) -> der KI-Vision ueberlassen,
        // die jede Person korrekt zuordnet. Erkennung an mehrfach auftretenden
        // Kartenmarkern.
        if ($this->markerCount($upper) > 1) {
            return null;
        }

        $this->lines = array_map('trim', preg_split('/\R/', $text) ?: []);

        $raw = [];

        // Name (NAMEN/SURNAMES) und Vornamen (Forenames): auf der Karte steht
        // der Nachname (Grossbuchstaben) in der Zeile unter der Beschriftung,
        // der/die Vorname(n) in der Zeile darunter.
        $surnameIdx = $this->lineIndex('/\b(?:NAMEN|SURNAMES?)\b/i');
        if ($surnameIdx !== null) {
            $vals = $this->nextNonEmpty($surnameIdx, 2);
            if (isset($vals[0]) && $this->looksLikeName($vals[0])) {
                $raw['last_name'] = $this->normalizeName($vals[0]);
            }
            if (isset($vals[1]) && $this->looksLikeName($vals[1])) {
                $raw['first_name'] = $this->normalizeName($vals[1]);
            }
        }

        // Geschlecht + Staatsangehoerigkeit + Geburtsdatum stehen zusammen in
        // EINER Wertzeile ("M   IRQ   28 03 1987").
        $this->fillSexNationalityBirth($raw);

        // Dokumentennummer (oben rechts, z.B. "YZ119CMFH") - nur wenn eindeutig.
        if (preg_match('/\b([A-Z]{2}\d[A-Z0-9]{5,7})\b/', $this->text(), $m)) {
            $raw['id_number'] = $m[1];
        }

        $person = $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));

        // Ohne belastbaren Namen der normalen Analyse/KI ueberlassen.
        if (($person['last_name'] ?? null) === null && ($person['first_name'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $expiry = $this->expiryDate();
        return [
            'type' => 'aufenthaltstitel',
            'confidence' => 68,
            'summary' => 'Aufenthaltstitel (Aufenthaltserlaubnis)'
                . ($name !== '' ? ' - ' . $name : '')
                . (isset($person['nationality']) ? ' - Staatsangehoerigkeit ' . $person['nationality'] : '')
                . ($expiry !== null ? ' - gueltig bis ' . $expiry : '')
                . ' - Felder gratis aus der Karte gelesen (ohne KI).',
            'title' => 'Aufenthaltstitel' . ($name !== '' ? ' ' . $name : ''),
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

    /** @param array<string,mixed> $raw */
    private function fillSexNationalityBirth(array &$raw): void
    {
        // Bevorzugt die kombinierte Wertzeile (Geschlecht | Land | Datum).
        foreach ($this->lines as $line) {
            if (preg_match('/\b([MFWX])\b\s+([A-Z]{3})\b\s+(\d{2})[ .](\d{2})[ .](\d{4})/', $line, $m)) {
                $raw['gender'] = $this->gender($m[1]);
                $raw['nationality'] = $this->nationality($m[2]);
                $raw['birth_date'] = $m[5] . '-' . $m[4] . '-' . $m[3];
                return;
            }
        }
        // Fallbacks (Zeilen einzeln), falls OCR die Spalten getrennt hat.
        if (($nat = $this->firstNationality()) !== null) {
            $raw['nationality'] = $this->nationality($nat);
        }
        $birthIdx = $this->lineIndex('/GEBURTSDATUM|DATE OF BIRTH/i');
        if ($birthIdx !== null) {
            foreach ($this->nextNonEmpty($birthIdx, 3) as $v) {
                if (preg_match('/(\d{2})[ .](\d{2})[ .](\d{4})/', $v, $m)) {
                    $raw['birth_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
                    break;
                }
            }
        }
        if (!isset($raw['gender'])) {
            $sexIdx = $this->lineIndex('/GESCHLECHT|\bSEX\b/i');
            if ($sexIdx !== null) {
                foreach ($this->nextNonEmpty($sexIdx, 2) as $v) {
                    if (preg_match('/^\s*([MFWX])\b/', $v, $m)) {
                        $raw['gender'] = $this->gender($m[1]);
                        break;
                    }
                }
            }
        }
    }

    /** Ablaufdatum ("KARTE GUELTIG BIS/CARD EXPIRY") als TT.MM.JJJJ (Anzeige). */
    private function expiryDate(): ?string
    {
        $idx = $this->lineIndex('/G[ÜU]LTIG BIS|CARD EXPIRY/i');
        if ($idx !== null) {
            foreach ($this->nextNonEmpty($idx, 3) as $v) {
                if (preg_match('/(\d{2})[ .](\d{2})[ .](\d{4})/', $v, $m)) {
                    return $m[1] . '.' . $m[2] . '.' . $m[3];
                }
            }
        }
        return null;
    }

    private function gender(string $letter): ?string
    {
        return match (strtoupper($letter)) {
            'M' => 'male',
            'F', 'W' => 'female',
            default => null,
        };
    }

    private function nationality(string $code): string
    {
        return self::NATIONALITY[strtoupper($code)] ?? strtoupper($code);
    }

    /** Erster eindeutiger 3-Buchstaben-Laendercode aus der Codeliste. */
    private function firstNationality(): ?string
    {
        if (preg_match_all('/\b([A-Z]{3})\b/', $this->text(), $mm)) {
            foreach ($mm[1] as $code) {
                if (isset(self::NATIONALITY[$code])) {
                    return $code;
                }
            }
        }
        return null;
    }

    /** Anzahl Karten-Marker (fuer die Einzel-/Mehr-Karten-Unterscheidung). */
    private function markerCount(string $upper): int
    {
        return max(
            substr_count($upper, 'AUFENTHALTSTITEL'),
            substr_count($upper, 'AUFENTHALTSERLAUBNIS'),
            substr_count($upper, 'GEBURTSDATUM'),
        );
    }

    private function looksLikeName(string $s): bool
    {
        return (bool) preg_match('/^\p{Lu}[\p{L}\-\'’ ]+$/u', trim($s)) && mb_strlen(trim($s)) >= 2;
    }

    /** Nachnamen in Grossbuchstaben ("MUSTAFA") zu "Mustafa" normalisieren. */
    private function normalizeName(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ', $s));
        if ($s === mb_strtoupper($s)) {
            return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
        }
        return $s;
    }

    private function lineIndex(string $pattern): ?int
    {
        foreach ($this->lines as $i => $line) {
            if (preg_match($pattern, $line)) {
                return $i;
            }
        }
        return null;
    }

    /** @return list<string> Die naechsten $count nicht-leeren Zeilen ab Index+1. */
    private function nextNonEmpty(int $index, int $count): array
    {
        $out = [];
        for ($j = $index + 1; $j < count($this->lines) && count($out) < $count; $j++) {
            $v = trim($this->lines[$j]);
            if ($v !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }

    private function text(): string
    {
        return implode("\n", $this->lines);
    }
}
