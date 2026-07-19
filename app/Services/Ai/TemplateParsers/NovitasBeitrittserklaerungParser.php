<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer die Beitrittserklaerung der Novitas BKK (gesetzliche
 * Krankenversicherung). Wie die KKH-Beitrittserklaerung ist dieses Formular
 * ueber alle Kunden hinweg identisch aufgebaut - nur die Werte unterscheiden
 * sich - und laesst sich per fester Regel gratis und zuverlaessig lesen.
 *
 * Besonderheit dieser Formulare: die Textebene ist zweigeteilt. Die FEST
 * gedruckten Beschriftungen ("Name", "Geburtsdatum" ...) nutzen eine Schrift
 * OHNE gueltiges ToUnicode und kommen bei `pdftotext` als Mojibake heraus
 * (z.B. "Geburtsdatum" -> "32G:?>;-?G5"). Die AUSGEFUELLTEN Werte (Name,
 * Datum, Ort ...) stehen dagegen in einer sauberen Schrift und sind exakt
 * lesbar - inklusive der Spaltenpositionen aus `-layout`. Genau diese
 * Spalten braucht es fuer mehrteilige Namen (z.B. Nachname "Al Shouli",
 * mehrere Vornamen), die OCR unwiederbringlich zu einer Zeile verschmilzt.
 *
 * Der Parser ankert deshalb auf den (ueber alle Novitas-Formulare byte-
 * stabilen) Mojibake-Beschriftungen und liest die sauberen Werte relativ
 * dazu. Kommt das Formular ausnahmsweise mit intakter Textebene (oder aus
 * OCR) mit deutschen Klartext-Labels, greifen dieselben Anker als Klartext-
 * Alternative. Alle Werte durchlaufen die harte Feldvalidierung; Unsicheres
 * bleibt leer statt falsch.
 */
class NovitasBeitrittserklaerungParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    private const INSURER = 'Novitas BKK';

    // Byte-stabile Mojibake-Fingerabdruecke fuer die Erkennung (defekter Font).
    private const MOJIBAKE_NOVITAS = '7=A/?->M2<<';  // "novitas bkk"
    private const MOJIBAKE_BIRTH = '32G:?>;-?G5';    // "Geburtsdatum"

    // Byte-stabile Mojibake-Beschriftungen (defektes Formular-Font) mit ihrer
    // deutschen Bedeutung. Jeweils zusaetzlich das Klartext-Label als
    // Alternative (falls dieselbe Zeilenlage doch mit intakter Textebene kaeme).
    private const A_NAME     = ['K-53', 'Vorname'];                // "Name"/"Vorname"
    private const A_BIRTH    = ['32G:?>;-?G5', 'Geburtsdatum'];    // "Geburtsdatum"
    private const A_STREET   = ['E?:-', 'Stra'];                   // "Strasse, Hausnummer"
    private const A_KK       = ['L:-7<37<->>3', 'Krankenkasse'];   // "Krankenkasse"
    private const A_WEIBLICH = ['B3/26/01', 'weiblich'];
    private const A_MAENNLICH = ['776/01', 'nnlich'];              // m(ae)nnlich
    private const A_DIVERS    = [';/A3:>', 'divers'];
    private const A_UNBESTIMMT = ['G723>?/55?', 'unbestimmt'];

    private const MARITAL = ['verheiratet', 'ledig', 'geschieden', 'verwitwet'];

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        // Nur zustaendig fuer die (defekt kodierte) Novitas-Beitrittserklaerung.
        // Die Extraktionslogik ist exakt auf DIESE Textebene (Mojibake-Labels
        // ueber sauberen Werten, feste -layout-Spalten) kalibriert und
        // verifiziert. Deshalb wird bewusst NUR auf die byte-stabilen Mojibake-
        // Anker getriggert - kaeme ein Formular mit intakter Textebene (andere
        // Zeilenlage), wuerde diese Logik falsche Werte lesen. Ein solcher Fall
        // faellt lieber sauber auf die normale Analyse (Heuristik/KI) zurueck,
        // statt Stammdaten zu erfinden. Der Novitas-Anker grenzt zugleich sicher
        // gegen andere Krankenkassen-Formulare (z.B. KKH) ab.
        if (!str_contains($text, self::MOJIBAKE_NOVITAS) || !str_contains($text, self::MOJIBAKE_BIRTH)) {
            return null;
        }

        // -layout-Zeilen bewusst NICHT trimmen: die fuehrenden Leerspalten
        // tragen die Spalteninformation (X-Position beim Geschlecht,
        // Datum|Ort|Familienstand nebeneinander).
        $this->lines = preg_split('/\R/', $text) ?: [];

        $person = $this->parsePerson();
        $health = $this->parseHealth();
        $versicherung = $this->parseInsurance($health);

        // Ohne belastbaren Namen der normalen Analyse ueberlassen.
        if (($person['first_name'] ?? null) === null && ($person['last_name'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        return [
            'type' => 'beitrittserklaerung',
            'confidence' => 70,
            'summary' => 'Novitas-BKK-Beitrittserklaerung (gesetzliche Krankenversicherung)'
                . ($name !== '' ? ' - ' . $name : '')
                . ' - Felder gratis aus dem Formular gelesen (ohne KI).'
                . (isset($health['previous_insurer']) ? ' Zuvor versichert bei ' . $health['previous_insurer'] . '.' : ''),
            'title' => 'Beitrittserklaerung Novitas BKK' . ($name !== '' ? ' ' . $name : ''),
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

        // Name: die Wertzeile steht ueber der Beschriftung "Name  Vorname".
        // Dank -layout stehen Nachname (linke Spalte) und Vorname(n) (rechte
        // Spalte) durch mehrere Leerzeichen getrennt - so bleiben mehrteilige
        // Namen ("Al Shouli" | "Ali") korrekt zugeordnet.
        $nameLine = $this->valueAbove(self::A_NAME);
        if ($nameLine !== null) {
            $cols = $this->columns($nameLine);
            if (count($cols) >= 2) {
                $raw['last_name'] = $cols[0];
                $raw['first_name'] = $cols[1];
            } elseif ($cols !== []) {
                $raw['last_name'] = $cols[0];
            }
        }

        // Geburtsdatum | Geburtsort | Familienstand stehen in EINER Wertzeile.
        $birthLine = $this->valueAbove(self::A_BIRTH);
        if ($birthLine !== null) {
            if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $birthLine, $m)) {
                $raw['birth_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
            }
            $rest = trim((string) preg_replace('/^.*?\d{2}\.\d{2}\.\d{4}\s*/u', '', $birthLine));
            // Familienstand am Zeilenende abspalten.
            foreach (self::MARITAL as $status) {
                if (stripos($rest, $status) !== false) {
                    $raw['marital_status'] = $status;
                    $rest = trim((string) preg_replace('/' . $status . '.*$/i', '', $rest));
                    break;
                }
            }
            // Rest = Geburtsort (Stadt/Land), OCR-/Layout-Reste am Ende kappen.
            $place = trim((string) preg_replace('/\s{2,}.*$/u', '', $rest));
            $place = trim((string) preg_replace('/[|]+$/', '', $place));
            $place = trim((string) preg_replace('/[^\p{L}\s,.\/\-]/u', '', $place));
            if (mb_strlen($place) >= 2) {
                $raw['birth_place'] = $place;
            }
        }

        $raw['gender'] = $this->gender();

        // Strasse + Hausnummer: Wertzeile ueber dem ERSTEN Strasse-Label
        // (das zweite gehoert zum Arbeitgeber).
        $streetLine = $this->valueAbove(self::A_STREET);
        if ($streetLine !== null) {
            $street = $this->columns($streetLine)[0] ?? trim($streetLine);
            // Hausnummer am Ende abspalten (auch nach "Str." ohne Leerzeichen).
            if (preg_match('/^(.*\D)\s*(\d+(?:\s*[a-zA-Z])?)\s*$/u', $street, $m)) {
                $raw['street'] = trim($m[1]);
                $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $m[2]));
            } elseif (preg_match('/\p{L}/u', $street)) {
                $raw['street'] = trim($street);
            }
        }

        // PLZ + Ort: erste "NNNNN  Ort"-Zeile (die des Kunden, vor der des
        // Arbeitgebers). Ort nur bis zur naechsten Grossspalte (schneidet die
        // rechts stehende Rentenversicherungsnummer ab).
        foreach ($this->lines as $line) {
            if (preg_match('/(?<!\d)(\d{5})\s{2,}([A-ZÄÖÜ][\p{L}.\-]+(?:[ \-][A-ZÄÖÜ]?[\p{L}.\-]+)*)/u', $line, $m)) {
                $raw['zip'] = $m[1];
                $raw['city'] = trim((string) preg_replace('/\s{2,}.*$/u', '', $m[2]));
                break;
            }
        }

        // E-Mail (wenn angegeben).
        if (preg_match('/[\w.+\-]+@[\w.\-]+\.\w{2,}/u', $this->text(), $m)) {
            $raw['email'] = $m[0];
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

        // Renten-/Sozialversicherungsnummer: 8 Ziffern + 1 Buchstabe + 3 Ziffern.
        if (preg_match('/\b(\d{8}[A-Z]\d{3})\b/', $this->text(), $m)) {
            $raw['pension_number'] = $m[1];
        }

        // Vorversicherung: im Block "bei der Krankenkasse" steht die letzte
        // Kasse rechts neben dem Bis-Datum ("31.03.2026    Privat").
        $prev = $this->previousInsurer();
        if ($prev !== null) {
            $raw['previous_insurer'] = $prev;
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
        // Mitgliedsbeginn ("JA, ICH MOECHTE ZUM ...") = Versicherungsbeginn =
        // das erste Datum im Formular.
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $this->text(), $m)) {
            $raw['start_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Geschlecht aus der Ankreuz-Zeile: das 'X' steht unmittelbar VOR der
     * gewaehlten Option (weiblich | maennlich | divers | unbestimmt). Wir
     * nehmen die Option, deren Anker am naechsten RECHTS vom X liegt.
     */
    private function gender(): ?string
    {
        $line = $this->lineContaining(self::A_WEIBLICH);
        if ($line === null) {
            return null;
        }
        $x = mb_strpos($line, 'X');
        if ($x === false) {
            return null;
        }
        $options = [
            'female' => self::A_WEIBLICH,
            'male' => self::A_MAENNLICH,
            'divers' => self::A_DIVERS,
            'unbestimmt' => self::A_UNBESTIMMT,
        ];
        $bestKey = null;
        $bestPos = PHP_INT_MAX;
        foreach ($options as $key => $needles) {
            $pos = $this->needlePos($line, $needles);
            if ($pos !== null && $pos > $x && $pos < $bestPos) {
                $bestPos = $pos;
                $bestKey = $key;
            }
        }
        return in_array($bestKey, ['male', 'female'], true) ? $bestKey : null;
    }

    /** Letzte Krankenkasse (Vorversicherung) aus dem "bei der Krankenkasse"-Block. */
    private function previousInsurer(): ?string
    {
        $kkIndex = null;
        foreach ($this->lines as $i => $line) {
            if ($this->needlePos($line, self::A_KK) !== null) {
                $kkIndex = $i;
                break;
            }
        }
        if ($kkIndex === null) {
            return null;
        }
        // Wertzeile "Bis-Datum   Kassenname" ein paar Zeilen ueber dem Label.
        for ($j = $kkIndex; $j >= max(0, $kkIndex - 8); $j--) {
            if (preg_match('/^\s*\d{2}\.\d{2}\.\d{4}\s{2,}(\p{L}[\p{L}\s.\-]{1,50})$/u', $this->lines[$j], $m)) {
                $insurer = trim($m[1]);
                if (stripos($insurer, 'versichert') === false) {
                    return $insurer;
                }
            }
        }
        return null;
    }

    private function text(): string
    {
        return implode("\n", $this->lines);
    }

    /** Zerlegt eine -layout-Zeile an Spaltengrenzen (>= 2 Leerzeichen). @return list<string> */
    private function columns(string $line): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\s{2,}/', trim($line)) ?: []),
            fn ($c) => $c !== ''
        ));
    }

    /** Enthaelt der Text eines der Anker-Alternativen (Mojibake ODER Klartext)? */
    private function contains(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && mb_stripos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Byte-Position des ersten passenden Ankers in der Zeile (oder null). */
    private function needlePos(string $line, array $needles): ?int
    {
        foreach ($needles as $needle) {
            $pos = mb_strpos($line, $needle);
            if ($pos !== false) {
                return $pos;
            }
        }
        return null;
    }

    /** Erste Zeile, die einen der Anker enthaelt. */
    private function lineContaining(array $needles): ?string
    {
        foreach ($this->lines as $line) {
            if ($this->needlePos($line, $needles) !== null) {
                return $line;
            }
        }
        return null;
    }

    /** Naechste nicht-leere Zeile UEBER der ersten Zeile mit einem der Anker. */
    private function valueAbove(array $needles): ?string
    {
        foreach ($this->lines as $i => $line) {
            if ($this->needlePos($line, $needles) === null) {
                continue;
            }
            for ($j = $i - 1; $j >= 0; $j--) {
                if (trim($this->lines[$j]) !== '') {
                    return rtrim($this->lines[$j]);
                }
            }
            return null;
        }
        return null;
    }
}
