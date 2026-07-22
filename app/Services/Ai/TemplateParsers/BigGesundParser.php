<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer die Formulare der BIG direkt gesund (gesetzliche
 * Krankenkasse). Zwei Formulartypen werden erkannt und gratis aus der
 * Textebene gelesen (kein KI-Aufruf):
 *
 *  1) Mitgliedsantrag - der eigentliche Beitritt zur gesetzlichen
 *     Krankenversicherung. Label-links/Wert-rechts-Layout: Name, Anschrift,
 *     Geburtsdaten, Familienstand, bisherige Krankenkasse (Vorversicherung),
 *     Versichertennummer (lebenslange KVNR), Telefon, E-Mail.
 *  2) Antrag Plusbonus - Zusatzversicherungs-/Bonusformular. Es traegt die
 *     Bankverbindung des Kunden (IBAN/BIC/Kontoinhaber) und den vollen Namen -
 *     Felder, die im Mitgliedsantrag fehlen.
 *
 * Beide Formulare bleiben als "beitrittserklaerung" im Dokumenten-Eingang
 * (Neugeschaeft): der Mitarbeiter legt den Kunden/Vertrag an bzw. haengt das
 * Dokument an die Akte. Alle Werte durchlaufen die harte Feldvalidierung;
 * unsichere Felder bleiben leer statt falsch. Die im Fussbereich abgedruckten
 * Bankverbindungen der Krankenkasse selbst werden bewusst NICHT als
 * Kunden-IBAN uebernommen (nur der klar abgegrenzte Zahlungsempfaenger-Block
 * des Plusbonus-Formulars).
 */
class BigGesundParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    private const INSURER = 'BIG direkt gesund';

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        // Weiche Trennzeichen normalisieren.
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);

        $upper = mb_strtoupper($text);
        // Nur BIG-direkt-gesund-Formulare (Wortmarke oder Domain im Dokument).
        if (!str_contains($upper, 'BIG DIREKT GESUND') && !str_contains($upper, 'BIG-DIREKT.DE')) {
            return null;
        }

        // -layout-Zeilen bewusst NICHT trimmen: fuehrende Leerspalten tragen
        // die Spalteninformation (Wert-ueber-Label im Plusbonus-Formular).
        $this->lines = preg_split('/\R/', $text) ?: [];

        if (str_contains($upper, 'MITGLIEDSANTRAG')) {
            return $this->parseMitgliedsantrag();
        }
        if (str_contains($upper, 'PLUSBONUS')) {
            return $this->parsePlusbonus();
        }

        return null;
    }

    /**
     * Mitgliedsantrag: Beitritt zur gesetzlichen Krankenversicherung.
     * Layout: "Label            Wert" auf einer Zeile (Adresse mehrzeilig).
     */
    private function parseMitgliedsantrag(): ?array
    {
        $person = [];

        $anrede = $this->labelValue('Anrede');
        if ($anrede !== null) {
            $lower = mb_strtolower($anrede);
            if (str_contains($lower, 'frau')) {
                $person['gender'] = 'female';
            } elseif (str_contains($lower, 'herr')) {
                $person['gender'] = 'male';
            }
        }
        $person['first_name'] = $this->labelValue('Vorname');
        $person['last_name'] = $this->labelValue('Nachname');

        $birth = $this->labelValue('Geburtsdatum');
        if ($birth !== null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $birth, $m)) {
            $person['birth_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        $person['birth_place'] = $this->labelValue('Geburtsort');

        $familienstand = mb_strtolower((string) $this->labelValue('Familienstand'));
        if (in_array($familienstand, ['ledig', 'verheiratet', 'geschieden', 'verwitwet'], true)) {
            $person['marital_status'] = $familienstand;
        }

        // Adresse: "Strasse Hausnummer" (Wert neben Label), PLZ + Ort in den
        // Folgezeilen (ohne eigenes Label, vor "Deutschland").
        $this->fillAddress($person);

        // Telefon: Wert in der Zeile UNTER dem Label (kein Wert daneben).
        $phone = $this->phone();
        if ($phone !== null) {
            $person['phone'] = $phone;
        }

        $email = $this->labelValue('E-Mail');
        if ($email !== null && preg_match('/[\w.+\-]+@[\w.\-]+\.\w{2,}/u', $email, $m)) {
            $person['email'] = $m[0];
        }

        $person = $this->validatedPerson(array_filter($person, fn ($v) => $v !== null && $v !== ''));

        // Gesundheit: BIG ist die neue (gesetzliche) Kasse; die bisherige Kasse
        // ist die Vorversicherung; die Versichertennummer ist die lebenslange
        // KVNR des Kunden.
        $healthRaw = [
            'health_insurance_company' => self::INSURER,
            'health_insurance_type' => 'gesetzlich',
        ];
        $prev = $this->labelValue('Bei welcher Krankenkasse sind Sie bisher');
        if ($prev !== null && $prev !== '' && stripos($prev, 'versichert') === false) {
            $healthRaw['previous_insurer'] = $prev;
        }
        // Lebenslange Krankenversichertennummer (KVNR): 1 Buchstabe + 9 Ziffern,
        // in der Zeile des Labels "Versichertennummer (optional)".
        foreach ($this->lines as $line) {
            if (mb_stripos($line, 'Versichertennummer') !== false && preg_match('/\b([A-Z]\d{9})\b/', $line, $m)) {
                $healthRaw['health_insurance_number'] = $m[1];
                break;
            }
        }
        $health = $this->validatedHealth(array_filter($healthRaw, fn ($v) => $v !== null && $v !== ''));

        if (($person['first_name'] ?? null) === null && ($person['last_name'] ?? null) === null) {
            return null;
        }

        $insurance = $this->validatedInsurance([
            'sparte' => 'krankenversicherung',
            'insurer' => self::INSURER,
        ]);

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        return [
            'type' => 'beitrittserklaerung',
            'confidence' => 72,
            'summary' => 'BIG-direkt-gesund-Mitgliedsantrag (gesetzliche Krankenversicherung)'
                . ($name !== '' ? ' - ' . $name : '')
                . ' - Felder gratis aus dem Formular gelesen (ohne KI).'
                . (isset($health['previous_insurer']) ? ' Zuvor versichert bei ' . $health['previous_insurer'] . '.' : ''),
            'title' => 'Mitgliedsantrag BIG direkt gesund' . ($name !== '' ? ' ' . $name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $insurance,
                'gesundheit' => $health,
                'kfz' => [],
                'bank' => [],
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /**
     * Antrag Plusbonus: Zusatzversicherungs-/Bonusformular. Layout: Wert steht
     * UEBER dem Label. Genutzt werden die klar abgegrenzten, verlaesslichen
     * Bloecke (Zahlungsempfaenger: Kontoinhaber/IBAN/BIC) und PLZ/Ort.
     */
    private function parsePlusbonus(): ?array
    {
        $person = [];
        $bankRaw = [];

        // Kontoinhaber*in = voller Name des Kunden ("Majd Aldin Alkhatib").
        $holderLine = $this->valueAbove(['Kontoinhaber']);
        if ($holderLine !== null) {
            $holder = $this->columns($holderLine)[0] ?? trim($holderLine);
            if (preg_match('/^[A-ZÄÖÜ][\p{L}\-]+(?:\s+[A-ZÄÖÜ][\p{L}\-]+)+$/u', $holder)) {
                $bankRaw['account_holder'] = $holder;
                $parts = preg_split('/\s+/', $holder) ?: [];
                $person['last_name'] = array_pop($parts);
                $person['first_name'] = implode(' ', $parts) ?: null;
            }
        }

        // IBAN + BIC: Wertzeile ueber dem Label "IBAN ...".
        $ibanLine = $this->valueAbove(['IBAN (Internationale', 'IBAN']);
        if ($ibanLine !== null) {
            if (preg_match('/\bDE\d{2}(?:[ ]?\d){18}\b/', $ibanLine, $m)) {
                $bankRaw['iban'] = strtoupper((string) preg_replace('/\s+/', '', $m[0]));
            }
            if (preg_match('/\b([A-Z]{4}DE[A-Z0-9]{2,5})\b/', $ibanLine, $m)) {
                $bankRaw['bic'] = $m[1];
            }
        }

        // PLZ + Ort: Wertzeile ueber dem Label "... PLZ ... Ort".
        $plzLine = $this->valueAbove(['PLZ']);
        if ($plzLine !== null && preg_match('/(?<!\d)(\d{5})\s{2,}([A-ZÄÖÜ][\p{L}.\-]+(?:[ \-][A-ZÄÖÜ]?[\p{L}.\-]+)*)/u', $plzLine, $m)) {
            $person['zip'] = $m[1];
            $person['city'] = trim((string) preg_replace('/\s{2,}.*$/u', '', $m[2]));
        }

        $person = $this->validatedPerson(array_filter($person, fn ($v) => $v !== null && $v !== ''));
        $bank = $this->validatedBank(array_filter($bankRaw, fn ($v) => $v !== null && $v !== ''));

        // Ohne belastbaren Kern (Name oder IBAN) lieber der normalen Analyse
        // ueberlassen.
        if (($person['first_name'] ?? null) === null && ($bank['iban'] ?? null) === null) {
            return null;
        }

        // Hoehe der jaehrlichen Police (private Zusatzversicherung) fuer die
        // Zusammenfassung - am Label "Hoehe der ... Police" verankert, damit
        // nicht der beworbene Bonusbetrag ("200 Euro") getroffen wird.
        $police = null;
        foreach ($this->lines as $i => $line) {
            // Label der jaehrlichen Police (nicht die leeren Angehoerigen-Zeilen
            // "Hoehe der Police (in Euro)"): am Stamm "hrlich" verankert, robust
            // gegen Umlaut/oe-Schreibweise.
            if (mb_stripos($line, 'Police') === false || mb_stripos($line, 'hrlich') === false) {
                continue;
            }
            for ($j = $i; $j < count($this->lines) && $j <= $i + 2; $j++) {
                if (preg_match('/(\d{2,4})\s+Euro/u', $this->lines[$j], $m)) {
                    $police = $m[1];
                    break 2;
                }
            }
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        return [
            'type' => 'beitrittserklaerung',
            'confidence' => 68,
            'summary' => 'BIG-direkt-gesund Antrag Plusbonus (Zusatzversicherung/Bonus)'
                . ($name !== '' ? ' - ' . $name : '')
                . (isset($bank['iban']) ? ' - Bankverbindung erfasst' : '')
                . ($police !== null ? ' - Police ' . $police . ' EUR/Jahr' : '')
                . ' - Felder gratis aus dem Formular gelesen (ohne KI).',
            'title' => 'Antrag Plusbonus BIG direkt gesund' . ($name !== '' ? ' ' . $name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $this->validatedInsurance([
                    'sparte' => 'krankenversicherung',
                    'insurer' => self::INSURER,
                ]),
                'gesundheit' => $this->validatedHealth([
                    'health_insurance_company' => self::INSURER,
                    'health_insurance_type' => 'gesetzlich',
                ]),
                'kfz' => [],
                'bank' => $bank,
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /**
     * Adresse aus dem Mitgliedsantrag: "Strasse Hausnummer" steht neben dem
     * Label "Adresse", PLZ + Ort in einer der Folgezeilen (ohne eigenes Label,
     * vor "Deutschland"). @param array<string,mixed> $person
     */
    private function fillAddress(array &$person): void
    {
        foreach ($this->lines as $i => $line) {
            if (!preg_match('/^\s*Adresse\s{2,}(\S.*?)\s*$/u', $line, $m)) {
                continue;
            }
            $street = trim($m[1]);
            if (preg_match('/^(.*\D)\s*(\d+(?:\s*[a-zA-Z])?)\s*$/u', $street, $s)) {
                $person['street'] = trim($s[1]);
                $person['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
            } elseif (preg_match('/\p{L}/u', $street)) {
                $person['street'] = $street;
            }
            // PLZ + Ort in den naechsten Zeilen (bis zum naechsten Label/Ende).
            for ($j = $i + 1; $j < count($this->lines) && $j <= $i + 6; $j++) {
                if (preg_match('/(?<!\d)(\d{5})\s+([A-ZÄÖÜ][\p{L}.\-]+(?:[ \-][A-ZÄÖÜ]?[\p{L}.\-]+)*)/u', $this->lines[$j], $z)) {
                    $person['zip'] = $z[1];
                    $person['city'] = trim((string) preg_replace('/\s{2,}.*$/u', '', $z[2]));
                    break;
                }
            }
            return;
        }
    }

    /**
     * Erste Telefon-/Mobilnummer, die wie eine deutsche Rufnummer aussieht -
     * so wird keine lange Referenz-/Institutionsnummer als Telefon uebernommen.
     */
    private function phone(): ?string
    {
        if (preg_match_all('/\b(0\d{9,14})\b/', $this->text(), $mm)) {
            foreach ($mm[1] as $candidate) {
                if (\App\Support\GermanPhone::isMobile($candidate) || \App\Support\GermanPhone::isLandline($candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    /**
     * Wert neben einem Label ("Label            Wert" auf derselben Zeile).
     */
    private function labelValue(string $label): ?string
    {
        foreach ($this->lines as $line) {
            if (preg_match('/^\s*' . preg_quote($label, '/') . '\s{2,}(\S.*?)\s*$/u', $line, $m)) {
                return trim($m[1]);
            }
        }
        return null;
    }

    /** Naechste nicht-leere Zeile UEBER der ersten Zeile mit einem der Anker. */
    private function valueAbove(array $needles): ?string
    {
        foreach ($this->lines as $i => $line) {
            $hit = false;
            foreach ($needles as $needle) {
                if ($needle !== '' && mb_stripos($line, $needle) !== false) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
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

    /** Zerlegt eine -layout-Zeile an Spaltengrenzen (>= 2 Leerzeichen). @return list<string> */
    private function columns(string $line): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\s{2,}/', trim($line)) ?: []),
            fn ($c) => $c !== ''
        ));
    }

    private function text(): string
    {
        return implode("\n", $this->lines);
    }
}
