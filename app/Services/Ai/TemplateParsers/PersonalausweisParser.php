<?php

namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\LiestDeutscheAusweiskarte;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer den deutschen PERSONALAUSWEIS (Scheckkartenformat) aus
 * der OCR-Textebene eines einzelnen Kartenfotos - VORDER- und RUECKSEITE
 * (Betreiber-Auftrag 30.09.2026: "vom Ausweis brauchen wir vor allem die
 * Anschrift und den Geburtsort"). Bis dahin gab es fuer den Personalausweis
 * GAR keinen Parser - jedes Foto ging an die Heuristik oder die bezahlte KI.
 *
 * Die beiden Angaben stehen auf VERSCHIEDENEN Seiten, anders als beim
 * Aufenthaltstitel:
 * - VORDERSEITE: Name, Geburtsname, Vornamen, Geburtsdatum,
 *   Staatsangehoerigkeit, GEBURTSORT, Gueltig bis, Dokumentennummer.
 * - RUECKSEITE: ANSCHRIFT (Aufkleber bzw. Druck), Augenfarbe, Groesse,
 *   Behoerde und die maschinenlesbare Zone (TD1, Zeile 1 beginnt mit "IDD").
 *   Sie ist baugleich mit der Rueckseite des Aufenthaltstitels, deshalb der
 *   gemeinsame Baustein `LiestDeutscheAusweiskarte`.
 *
 * Die Anschrift der ausstellenden BEHOERDE ("STADT RENDSBURG / DIE
 * BUERGERMEISTERIN") wird NIE die Anschrift der Person - gelesen wird nur
 * der Block unter "Anschrift".
 *
 * Bewusst NUR fuer eine EINZELNE Karte; mehrere Karten auf einem Bild
 * gehen an die KI-Vision.
 */
class PersonalausweisParser implements DocumentTemplateParser
{
    use LiestDeutscheAusweiskarte;
    use ValidatesExtractedFields;

    public function parse(string $text): ?array
    {
        $this->zeilenSetzen($text);
        $upper = mb_strtoupper($this->text());

        if ($this->td1DataLineCount() > 1 || substr_count($upper, 'PERSONALAUSWEIS') > 1) {
            return null;
        }

        $rueckseite = $this->rueckseitenTyp();
        if ($rueckseite === 'personalausweis') {
            return $this->parseRueckseite('personalausweis');
        }
        if ($rueckseite !== null) {
            return null; // Aufenthaltstitel - eigener Parser.
        }

        // Vorderseite. Ein Aufenthaltstitel traegt ebenfalls "IDENTITY"-
        // aehnliche Beschriftungen nicht, aber sicherheitshalber ausschliessen.
        if ((! str_contains($upper, 'PERSONALAUSWEIS') && ! str_contains($upper, 'IDENTITY CARD'))
            || str_contains($upper, 'AUFENTHALT')) {
            return null;
        }

        $raw = $this->vorderseitenFelder();
        $person = $this->validatedPerson(array_filter($raw, fn (string $v) => $v !== ''));
        if (($person['last_name'] ?? null) === null && ($person['first_name'] ?? null) === null) {
            return null; // Ohne belastbaren Namen der normalen Analyse/KI ueberlassen.
        }

        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        $expiry = $this->datumNach('/G[ÜU]LTIG BIS|DATE OF EXPIRY/iu');
        $geburtsname = $this->geburtsname();

        return [
            'type' => 'personalausweis',
            'confidence' => 68,
            'summary' => 'Personalausweis'
                .($name !== '' ? ' - '.$name : '')
                .($geburtsname !== null ? ' - Geburtsname '.$geburtsname : '')
                .(isset($person['birth_place']) ? ' - Geburtsort '.$person['birth_place'] : '')
                .($expiry !== null ? ' - gueltig bis '.$expiry : '')
                .' - Felder gratis aus der Karte gelesen (ohne KI).'
                .' Die Anschrift steht auf der RUECKSEITE - bitte ebenfalls hochladen.',
            'title' => 'Personalausweis'.($name !== '' ? ' '.$name : ''),
            'data' => $this->kartenDaten($person),
            // Die Vorderseite wird fuer den GEBURTSORT gebraucht - fehlt er,
            // liest die KI das Bild (DocumentAnalyzer).
            'pflichtangaben' => ['birth_place'],
        ];
    }

    /**
     * Felder der Vorderseite. Beschriftung oben, Wert darunter; Geburtsdatum
     * und Staatsangehoerigkeit teilen sich oft eine Zeile ("12.08.1983
     * DEUTSCH") - deshalb wird nach der FORM des Wertes gesucht.
     *
     * @return array<string,string>
     */
    protected function vorderseitenFelder(): array
    {
        $raw = [];

        $idx = $this->lineIndex('/(?<!GEBURTS)(?<!GEBURTS )NAME\s*\/\s*SURNAME|^\s*NAME\s*\/|\bSURNAME\b/iu');
        if ($idx !== null && ($wert = $this->wertUnter($idx)) !== null) {
            $raw['last_name'] = $this->normalizeName($wert);
        }

        $idx = $this->lineIndex('/VORNAMEN|GIVEN NAMES/iu');
        if ($idx !== null && ($wert = $this->wertUnter($idx)) !== null) {
            $raw['first_name'] = $this->normalizeName($wert);
        }

        $geburt = $this->datumNach('/GEBURTSDATUM|GEBURTSTAG|DATE OF BIRTH/iu');
        if ($geburt !== null && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $geburt, $m)) {
            $raw['birth_date'] = $m[3].'-'.$m[2].'-'.$m[1];
        }

        if (preg_match('/\bDEUTSCH\b/u', mb_strtoupper($this->text()))) {
            $raw['nationality'] = 'Deutschland';
        }

        if (($ort = $this->backBirthPlace()) !== null) {
            $raw['birth_place'] = $ort;
        }

        // Dokumentennummer (oben rechts). Deutscher Zeichenvorrat: Ziffern
        // und C F G H J K L M N P R T V W X Y Z - ohne Vokale, deshalb
        // trifft das Muster kein gewoehnliches Wort.
        if (preg_match('/\b(?=[A-Z0-9]*\d)([CFGHJKLMNPRTVWXYZ][CFGHJKLMNPRTVWXYZ0-9]{8})\b/', $this->text(), $m)) {
            $raw['id_number'] = $m[1];
        }

        return $raw;
    }

    /**
     * Wert in der Zeile unter einer Beschriftung: die erste Spalte der
     * naechsten Zeile, die selbst keine Beschriftung ist.
     */
    private function wertUnter(int $idx): ?string
    {
        foreach ($this->nextNonEmpty($idx, 2) as $zeile) {
            $spalte = trim((preg_split('/\h{2,}/u', $zeile) ?: [$zeile])[0]);
            if (str_contains($spalte, '/') || preg_match('/NAME|GEBURT|BIRTH|DATUM|STAATS|NATIONAL/iu', $spalte)) {
                continue; // Beschriftung, kein Wert.
            }
            if ($this->looksLikeName($spalte) && mb_strtoupper($spalte) === $spalte) {
                return $spalte; // Werte druckt die Karte in Grossbuchstaben.
            }

            return null;
        }

        return null;
    }

    private function geburtsname(): ?string
    {
        $idx = $this->lineIndex('/GEBURTSNAME|NAME AT BIRTH/iu');

        return $idx !== null && ($wert = $this->wertUnter($idx)) !== null ? $this->normalizeName($wert) : null;
    }

    /** Erstes Datum TT.MM.JJJJ in den zwei Zeilen unter einer Beschriftung. */
    private function datumNach(string $muster): ?string
    {
        $idx = $this->lineIndex($muster);
        if ($idx === null) {
            return null;
        }
        foreach ([$this->lines[$idx], ...$this->nextNonEmpty($idx, 2)] as $zeile) {
            if (preg_match('/\b(\d{2})[ .](\d{2})[ .](\d{4})\b/', $zeile, $m)) {
                return $m[1].'.'.$m[2].'.'.$m[3];
            }
        }

        return null;
    }
}
