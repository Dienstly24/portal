<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer den deutschen elektronischen Aufenthaltstitel (eAT,
 * "AUFENTHALTSTITEL"/Aufenthaltserlaubnis) aus der OCR-Textebene eines
 * einzelnen Kartenfotos - VORDER- und RUECKSEITE:
 *
 * - VORDERSEITE: feste (zweisprachige) Beschriftungen NAMEN/SURNAMES,
 *   Vornamen/Forenames, GESCHLECHT/SEX, STAATSANGEHOERIGKEIT/NATIONALITY,
 *   GEBURTSDATUM/DATE OF BIRTH, ART DES TITELS, KARTE GUELTIG BIS. Daraus
 *   werden Name, Geschlecht, Staatsangehoerigkeit und Geburtsdatum gelesen.
 * - RUECKSEITE: sie traegt KEINE dieser Beschriftungen, dafuer die
 *   maschinenlesbare Zone (MRZ, ICAO 9303 TD1: drei Zeilen a 30 Zeichen,
 *   Zeile 1 beginnt mit "AR" + Ausstellerstaat + Dokumentennummer). Die MRZ
 *   ist genormt und OCR-freundlich (OCR-B) und wird deterministisch samt
 *   Pruefziffern dekodiert: Name, Geburtsdatum, Geschlecht,
 *   Staatsangehoerigkeit, Dokumentennummer, Ablauf. Zusaetzlich werden die
 *   Klartext-Bloecke der Rueckseite gelesen: Anschrift (Aufkleber
 *   "Anschrift/Address/Adresse" -> Strasse/Hausnummer/PLZ/Ort) und
 *   GEBURTSORT/PLACE OF BIRTH.
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
        $this->lines = array_map('trim', preg_split('/\R/', $text) ?: []);

        // Mehrere Karten auf einem Bild (Familie) -> der KI-Vision ueberlassen,
        // die jede Person korrekt zuordnet. Erkennung an mehrfach auftretenden
        // Kartenmarkern (Vorderseite) bzw. MRZ-Bloecken (Rueckseite).
        $mrzCount = $this->td1DataLineCount();
        if ($mrzCount > 1 || $this->markerCount($upper) > 1) {
            return null;
        }

        // RUECKSEITE: sie traegt keine Vorderseiten-Beschriftungen, aber die
        // maschinenlesbare Zone (TD1-MRZ) - sie ist die verlaesslichste Quelle
        // und gewinnt auch bei einem kombinierten Vorder-/Rueckseiten-Scan.
        if ($mrzCount === 1) {
            return $this->parseBackSide();
        }

        // Nur der Aufenthaltstitel (eAT). Personalausweis/Reisepass bewusst
        // ausgeschlossen (eigene Typen).
        if (!str_contains($upper, 'AUFENTHALTSTITEL') && !str_contains($upper, 'AUFENTHALTSERLAUBNIS')
            && !str_contains($upper, 'RESIDENCE PERMIT')) {
            return null;
        }

        $raw = $this->frontNames();

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

    /**
     * RUECKSEITE der Karte: Personendaten aus der TD1-MRZ (drei Zeilen a 30
     * Zeichen, mit Pruefziffern), Anschrift und Geburtsort aus den
     * Klartext-Bloecken. Fehlen Felder (z.B. beim kombinierten Vorder-/
     * Rueckseiten-Scan), ergaenzen die Vorderseiten-Beschriftungen.
     */
    private function parseBackSide(): ?array
    {
        $mrz = $this->td1Fields();
        if ($mrz === null) {
            return null; // MRZ vorhanden, aber nicht sicher dekodierbar -> KI.
        }

        $raw = $mrz;

        // Kombinierter Scan: fehlende Felder aus den Vorderseiten-
        // Beschriftungen ergaenzen (nie MRZ-Werte ueberschreiben).
        foreach ($this->frontNames() as $k => $v) {
            $raw[$k] ??= $v;
        }
        $aux = [];
        $this->fillSexNationalityBirth($aux);
        foreach ($aux as $k => $v) {
            $raw[$k] ??= $v;
        }

        // Anschrift-Aufkleber ("Anschrift/Address/Adresse": PLZ Ort und
        // Strasse Hausnummer) + Geburtsort ("GEBURTSORT/PLACE OF BIRTH").
        $raw = [...$raw, ...$this->backAddress()];
        $raw['birth_place'] ??= $this->backBirthPlace();

        $expiry = $raw['card_expiry'] ?? null;
        unset($raw['card_expiry']);

        $person = $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
        if (($person['last_name'] ?? null) === null && ($person['first_name'] ?? null) === null) {
            return null; // Ohne belastbaren Namen der normalen Analyse/KI ueberlassen.
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $address = trim(
            (($person['street'] ?? '') !== '' ? trim(($person['street'] ?? '') . ' ' . ($person['house_number'] ?? '')) . ', ' : '')
            . trim(($person['zip'] ?? '') . ' ' . ($person['city'] ?? ''))
        );
        $address = trim($address, ', ');

        return [
            'type' => 'aufenthaltstitel',
            'confidence' => 76,
            'summary' => 'Aufenthaltstitel (Rueckseite, maschinenlesbare Zone gelesen)'
                . ($name !== '' ? ' - ' . $name : '')
                . (isset($person['nationality']) ? ' - Staatsangehoerigkeit ' . $person['nationality'] : '')
                . ($expiry !== null ? ' - gueltig bis ' . $expiry : '')
                . ($address !== '' ? ' - Anschrift ' . $address : '')
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

    /**
     * Anzahl der TD1-MRZ-Datenzeilen (Geburtsdatum+Pruefziffer+Geschlecht+
     * Ablauf+Pruefziffer+Staatsangehoerigkeit) im Text - Basis fuer die
     * Rueckseiten-Erkennung und den Mehr-Karten-Schutz.
     */
    private function td1DataLineCount(): int
    {
        $count = 0;
        foreach ($this->mrzLines() as $line) {
            if (preg_match('/^\d{6}\d[MFX<]\d{6}\d[A-Z]{3}[A-Z0-9<]*$/', $line)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Dekodiert die TD1-MRZ der Rueckseite (Pruefziffern-validiert):
     *   Zeile 1: AR + Ausstellerstaat + Dokumentennummer(9) + Pruefziffer
     *   Zeile 2: Geburt(6) Pruef Geschlecht Ablauf(6) Pruef Staat(3)
     *   Zeile 3: NACHNAME<<VORNAMEN
     *
     * @return array<string,mixed>|null null, wenn die Datenzeile fehlt.
     */
    private function td1Fields(): ?array
    {
        $lines = $this->mrzLines();

        $dataIdx = null;
        $data = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^(\d{6})(\d)([MFX<])(\d{6})(\d)([A-Z]{3})[A-Z0-9<]*$/', $line, $m)) {
                $dataIdx = $i;
                $data = $m;
                break;
            }
        }
        if ($dataIdx === null) {
            return null;
        }

        $raw = [];

        // Geburtsdatum/Ablauf nur mit stimmiger Pruefziffer uebernehmen -
        // ein OCR-Zahlendreher darf nie ein falsches Datum erzeugen.
        if ($this->mrzCheckDigit($data[1]) === (int) $data[2]) {
            $raw['birth_date'] = $this->mrzDate($data[1], false);
        }
        $raw['gender'] = match ($data[3]) {
            'M' => 'male',
            'F' => 'female',
            default => null,
        };
        if ($this->mrzCheckDigit($data[4]) === (int) $data[5]) {
            $raw['card_expiry'] = $this->displayDate($this->mrzDate($data[4], true));
        }
        $raw['nationality'] = $this->nationality($data[6]);

        // Zeile 1 (davor): "AR" + Ausstellerstaat + Dokumentennummer + Pruefziffer.
        foreach ($lines as $line) {
            if (preg_match('/^AR([A-Z]{1,3})<{0,2}([A-Z0-9]{9})(\d)/', $line, $m)
                && $this->mrzCheckDigit($m[2]) === (int) $m[3]) {
                $raw['id_number'] = $m[2];
                break;
            }
        }

        // Zeile 3 (danach): NACHNAME<<VORNAMEN ("<" als Fueller).
        foreach ($lines as $j => $line) {
            if ($j <= $dataIdx || !str_contains($line, '<<') || !preg_match('/^[A-Z<]+$/', $line)) {
                continue;
            }
            $parts = preg_split('/<</', $line, 2) ?: [];
            $surname = $this->mrzName($parts[0] ?? '');
            $given = $this->mrzName($parts[1] ?? '');
            if ($surname !== '') {
                $raw['last_name'] = $surname;
            }
            if ($given !== '') {
                $raw['first_name'] = $given;
            }
            break;
        }

        return array_filter($raw, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * MRZ-taugliche Zeilen: Leerraum entfernt, Grossschreibung, nur
     * A-Z/0-9/< und ausreichend lang. Die Original-Zeilenindexe bleiben als
     * Schluessel erhalten (fuer die Reihenfolge Zeile 2 -> Zeile 3).
     *
     * @return array<int,string>
     */
    private function mrzLines(): array
    {
        $out = [];
        foreach ($this->lines as $i => $line) {
            $c = strtoupper((string) preg_replace('/\s+/', '', $line));
            if (mb_strlen($c) >= 24 && preg_match('/^[A-Z0-9<]+$/', $c)) {
                $out[$i] = $c;
            }
        }
        return $out;
    }

    /** ICAO-9303-Pruefziffer (Gewichte 7/3/1; "<"=0, A=10 ... Z=35). */
    private function mrzCheckDigit(string $value): int
    {
        $weights = [7, 3, 1];
        $sum = 0;
        foreach (str_split($value) as $i => $ch) {
            $v = match (true) {
                $ch === '<' => 0,
                ctype_digit($ch) => (int) $ch,
                default => ord($ch) - ord('A') + 10,
            };
            $sum += $v * $weights[$i % 3];
        }
        return $sum % 10;
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

    /** MRZ-Namensteil ("ALALI", "SAFA<PETER") -> "Alali", "Safa Peter". */
    private function mrzName(string $part): string
    {
        $part = trim(str_replace('<', ' ', $part));
        $part = (string) preg_replace('/\s+/', ' ', $part);
        return $part === '' ? '' : mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
    }

    private function displayDate(?string $iso): ?string
    {
        if ($iso === null) {
            return null;
        }
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) ? $m[3] . '.' . $m[2] . '.' . $m[1] : $iso;
    }

    /**
     * Anschrift-Aufkleber der Rueckseite: nach der Beschriftung "Anschrift/
     * Address/Adresse" folgen "PLZ Ort" und "Strasse Hausnummer" (in
     * beliebiger Reihenfolge). Werte rechts einer breiten Spaltenluecke
     * (z.B. die Dokumentennummer daneben) werden abgeschnitten.
     *
     * @return array<string,string>
     */
    private function backAddress(): array
    {
        $idx = $this->lineIndex('/\bAnschrift\b|\bAdresse\b/iu');
        if ($idx === null) {
            return [];
        }

        $out = [];
        foreach ($this->nextNonEmpty($idx, 3) as $value) {
            // Nur die eigene Spalte betrachten (vor einer 2+-Leerzeichen-Luecke).
            foreach (preg_split('/\s{2,}/', $value) ?: [] as $col) {
                $col = trim($col);
                if (!isset($out['zip']) && preg_match('/^(\d{5})\s+(\p{Lu}[\p{L}.\-]*(?:\s\p{L}[\p{L}.\-]+)*)$/u', $col, $m)) {
                    $out['zip'] = $m[1];
                    $out['city'] = $m[2];
                } elseif (!isset($out['street']) && preg_match('/^(\p{Lu}[\p{L}.\-]*(?:\s\p{L}[\p{L}.\-]+)*)\s+(\d{1,4}(?:\s?[a-zA-Z])?)$/u', $col, $m)) {
                    $out['street'] = $m[1];
                    $out['house_number'] = trim($m[2]);
                }
            }
        }
        // Nur uebernehmen, wenn wenigstens PLZ+Ort ODER Strasse sicher sind.
        return (isset($out['zip']) || isset($out['street'])) ? $out : [];
    }

    /**
     * Geburtsort ("3. GEBURTSORT/PLACE OF BIRTH"): der Wert steht auf der
     * Karte UNTER der Beschriftung, in der OCR-Ausgabe je nach Layout aber
     * an unterschiedlichen Stellen - hinter der Beschriftung in derselben
     * Zeile, in derselben Spalte der Folgezeile, oder (wenn die OCR die
     * Spalten mit nur EINEM Leerzeichen verschmilzt) hinter dem
     * Anmerkungs-Text ("ERWERBSTAETIGKEIT ERLAUBT DEIR EZZOR"). Alle
     * Kandidaten durchlaufen dieselbe Bereinigung; unsichere Treffer
     * bleiben leer.
     */
    private function backBirthPlace(): ?string
    {
        $idx = $this->lineIndex('/GEBURTSORT|PLACE OF BIRTH/i');
        if ($idx === null) {
            return null;
        }

        $candidates = [];

        // 1) Wert hinter der LETZTEN Beschriftung in derselben Zeile
        //    ("... GEBURTSORT/PLACE OF BIRTH DEIR EZZOR").
        if (preg_match('/^.*(?:GEBURTSORT|BIRTH)\b[\s\/.:]*([\p{Lu}][\p{Lu} \-\.]{1,40})$/u', trim($this->lines[$idx]), $m)) {
            $candidates[] = $m[1];
        }

        // 2) Folgezeilen: bevorzugt dieselbe Spalte wie die Beschriftung,
        //    danach alle uebrigen Spalten (die Bereinigung sortiert
        //    Anmerkungs-/Merkmalstexte aus).
        $labelCols = preg_split('/\s{2,}/', trim($this->lines[$idx])) ?: [];
        $labelPos = null;
        foreach ($labelCols as $i => $col) {
            if (preg_match('/GEBURTSORT|PLACE OF BIRTH/i', $col)) {
                $labelPos = $i;
                break;
            }
        }
        foreach ($this->nextNonEmpty($idx, 2) as $value) {
            $cols = array_map('trim', preg_split('/\s{2,}/', trim($value)) ?: []);
            if ($labelPos !== null && isset($cols[$labelPos])) {
                $candidates[] = $cols[$labelPos];
            }
            foreach ($cols as $col) {
                $candidates[] = $col;
            }
        }

        foreach ($candidates as $candidate) {
            $place = $this->cleanBirthPlace($candidate);
            if ($place !== null) {
                return $place;
            }
        }
        return null;
    }

    /**
     * Kandidat zu einem plausiblen Geburtsort bereinigen. Bekannte
     * Anmerkungs-Phrasen der Rueckseite ("ERWERBSTAETIGKEIT ERLAUBT",
     * "SIEHE ZUSATZBLATT") werden vorn abgeschnitten - die OCR verschmilzt
     * die Spalten teils mit nur einem Leerzeichen. Bleibt danach kein
     * reiner Ortsname (Grossbuchstaben der Karte) uebrig oder enthaelt er
     * ein Beschriftungs-/Merkmalswort, wird der Kandidat verworfen.
     */
    private function cleanBirthPlace(string $candidate): ?string
    {
        $candidate = trim($candidate);
        $candidate = (string) preg_replace(
            '/\b(?:ERWERBST\p{Lu}*|BESCH\p{Lu}*FTIGUNG|T\p{Lu}*TIGKEIT|NICHT|ERLAUBT|GESTATTET|SIEHE|ZUSATZBLATT|ZUSATZ|BLATT)\b[\s\-]*/u',
            ' ',
            $candidate
        );
        $candidate = trim((string) preg_replace('/\s+/', ' ', $candidate));

        if (!preg_match('/^\p{Lu}[\p{Lu} \-\.]{1,40}$/u', $candidate)) {
            return null;
        }
        // Beschriftungen und Merkmalswerte der Karte sind kein Ortsname.
        if (preg_match('/ANMERKUNG|REMARK|GEBURTSORT|BIRTH|PLACE|AUGENFARBE|EYE|COLOU?R|BRAUN|BLAU|GR[UÜ]N|GRUEN|GRAU|SCHWARZ|GR[OÖ]SSE|GROESSE|HEIGHT|AUSSTELLUNG|BEH[OÖ]RDE|BEHOERDE|DATE|ISSUE|AUTHORITY|ANSCHRIFT|ADDRESS|ADRESSE|BUNDESDRUCKEREI|KARTE|SIEGEL/iu', $candidate)) {
            return null;
        }
        return mb_convert_case($candidate, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Vorderseite: Nachname (NAMEN/SURNAMES) in der Zeile unter der
     * Beschriftung, Vorname(n) (Forenames) in der Zeile darunter.
     *
     * @return array<string,string>
     */
    private function frontNames(): array
    {
        $raw = [];
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
        return $raw;
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
