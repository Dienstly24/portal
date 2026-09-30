<?php

namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\LiestDeutscheAusweiskarte;
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
 * Die Rueckseite teilt sich den Baustein `LiestDeutscheAusweiskarte` mit dem
 * Personalausweis (baugleiche MRZ und Anschrift-Aufkleber); traegt die MRZ
 * "ID" statt "AR", ueberlaesst dieser Parser die Karte dem
 * `PersonalausweisParser`.
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
    use LiestDeutscheAusweiskarte;
    use ValidatesExtractedFields;

    public function parse(string $text): ?array
    {
        $this->zeilenSetzen($text);
        $upper = mb_strtoupper($this->text());

        // Mehrere Karten auf einem Bild (Familie) -> der KI-Vision ueberlassen,
        // die jede Person korrekt zuordnet. Erkennung an mehrfach auftretenden
        // Kartenmarkern (Vorderseite) bzw. MRZ-Bloecken (Rueckseite).
        if ($this->td1DataLineCount() > 1 || $this->markerCount($upper) > 1) {
            return null;
        }

        // RUECKSEITE: sie traegt keine Vorderseiten-Beschriftungen, aber die
        // maschinenlesbare Zone (TD1-MRZ) - sie ist die verlaesslichste Quelle
        // und gewinnt auch bei einem kombinierten Vorder-/Rueckseiten-Scan.
        $rueckseite = $this->rueckseitenTyp();
        if ($rueckseite === 'aufenthaltstitel') {
            return $this->parseRueckseite('aufenthaltstitel');
        }
        if ($rueckseite !== null) {
            return null; // Personalausweis - eigener Parser.
        }

        // Nur der Aufenthaltstitel (eAT). Personalausweis/Reisepass bewusst
        // ausgeschlossen (eigene Typen).
        if (! str_contains($upper, 'AUFENTHALTSTITEL') && ! str_contains($upper, 'AUFENTHALTSERLAUBNIS')
            && ! str_contains($upper, 'RESIDENCE PERMIT')) {
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

        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        $expiry = $this->expiryDate();

        return [
            'type' => 'aufenthaltstitel',
            'confidence' => 68,
            'summary' => 'Aufenthaltstitel (Aufenthaltserlaubnis)'
                .($name !== '' ? ' - '.$name : '')
                .(isset($person['nationality']) ? ' - Staatsangehoerigkeit '.$person['nationality'] : '')
                .($expiry !== null ? ' - gueltig bis '.$expiry : '')
                .' - Felder gratis aus der Karte gelesen (ohne KI).'
                .' Anschrift und Geburtsort stehen auf der RUECKSEITE - bitte ebenfalls hochladen.',
            'title' => 'Aufenthaltstitel'.($name !== '' ? ' '.$name : ''),
            'data' => $this->kartenDaten($person),
        ];
    }

    /** Vorderseite im selben Bild (kombinierter Scan) ergaenzt die Rueckseite. */
    protected function vorderseitenFelder(): array
    {
        $raw = $this->frontNames();
        $this->fillSexNationalityBirth($raw);

        return $raw;
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
                $raw['birth_date'] = $m[5].'-'.$m[4].'-'.$m[3];
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
                    $raw['birth_date'] = $m[3].'-'.$m[2].'-'.$m[1];
                    break;
                }
            }
        }
        if (! isset($raw['gender'])) {
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
                    return $m[1].'.'.$m[2].'.'.$m[3];
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
}
