<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer den Reisepass ueber die maschinenlesbare Zone (MRZ,
 * ICAO 9303 TD3: zwei Zeilen a 44 Zeichen am unteren Rand der Passseite).
 * Die MRZ ist genormt und OCR-freundlich (OCR-B) - sie laesst sich exakt und
 * unabhaengig von der Sprache (auch bei arabischen Paessen) deterministisch
 * dekodieren:
 *
 *   Zeile 1: P<CCC NACHNAME << VORNAMEN            (Dokumenttyp, Land, Name)
 *   Zeile 2: PassNr(9) Pruef Nat(3) Geb(6) Pruef Geschlecht Ablauf(6) ...
 *
 * Daraus werden Nachname, Vorname(n), Passnummer, Staatsangehoerigkeit,
 * Geburtsdatum, Geschlecht und Ablaufdatum gelesen - deutlich zuverlaessiger
 * als die OCR der sichtbaren (oft zweisprachigen) Datenzeilen. Fehlt der Name
 * in der MRZ (OCR-Ausfall der "<"-Zeichen), wird er ersatzweise aus den
 * VIZ-Beschriftungen "Surname"/"Name" gelesen.
 *
 * Alle Werte durchlaufen die harte Feldvalidierung; unsichere Felder bleiben
 * leer statt falsch.
 */
class ReisepassMrzParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /** Haeufige Staatsangehoerigkeits-Codes (ISO 3166 alpha-3) -> Land. */
    private const NATIONALITY = [
        'SYR' => 'Syrien', 'IRQ' => 'Irak', 'TUR' => 'Tuerkei', 'AFG' => 'Afghanistan',
        'IRN' => 'Iran', 'RUS' => 'Russland', 'UKR' => 'Ukraine', 'LBN' => 'Libanon',
        'EGY' => 'Aegypten', 'MAR' => 'Marokko', 'TUN' => 'Tunesien', 'DZA' => 'Algerien',
        'JOR' => 'Jordanien', 'PSE' => 'Palaestina', 'SOM' => 'Somalia', 'ERI' => 'Eritrea',
        'PAK' => 'Pakistan', 'IND' => 'Indien', 'DEU' => 'Deutschland',
    ];

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $this->lines = preg_split('/\R/', $text) ?: [];

        [$line1, $line2] = $this->findMrzLines();
        if ($line2 === null) {
            return null; // Ohne MRZ-Datenzeile nicht zustaendig.
        }

        $raw = [];

        // Zeile 2: Passnummer, Staatsangehoerigkeit, Geburtsdatum, Geschlecht,
        // Ablauf - am genormten Muster verankert (robust gegen Rand-Rauschen).
        if (!preg_match('/([A-Z0-9<]{9})(\d)([A-Z]{3})(\d{6})(\d)([MFX<])(\d{6})/', $line2, $m)) {
            return null;
        }
        $passNo = rtrim($m[1], '<');
        if ($passNo !== '') {
            $raw['id_number'] = $passNo;
        }
        $raw['nationality'] = self::NATIONALITY[$m[3]] ?? $m[3];
        if (($birth = $this->mrzDate($m[4], false)) !== null) {
            $raw['birth_date'] = $birth;
        }
        $raw['gender'] = match ($m[6]) {
            'M' => 'male',
            'F' => 'female',
            default => null,
        };
        $expiry = $this->mrzDate($m[7], true);

        // Zeile 1: Nachname und Vorname(n) aus dem Namensfeld (durch "<<"
        // getrennt, "<" als Fueller).
        if ($line1 !== null && preg_match('/^P[A-Z<]([A-Z]{3})([A-Z<].*)$/', $line1, $n)) {
            $nameField = $n[2];
            $partsRaw = preg_split('/<</', $nameField, 2) ?: [];
            $surname = $this->mrzName($partsRaw[0] ?? '');
            $given = $this->mrzName($partsRaw[1] ?? '');
            if ($surname !== '') {
                $raw['last_name'] = $surname;
            }
            if ($given !== '') {
                $raw['first_name'] = $given;
            }
        }
        // Ersatz aus den sichtbaren Beschriftungen, falls die MRZ-Namen fehlen.
        if (!isset($raw['last_name']) && ($s = $this->vizLabel('Surname')) !== null) {
            $raw['last_name'] = $this->titleCase($s);
        }
        if (!isset($raw['first_name']) && ($g = $this->vizLabel('Name')) !== null) {
            $raw['first_name'] = $this->titleCase($g);
        }

        $person = $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));

        // Ohne belastbaren Namen ODER Passnummer der normalen Analyse ueberlassen.
        if (($person['last_name'] ?? null) === null && ($person['id_number'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        return [
            'type' => 'reisepass',
            'confidence' => 78,
            'summary' => 'Reisepass (maschinenlesbare Zone gelesen)'
                . ($name !== '' ? ' - ' . $name : '')
                . (isset($person['nationality']) ? ' - ' . $person['nationality'] : '')
                . ($expiry !== null ? ' - gueltig bis ' . $this->displayDate($expiry) : '')
                . ' - Felder gratis aus der MRZ gelesen (ohne KI).',
            'title' => 'Reisepass' . ($name !== '' ? ' ' . $name : ''),
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
     * Findet die beiden MRZ-Zeilen: bereinigt (nur A-Z/0-9/<), Zeile 1 beginnt
     * mit "P" (Pass) und enthaelt "<<", Zeile 2 ist die Datenzeile darunter.
     *
     * @return array{0:?string,1:?string} [Zeile1, Zeile2]
     */
    private function findMrzLines(): array
    {
        $clean = [];
        foreach ($this->lines as $line) {
            $c = strtoupper((string) preg_replace('/\s+/', '', $line));
            // Nur MRZ-Zeichen und ausreichend lang.
            if (mb_strlen($c) >= 28 && preg_match('/^[A-Z0-9<]+$/', $c) && str_contains($c, '<')) {
                $clean[] = $c;
            } else {
                $clean[] = null;
            }
        }
        $line1 = null;
        for ($i = 0; $i < count($clean); $i++) {
            if ($clean[$i] === null) {
                continue;
            }
            if ($line1 === null && preg_match('/^P[A-Z<][A-Z]{3}[A-Z<]*<<[A-Z<]+$/', $clean[$i])) {
                $line1 = $clean[$i];
                continue;
            }
            // Datenzeile (mit Nat+Geburtsdatum+Geschlecht+Ablauf-Muster).
            if (preg_match('/[A-Z0-9<]{9}\d[A-Z]{3}\d{6}\d[MFX<]\d{6}/', $clean[$i])) {
                return [$line1, $clean[$i]];
            }
        }
        return [$line1, null];
    }

    /** MRZ-Datum "YYMMDD" -> "JJJJ-MM-TT". $expiry steuert die Jahrhundertwahl. */
    private function mrzDate(string $yymmdd, bool $expiry): ?string
    {
        if (!preg_match('/^(\d{2})(\d{2})(\d{2})$/', $yymmdd, $m)) {
            return null;
        }
        $yy = (int) $m[1];
        // Ablauf liegt in der Zukunft -> 20YY. Geburtsdatum: Pivot bei 30
        // (00-30 -> 20YY, 31-99 -> 19YY) - deterministisch, ohne "heute".
        $year = $expiry ? 2000 + $yy : ($yy <= 30 ? 2000 + $yy : 1900 + $yy);
        if (!checkdate((int) $m[2], (int) $m[3], $year)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[3]);
    }

    /** MRZ-Namensteil ("KUTAISH", "SAFA<PETER") -> "Kutaish", "Safa Peter". */
    private function mrzName(string $part): string
    {
        $part = trim(str_replace('<', ' ', $part));
        $part = (string) preg_replace('/\s+/', ' ', $part);
        return $part === '' ? '' : $this->titleCase($part);
    }

    private function titleCase(string $s): string
    {
        return mb_convert_case(trim($s), MB_CASE_TITLE, 'UTF-8');
    }

    /** Wert einer sichtbaren Beschriftung ("Surname   KUTAISH"). */
    private function vizLabel(string $label): ?string
    {
        foreach ($this->lines as $line) {
            if (preg_match('/\b' . preg_quote($label, '/') . '\b\s*:?\s+([A-ZÄÖÜ][A-Za-zÄÖÜäöüß\- ]{1,40})/u', $line, $m)) {
                $val = trim($m[1]);
                if (mb_strtoupper($val) === $val || preg_match('/^\p{Lu}/u', $val)) {
                    return $val;
                }
            }
        }
        return null;
    }

    private function displayDate(string $iso): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) ? $m[3] . '.' . $m[2] . '.' . $m[1] : $iso;
    }
}
