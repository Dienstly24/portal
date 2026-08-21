<?php
namespace App\Services\Ai\Concerns;

/**
 * Gemeinsame Reparaturen fuer TEXT AUS DER OCR.
 *
 * Lehre (21.08.2026, gemeldet an einem hochgeladenen Kontakt-Screenshot):
 * derselbe Notizzettel wurde einmal erkannt und einmal nicht - der Unterschied
 * lag nur in der Darstellung (heller Hintergrund, farbige/unterstrichene
 * Links). Fuer die OCR sind das andere Bilder, und EIN verlesenes Zeichen
 * reichte, damit ein Parser mit harten Mustern ("DE" + genau 20 Ziffern,
 * E-Mail mit Punkt) gar nichts mehr fand und das Dokument als "Sonstiges"
 * liegen blieb.
 *
 * Deshalb hier die zwei Felder, an denen es typischerweise scheitert - und
 * beide mit einer PRUEFUNG statt mit blossem Raten:
 *  - IBAN: bekannte Zeichenverwechslungen werden zurueckgesetzt, uebernommen
 *    wird der Wert NUR, wenn danach die Pruefziffer (Modulo 97) stimmt. Eine
 *    falsch geratene Bankverbindung kann so nicht entstehen.
 *  - E-Mail: nur Schreibweisen, die eindeutig aus der Erkennung stammen
 *    ("(at)", fehlender Punkt vor einer bekannten Endung).
 */
trait RepairsOcrText
{
    /** Zeichen, die die OCR in Ziffernfeldern regelmaessig verwechselt. */
    private const OCR_ZIFFERN = [
        'O' => '0', 'o' => '0', 'Q' => '0', 'D' => '0',
        'I' => '1', 'l' => '1', 'i' => '1', '|' => '1', '/' => '1',
        'Z' => '2', 'z' => '2', 'E' => '3', 'A' => '4',
        'S' => '5', 's' => '5', 'G' => '6', 'b' => '6',
        'T' => '7', 'B' => '8', 'g' => '9', 'q' => '9',
    ];

    /**
     * Erste deutsche IBAN im Text - auch wenn die OCR Zeichen verlesen hat
     * ("DE68 65O5 O110 ..."). Uebernommen wird ausschliesslich ein Wert mit
     * GUELTIGER Pruefziffer; sonst null (lieber keine IBAN als eine falsche).
     */
    protected function ocrGermanIban(string $text): ?string
    {
        // Zweiter Durchgang mit zusammengezogenen Zeilenumbruechen: eine ueber
        // zwei Zeilen umgebrochene IBAN faellt sonst durch.
        $kandidaten = [];
        foreach ([$text, (string) preg_replace('/\s+/u', ' ', $text)] as $variante) {
            // "DE" wird gelegentlich als "0E"/"OE"/"D3" gelesen; danach 20
            // Zeichen, die Ziffern oder typische Verwechslungen sein koennen.
            if (preg_match_all('/[D0O][E3][0-9A-Za-z|\/ ]{20,44}/u', $variante, $treffer)) {
                $kandidaten = [...$kandidaten, ...$treffer[0]];
            }
        }

        foreach ($kandidaten as $kandidat) {
            $kompakt = (string) preg_replace('/\s+/u', '', $kandidat);
            if (mb_strlen($kompakt) < 22) {
                continue;
            }
            $iban = 'DE' . strtr(mb_substr($kompakt, 2, 20), self::OCR_ZIFFERN);
            if (preg_match('/^DE\d{20}$/', $iban) && $this->ibanChecksumValid($iban)) {
                return $iban;
            }
        }

        return null;
    }

    /**
     * IBAN-Pruefziffer nach ISO 7064 (Modulo 97): die ersten vier Zeichen
     * wandern ans Ende, Buchstaben werden zu Zahlen (A=10 ... Z=35), der Rest
     * der Division durch 97 muss 1 sein.
     */
    protected function ibanChecksumValid(string $iban): bool
    {
        $umgestellt = mb_substr($iban, 4) . mb_substr($iban, 0, 4);
        $zahl = '';
        foreach (str_split($umgestellt) as $zeichen) {
            $zahl .= ctype_alpha($zeichen) ? (string) (ord(strtoupper($zeichen)) - 55) : $zeichen;
        }
        $rest = 0;
        foreach (str_split($zahl, 7) as $block) {
            $rest = (int) ((string) $rest . $block) % 97;
        }

        return $rest === 1;
    }

    /**
     * Erste E-Mail-Adresse im Text. Zusaetzlich zur normalen Schreibweise
     * werden zwei Erkennungsfehler abgefangen, die bei Screenshots haeufig
     * sind: das "@" als "(at)"/"©" gelesen und der fehlende Punkt vor einer
     * bekannten Endung ("name@gmail com" / "name@gmailcom").
     */
    protected function ocrEmail(string $text): ?string
    {
        // Das "@" verliest die OCR bei Screenshots regelmaessig als "©"/"®"
        // (oder verdoppelt es, "@®" -> "@@"); "(at)" kommt aus abgetippten
        // Adressen. Mehrere "@" hintereinander werden wieder zu einem.
        $text = (string) preg_replace('/\s*(?:\(at\)|\[at\]|\{at\}|[©®]|@)\s*/iu', '@', $text);
        $text = (string) preg_replace('/@{2,}/u', '@', $text);

        if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9\-]+(?:\.[a-z0-9\-]+)*\.[a-z]{2,}/i', $text, $m)) {
            return mb_strtolower(rtrim($m[0], '.'));
        }
        if (preg_match('/([a-z0-9._%+\-]+)@([a-z0-9.\-]*?)[ .]?(com|de|net|org|eu|info)\b/i', $text, $m)
            && $m[2] !== '') {
            return mb_strtolower($m[1] . '@' . rtrim($m[2], '.') . '.' . $m[3]);
        }

        return null;
    }
}
