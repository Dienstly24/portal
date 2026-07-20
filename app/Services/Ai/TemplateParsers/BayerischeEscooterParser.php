<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Support\EscooterInsurance;

/**
 * Gratis-Parser (ohne KI) fuer die Abschlussbestaetigung der E-Scooter-
 * Versicherung ("die Bayerische" und baugleiche GDV-E-Scooter-Policen). Diese
 * Bestaetigung ist immer gleich aufgebaut ("Die wichtigsten Details fuer Sie
 * im Ueberblick", "Sie haben die Bayerische E-Scooter-Versicherung
 * abgeschlossen") und traegt alle Vertragskerndaten:
 *
 *   Tarifname, Versicherungsbeginn/-ende, Hersteller/Modellbezeichnung,
 *   Fahrgestellnummer (FIN), Versicherungskennzeichen, Einmaliger Beitrag,
 *   Versicherungsnehmer (Anschrift, Geburtsdatum, E-Mail) und die
 *   Zahlungsangaben (Kontoinhaber, IBAN).
 *
 * So legt der Dokumenten-Eingang den E-Scooter-Vertrag samt Fahrzeug- und
 * Bankdaten automatisch an, ohne den (teuren) KI-Anbieter zu bemuehen. Der
 * Ablauf wird nicht dem Text vertraut, sondern zentral ueber die Fachregel
 * (Ende Februar der Saison) gesetzt (siehe Contract::saving / EscooterInsurance).
 *
 * Zwei-Spalten-Layout: `pdftotext -layout` trennt die Spalten mit mehreren
 * Leerzeichen. Werte werden daher entweder ueber die Naehe zur Beschriftung
 * (Datum/Betrag) oder ueber die linke Spalte der Folgezeile gelesen; alle
 * Treffer durchlaufen die harte Feldvalidierung.
 */
class BayerischeEscooterParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    public function parse(string $text): ?array
    {
        // Weiche Trennzeichen entfernen, damit umbrochene Woerter zusammenfinden.
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $upper = mb_strtoupper($text);

        if (!$this->looksLikeEscooterConfirmation($upper)) {
            return null;
        }

        $lines = array_map('rtrim', preg_split('/\R/', $text) ?: []);

        $vehicle = $this->parseVehicle($text, $lines);
        $insurance = $this->parseInsurance($text, $lines, $upper);
        $person = $this->parsePerson($lines);
        $bank = $this->parseBank($text, $lines, $person);

        // Ohne belastbaren Kern (Fahrzeug- oder Vertragsdaten) lieber der
        // normalen Analyse ueberlassen.
        if (($vehicle['vin'] ?? null) === null
            && ($vehicle['license_plate'] ?? null) === null
            && ($insurance['start_date'] ?? null) === null
            && ($insurance['premium_amount'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $plate = $vehicle['license_plate'] ?? null;

        return [
            'type' => 'escooter_vertrag',
            'confidence' => 78,
            'summary' => 'E-Scooter-Versicherung' . (($insurance['insurer'] ?? null) ? ' (' . $insurance['insurer'] . ')' : '')
                . ($name !== '' ? ' - ' . $name : '')
                . ($plate !== null ? ' - ' . $plate : '')
                . ' - Felder gratis aus dem Dokument gelesen (ohne KI).',
            'title' => trim('E-Scooter-Versicherung ' . $name),
            'data' => [
                'person' => $person,
                'versicherung' => $insurance,
                'kfz' => $vehicle,
                'gesundheit' => [],
                'bank' => $bank,
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /** Sichere Erkennung: E-Scooter-Bezug + mindestens zwei der festen Felder. */
    private function looksLikeEscooterConfirmation(string $upper): bool
    {
        $hasScooter = str_contains($upper, 'E-SCOOTER') || str_contains($upper, 'ESCOOTER');
        if (!$hasScooter) {
            return false;
        }
        $signals = 0;
        foreach (['EINMALIGER BEITRAG', 'FAHRGESTELLNUMMER', 'VERSICHERUNGSBEGINN', 'VERSICHERUNGSENDE', 'TARIFNAME'] as $needle) {
            if (str_contains($upper, $needle)) {
                $signals++;
            }
        }
        return $signals >= 2;
    }

    /** @return array<string,mixed> */
    private function parseVehicle(string $text, array $lines): array
    {
        $raw = [];

        // Fahrgestellnummer (FIN): direkt hinter der Beschriftung, 11-17
        // Zeichen mit mindestens einem Buchstaben UND einer Ziffer.
        $vinWindow = $this->windowAfter($text, 'Fahrgestellnummer', 120)
            ?? $this->windowAfter($text, 'Fahrzeug-Identifizierungsnummer', 120);
        if ($vinWindow !== null && preg_match_all('/\b([A-Z0-9]{11,17})\b/u', mb_strtoupper($vinWindow), $mm)) {
            foreach ($mm[1] as $candidate) {
                if (preg_match('/[A-Z]/', $candidate) && preg_match('/\d/', $candidate)) {
                    $raw['vin'] = $candidate;
                    break;
                }
            }
        }

        // Versicherungskennzeichen (E-Scooter): 3 Ziffern + 3 Buchstaben
        // ("611 MDS"), gesucht im Fenster hinter "Kennzeichen".
        $plateWindow = $this->windowAfter($text, 'Kennzeichen', 120);
        if ($plateWindow !== null && preg_match('/(\d{3})\s*([A-Za-zÄÖÜ]{3})/u', $plateWindow, $m)) {
            $raw['license_plate'] = mb_strtoupper($m[1] . $m[2]);
        }

        // Hersteller/Modellbezeichnung -> als Fahrzeugbezeichnung (Hersteller).
        $model = $this->valueBelow($lines, 'Modellbezeichnung') ?? $this->valueBelow($lines, 'Hersteller');
        if ($model !== null) {
            $raw['manufacturer'] = $model;
        }

        // Deckung: E-Scooter ist Haftpflicht ODER Teilkasko (nie Vollkasko).
        // Nur wenn der Tarif/das Dokument Teilkasko ausweist, Teilkasko setzen.
        $raw['has_teilkasko'] = (bool) preg_match('/teilkasko|teil-?kasko/i', $text);

        return $this->validatedVehicle(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseInsurance(string $text, array $lines, string $upper): array
    {
        $raw = ['sparte' => 'escooter', 'premium_interval' => 'einmalig'];

        // Versicherer: bei "die Bayerische" fest, sonst nichts erfinden.
        if (str_contains($upper, 'BAYERISCHE')) {
            $raw['insurer'] = 'die Bayerische';
        } else {
            $raw['insurer'] = $this->valueBelow($lines, 'Versicherer') ?? $this->valueBelow($lines, 'Gesellschaft');
        }

        // Tarifname (z.B. "Haftpflicht") als Anzeige-Info.
        $tarif = $this->valueBelow($lines, 'Tarifname');
        if ($tarif !== null) {
            $raw['tariff'] = $tarif;
        }

        $start = $this->dateAfter($text, 'Versicherungsbeginn');
        if ($start !== null) {
            $raw['start_date'] = $start;
            // Ablauf zwar aus dem Dokument lesen, aber die Fachregel gewinnt:
            // Ende der Saison (Ende Februar). Contract::saving erzwingt es
            // ohnehin nochmal - hier gesetzt, damit das Ergebnis konsistent ist.
            $raw['end_date'] = EscooterInsurance::seasonEndDate($start);
        }
        // Fallback: kein Beginn erkannt, aber ein Ende im Dokument.
        if (($raw['end_date'] ?? null) === null) {
            $end = $this->dateAfter($text, 'Versicherungsende');
            if ($end !== null) {
                $raw['end_date'] = $end;
            }
        }

        // Einmaliger Beitrag (z.B. "41,60 €").
        $amount = $this->amountAfter($text, 'Einmaliger Beitrag') ?? $this->amountAfter($text, 'Beitrag');
        if ($amount !== null) {
            $raw['premium_amount'] = $amount;
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Anschriftenblock hinter "Versicherungsnehmer": Anrede -> Name -> Strasse
     * -> PLZ Ort. Zusaetzlich Geburtsdatum und E-Mail (per Beschriftung/Muster).
     *
     * @return array<string,mixed>
     */
    private function parsePerson(array $lines): array
    {
        $raw = [];
        $idx = null;
        foreach ($lines as $i => $l) {
            if (mb_stripos($l, 'Versicherungsnehmer') !== false) {
                $idx = $i;
                break;
            }
        }
        if ($idx !== null) {
            $block = [];
            $inline = $this->afterColon($lines[$idx]);
            if ($inline !== null && $inline !== '') {
                $block[] = $inline;
            }
            for ($j = $idx + 1; $j < count($lines) && count($block) < 6; $j++) {
                $left = $this->leftColumn($lines[$j]);
                if ($left === '') {
                    continue;
                }
                if (preg_match('/^(Zahlungsangaben|Geburtsdatum|E-?Mail|IBAN|Kontoinhaber|Kontoverbindung)/iu', $left)) {
                    break;
                }
                $block[] = $left;
                if (preg_match('/^\d{5}\s+\S/u', $left)) {
                    break; // PLZ + Ort erreicht -> Adressblock vollstaendig
                }
            }
            $raw = $this->interpretPersonBlock($block);
        }

        // Geburtsdatum: erstes Datum hinter der Beschriftung.
        $birth = $this->dateAfter(implode("\n", $lines), 'Geburtsdatum');
        if ($birth !== null) {
            $raw['birth_date'] = $birth;
        }

        // E-Mail: eindeutiges Muster im gesamten Text.
        if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', implode("\n", $lines), $m)) {
            $raw['email'] = strtolower($m[0]);
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Zeilen des Anschriftenblocks nach Rolle einordnen (Anrede, Name, Strasse,
     * PLZ/Ort) - tolerant gegenueber kleinen Reihenfolge-Abweichungen.
     *
     * @param list<string> $block
     * @return array<string,mixed>
     */
    private function interpretPersonBlock(array $block): array
    {
        $raw = [];
        foreach ($block as $line) {
            $val = trim($line);
            if ($val === '') {
                continue;
            }
            // Anrede (evtl. mit direkt folgendem Namen: "Herr Ali Aliq").
            if (preg_match('/^(Herrn?|Frau)\b\s*(.*)$/u', $val, $m)) {
                $raw['gender'] = mb_strtolower($m[1]) === 'frau' ? 'female' : 'male';
                $rest = trim($m[2]);
                if ($rest !== '' && !isset($raw['last_name']) && $this->looksLikeName($rest)) {
                    $this->assignName($raw, $rest);
                }
                continue;
            }
            // PLZ + Ort.
            if (preg_match('/^(\d{5})\s+(.+)$/u', $val, $m)) {
                $raw['zip'] = $m[1];
                $raw['city'] = trim($m[2]);
                continue;
            }
            // Strasse + Hausnummer (enthaelt eine Ziffer).
            if (preg_match('/\d/', $val) && !isset($raw['street'])) {
                if (preg_match('/^(.*\D)\s*(\d+\s*[a-zA-Z]?)\s*$/u', $val, $s)) {
                    $raw['street'] = trim($s[1]);
                    $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
                } else {
                    $raw['street'] = $val;
                }
                continue;
            }
            // Name (nur Buchstaben, mindestens zwei Woerter).
            if (!isset($raw['last_name']) && $this->looksLikeName($val)) {
                $this->assignName($raw, $val);
            }
        }
        return $raw;
    }

    private function looksLikeName(string $val): bool
    {
        return (bool) preg_match('/^[A-ZÄÖÜ][\p{L}\-]+(?:\s+[A-ZÄÖÜ][\p{L}\-\']+)+$/u', $val);
    }

    private function assignName(array &$raw, string $val): void
    {
        $parts = preg_split('/\s+/', trim($val)) ?: [];
        $raw['last_name'] = array_pop($parts);
        $raw['first_name'] = implode(' ', $parts) ?: null;
    }

    /**
     * Zahlungsangaben: IBAN (eindeutiges Muster) und Kontoinhaber (Beschriftung,
     * ersatzweise der Name des Versicherungsnehmers).
     *
     * @return array<string,mixed>
     */
    private function parseBank(string $text, array $lines, array $person): array
    {
        $raw = [];
        if (preg_match('/\bIBAN\b[:\s]*([A-Z]{2}\d{2}(?:\s?[A-Z0-9]{2,4}){3,8})/iu', $text, $m)) {
            $raw['iban'] = strtoupper((string) preg_replace('/\s+/', '', $m[1]));
        } elseif (preg_match('/\b(DE\d{2}(?:\s?\d{4}){4}\s?\d{2})\b/u', $text, $m)) {
            $raw['iban'] = strtoupper((string) preg_replace('/\s+/', '', $m[1]));
        }

        $holder = $this->valueBelow($lines, 'Kontoinhaber');
        if ($holder !== null && $this->looksLikeName($holder)) {
            $raw['account_holder'] = $holder;
        } elseif (($person['first_name'] ?? null) || ($person['last_name'] ?? null)) {
            $raw['account_holder'] = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        }

        return $this->validatedBank(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    // ---- Textwerkzeuge -----------------------------------------------------

    /** Linke Spalte einer -layout-Zeile (erster Abschnitt vor >=2 Leerzeichen). */
    private function leftColumn(string $line): string
    {
        $parts = preg_split('/\s{2,}/', trim($line)) ?: [];
        return trim($parts[0] ?? '');
    }

    /** Inline-Wert hinter einem Doppelpunkt derselben Zeile (linke Spalte). */
    private function afterColon(string $line): ?string
    {
        $left = $this->leftColumn($line);
        if (str_contains($left, ':')) {
            $val = trim(substr($left, strpos($left, ':') + 1));
            return $val !== '' ? $val : null;
        }
        return null;
    }

    /**
     * Wert zu einer Beschriftung: Inline hinter dem Doppelpunkt, sonst linke
     * Spalte der naechsten nicht-leeren Zeile.
     */
    private function valueBelow(array $lines, string $needle): ?string
    {
        foreach ($lines as $i => $line) {
            if (mb_stripos($line, $needle) === false) {
                continue;
            }
            $inline = $this->afterColon($line);
            if ($inline !== null) {
                return $inline;
            }
            for ($j = $i + 1; $j < count($lines) && $j <= $i + 3; $j++) {
                $left = $this->leftColumn($lines[$j]);
                if ($left !== '') {
                    return $left;
                }
            }
            return null;
        }
        return null;
    }

    /** Textfenster ab (nach) der ersten Fundstelle einer Beschriftung. */
    private function windowAfter(string $text, string $needle, int $length): ?string
    {
        $pos = mb_stripos($text, $needle);
        if ($pos === false) {
            return null;
        }
        return mb_substr($text, $pos + mb_strlen($needle), $length);
    }

    /** Erstes deutsches Datum (TT.MM.JJJJ) hinter einer Beschriftung -> ISO. */
    private function dateAfter(string $text, string $needle): ?string
    {
        if (preg_match('/' . preg_quote($needle, '/') . '.*?(\d{2})\.(\d{2})\.(\d{4})/su', $text, $m)) {
            $iso = $m[3] . '-' . $m[2] . '-' . $m[1];
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? $iso : null;
        }
        return null;
    }

    /** Erster Geldbetrag (1.234,56) hinter einer Beschriftung als float. */
    private function amountAfter(string $text, string $needle): ?float
    {
        if (preg_match('/' . preg_quote($needle, '/') . '.*?(\d{1,3}(?:\.\d{3})*,\d{2})/su', $text, $m)) {
            return (float) str_replace(['.', ','], ['', '.'], $m[1]);
        }
        return null;
    }
}
