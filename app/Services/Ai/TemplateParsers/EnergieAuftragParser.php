<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Parser fuer den Strom-/Gas-Auftrag (Energie-Belieferungsauftrag), z.B. den
 * EWE-Auftrag "business Grünstrom". Der Betreiber braucht nur die ERSTE Seite
 * des Auftrags - dort stehen bereits alle Kern-Daten:
 *
 *   Kundendaten  : Name/Firma, Anschrift, Geburtsdatum, Telefon, E-Mail
 *   Bankdaten    : Kontoinhaber, IBAN
 *   Energiedaten : Tarif/Produkt, Zaehlernummer, Jahresverbrauch, Grundpreis
 *   Wechsel      : Neuer Anbieter (EWE) + derzeitiger (Vor-)Lieferant inkl.
 *                  dessen Kundennummer
 *
 * Besonderheit des Auftragslayouts: der WERT steht jeweils in der Zeile UEBER
 * dem Feld-Label ("41462  Neuss" ueber "PLZ  Ort"). Die Extraktion ankert
 * daher am Label und liest die Zeile darueber. Ergebnis: Typ 'energieauftrag'
 * (Sparte strom/gas). Alle Werte durchlaufen die harte Feldvalidierung.
 */
class EnergieAuftragParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    public function parse(string $text): ?array
    {
        $upper = mb_strtoupper($text);
        // Energie-Auftrag: Anbieter EWE + die energietypische Preis-Struktur
        // (Grundpreis + Arbeitspreis). Grenzt gegen DSL-/Versicherungs-
        // Auftraege ab (die diese Kombination nicht tragen).
        if (!str_contains($upper, 'EWE')
            || !str_contains($upper, 'GRUNDPREIS')
            || !str_contains($upper, 'ARBEITSPREIS')) {
            return null;
        }

        // Sparte am Auftragskopf entscheiden ("Auftrag für ... Grünstrom" =
        // Strom), NICHT an einzelnen Erwaehnungen in den AGB (dort kommen
        // "Strom" und "Gas" beide vor).
        $header = preg_match('/Auftrag für[^\r\n]*/u', $text, $hm) ? mb_strtoupper($hm[0]) : $upper;
        $isGas = (str_contains($header, 'GAS') || str_contains($header, 'ERDGAS'))
            && !str_contains($header, 'STROM') && !str_contains($header, 'GRÜNSTROM');
        $sparte = $isGas ? 'gas' : 'strom';

        $person = $this->parsePerson($text);
        $bank = $this->parseBank($text, $person);
        $energie = $this->parseEnergy($text);
        $versicherung = $this->parseContract($text, $sparte);

        // Ohne belastbaren Kern (Energiedaten oder Name) der KI/OCR ueberlassen.
        if ($energie === [] && $person === []) {
            return null;
        }

        $who = trim(($person['company_name'] ?? '') !== ''
            ? $person['company_name']
            : trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? '')));
        $art = $sparte === 'gas' ? 'Gas' : 'Strom';

        return [
            'type' => 'energieauftrag',
            'confidence' => 70,
            'summary' => $art . '-Auftrag'
                . (isset($versicherung['insurer']) ? ' - ' . $versicherung['insurer'] : '')
                . (isset($energie['tariff']) ? ' (' . $energie['tariff'] . ')' : '')
                . ($who !== '' ? ' - ' . $who : '')
                . (isset($energie['previous_provider'])
                    ? ' - Wechsel von ' . $energie['previous_provider']
                        . (isset($energie['previous_customer_number']) ? ' (Kd.-Nr. ' . $energie['previous_customer_number'] . ')' : '')
                    : '')
                . ' - Felder gratis aus dem Auftrag gelesen (ohne KI).',
            'title' => $art . '-Auftrag' . ($who !== '' ? ' ' . $who : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $versicherung,
                'kfz' => [],
                'gesundheit' => [],
                'bank' => $bank,
                'personen' => [],
                'energie' => $energie,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function parsePerson(string $text): array
    {
        $raw = [];

        // Firmenname aus dem Namensfeld ("Name, Vorname (ggf. Titel)"):
        // der Wert steht in der Zeile darueber.
        $nameField = $this->valueAboveLabel($text, 'Name, Vorname');
        // Natuerliche Person = Kontoinhaber ("Nachname, Vorname"), z.B.
        // "Isso, Aram" -> Vorname Aram, Nachname Isso.
        [$firstName, $lastName] = $this->accountHolderName($text);

        if ($nameField !== null && $this->looksLikeCompany($nameField)) {
            $raw['company_name'] = $nameField;
        } elseif ($nameField !== null && $firstName === null && $lastName === null) {
            // Kein separater Kontoinhaber: das Namensfeld ist die Person.
            $parts = preg_split('/\s+/', $nameField);
            $raw['last_name'] = array_pop($parts);
            $raw['first_name'] = implode(' ', $parts);
        }
        if ($firstName !== null) {
            $raw['first_name'] = $firstName;
        }
        if ($lastName !== null) {
            $raw['last_name'] = $lastName;
        }

        // Anschrift: Strasse+Hausnummer und PLZ+Ort (Wert ueber dem Label).
        if (preg_match('/^[ \t]*([A-ZÄÖÜ][^\r\n]*?)\s{2,}(\d+\s*[a-zA-Z]?)(?:\s{2,}[^\r\n]*)?\R[ \t]*Stra[ßs]e\s+Hausnummer\b/um', $text, $m)) {
            $raw['street'] = trim($m[1]);
            $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $m[2]));
        }
        if (preg_match('/^[ \t]*(\d{5})\s{2,}([A-ZÄÖÜ][^\r\n]*?)(?:\s{2,}[^\r\n]*)?\R[ \t]*PLZ\s+Ort\b/um', $text, $m)) {
            $raw['zip'] = $m[1];
            $raw['city'] = trim($m[2]);
        }

        // Geburtsdatum + Telefonnummer (zwei Spalten ueber einem Doppel-Label).
        if (preg_match('/^[ \t]*(\d{2}\.\d{2}\.\d{4})\s{2,}([\d][\d\-\/ ]{5,20})(?:\s{2,}[^\r\n]*)?\R[ \t]*Geburtsdatum\s+Telefon/um', $text, $m)) {
            $raw['birth_date'] = $this->germanDate($m[1]);
            $raw['phone'] = $this->cleanPhone($m[2]);
        }

        // E-Mail: erste Adresse, die NICHT vom Anbieter (ewe) stammt.
        $raw['email'] = $this->customerEmail($text);

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseBank(string $text, array $person): array
    {
        $raw = [];
        // Deutsche IBAN = DE + 20 Ziffern (ggf. mit Leerzeichen-Gruppen).
        // Bewusst NICHT die Glaeubiger-Identifikationsnummer ("DE86ZZZ...",
        // enthaelt Buchstaben) und keine maskierte IBAN uebernehmen.
        if (preg_match('/\bDE\d{2}(?:[ ]?\d){18}\b/', $text, $m)
            && !str_contains($m[0], '*')) {
            $raw['iban'] = strtoupper((string) preg_replace('/\s+/', '', $m[0]));
        }
        // Kontoinhaber = die natuerliche Person (falls erkannt).
        $holder = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        if ($holder !== '') {
            $raw['account_holder'] = $holder;
        }
        return $this->validatedBank($raw);
    }

    /** @return array<string,mixed> */
    private function parseEnergy(string $text): array
    {
        $raw = [];

        // Tarif/Produkt: Wert ueber dem Feld-Label "Produkt" (NICHT der
        // Abschnittstitel "Produktauswahl ..." - daher Wortgrenze).
        $tariff = $this->valueAboveLabel($text, 'Produkt(?![\p{L}])');
        if ($tariff !== null && mb_strlen($tariff) <= 80) {
            $raw['tariff'] = $tariff;
        }

        // Jahresverbrauch (kWh).
        $verbrauch = $this->valueAboveLabel($text, 'Letzter Jahresverbrauch');
        if ($verbrauch !== null && preg_match('/(\d[\d.]*)/', $verbrauch, $m)) {
            $raw['consumption_kwh'] = (int) str_replace('.', '', $m[1]);
        }

        // Zaehlernummer bei der Lieferanschrift.
        $meter = $this->valueAboveLabel($text, 'Zählernummer bei Lieferanschrift');
        if ($meter !== null && preg_match('/^[\dA-Za-z][\dA-Za-z\-]{3,29}$/', $meter)) {
            $raw['meter_number'] = $meter;
        }

        // Vorversorger: "Derzeitiger Lieferant" + dessen Kundennummer.
        $prev = $this->valueAboveLabel($text, 'Derzeitiger Lieferant');
        if ($prev !== null && mb_strlen($prev) >= 2 && mb_strlen($prev) <= 150) {
            $raw['previous_provider'] = $prev;
        }
        $prevNr = $this->valueAboveLabel($text, 'Kundennummer beim derzeitigen Lieferanten');
        if ($prevNr !== null && preg_match('/^[\dA-Za-z][\dA-Za-z\-\/]{2,39}$/', $prevNr)) {
            $raw['previous_customer_number'] = $prevNr;
        }

        return $this->validatedEnergy($raw);
    }

    /** @return array<string,mixed> */
    private function parseContract(string $text, string $sparte): array
    {
        $raw = ['sparte' => $sparte];

        if (preg_match('/(EWE\s+VERTRIEB\s+GmbH)/u', $text, $m)) {
            $raw['insurer'] = 'EWE VERTRIEB GmbH';
        } elseif (str_contains(mb_strtoupper($text), 'EWE')) {
            $raw['insurer'] = 'EWE';
        }

        // Grundpreis (monatlicher Grundbetrag) als Abschlag/Beitrag.
        if (preg_match('/(\d+(?:\.\d{3})*,\d{2})\s*Euro\s*\/\s*Monat/u', $text, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = 'monthly';
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Wert in der Zeile UEBER dem Feld-Label (linke Spalte). Das Auftragslayout
     * mischt rechts einen fortlaufenden AGB-Text ein; solche rechten Zeilen
     * (grosse Einrueckung) werden uebersprungen, damit nicht versehentlich
     * AGB-Text ("...lichen Verträge...") als Wert gelesen wird.
     *
     * @param string $labelRegex Regex, der den ANFANG der (links eingerueckten)
     *                           Label-Zeile trifft.
     */
    private function valueAboveLabel(string $text, string $labelRegex): ?string
    {
        $lines = preg_split('/\R/', $text) ?: [];
        foreach ($lines as $i => $line) {
            if ($i > 0 && $this->leadingSpaces($line) < 40
                && preg_match('/^' . $labelRegex . '/u', ltrim($line))) {
                for ($j = $i - 1; $j >= 0; $j--) {
                    if (trim($lines[$j]) === '') {
                        continue;
                    }
                    if ($this->leadingSpaces($lines[$j]) >= 40) {
                        continue; // rechte Spalte (AGB-Fliesstext) - ueberspringen
                    }
                    $left = preg_split('/\s{2,}/', trim($lines[$j]))[0] ?? null;
                    return ($left !== null && $left !== '') ? $left : null;
                }
                return null;
            }
        }
        return null;
    }

    private function leadingSpaces(string $line): int
    {
        return strlen($line) - strlen(ltrim($line));
    }

    /**
     * Kontoinhaber-Name aus "Nachname, Vorname" nahe dem Label
     * "der/die Kontoinhabende". Liefert [Vorname, Nachname] oder [null,null].
     *
     * @return array{0:?string,1:?string}
     */
    private function accountHolderName(string $text): array
    {
        // Anker: das SPEZIFISCHE Kontoinhaber-Label ("der/die Kontoinhabende –
        // falls abweichend ..."), NICHT der gleichlautende SEPA-Satz. Direkt
        // davor steht der Name "Nachname, Vorname".
        if (!preg_match('/der\/die Kontoinhabende\s*[–\-]\s*falls abweichend/u', $text, $am, PREG_OFFSET_CAPTURE)) {
            return [null, null];
        }
        $before = substr($text, 0, $am[0][1]);
        if (preg_match_all('/([A-ZÄÖÜ][\p{L}\-]{1,25}),\s+([A-ZÄÖÜ][\p{L}\-]{1,25})/u', $before, $mm, PREG_SET_ORDER)) {
            // Letzten (naechsten am Anker) echten Namen nehmen - die Feld-Labels
            // "Name, Vorname"/"..., Firma" ueberspringen.
            for ($k = count($mm) - 1; $k >= 0; $k--) {
                $last = trim($mm[$k][1]);
                $first = trim($mm[$k][2]);
                if (strcasecmp($last, 'Name') === 0 || in_array($first, ['Vorname', 'Firma', 'Titel'], true)) {
                    continue;
                }
                return [$first, $last]; // [Vorname, Nachname]
            }
        }
        return [null, null];
    }

    private function looksLikeCompany(string $name): bool
    {
        return (bool) preg_match('/(GmbH|mbH|\bUG\b|\bOHG\b|\bKG\b|\bGbR\b|\bAG\b|\be\.?\s?K\b|Einzelunt|Einzelfirma|Gewerbe|Unternehmen)/ui', $name);
    }

    /** Erste E-Mail, die NICHT vom Anbieter (ewe) stammt (= die des Kunden). */
    private function customerEmail(string $text): ?string
    {
        if (preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text, $mm)) {
            foreach ($mm[0] as $email) {
                if (stripos($email, '@ewe') === false && stripos($email, 'ewe.de') === false) {
                    return strtolower($email);
                }
            }
        }
        return null;
    }

    /** Telefonnummer auf Ziffern (mit fuehrender 0) reduzieren. */
    private function cleanPhone(string $phone): ?string
    {
        $digits = preg_replace('/[^\d]/', '', $phone) ?? '';
        return $digits !== '' ? $digits : null;
    }

    private function germanDate(?string $value): ?string
    {
        if ($value !== null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $value, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        return null;
    }
}
