<?php

namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer die EWE-Vertragsbestaetigung (Strom/Gas). Anders als der
 * generische Energie-Auftrag ist dies das strukturierte Willkommens-/
 * Bestaetigungsschreiben mit klar beschrifteten Bloecken - Vertragsnummer,
 * Kundennummer, Lieferstelle, persoenliche Daten, Produktdetails (Tarif,
 * Lieferbeginn, Preise), monatliche Zahlung, Marktlokations-ID und
 * Netzbetreiber. Alles wird per fester Regel gratis aus der Textebene gelesen
 * (kein KI-Aufruf).
 *
 * Bewusst NICHT uebernommen: die (maskierte) Kunden-IBAN, die Bankverbindung
 * der EWE selbst und die E-Mail der Schlichtungsstelle. Alle Werte durchlaufen
 * die harte Feldvalidierung; unsichere Felder bleiben leer statt falsch.
 */
class EweVertragsbestaetigungParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $upper = mb_strtoupper($text);
        // "EWE" nur als eigenstaendiges Wort - "jEWEils" u.ae. enthalten die
        // Buchstabenfolge und wuerden sonst fremde Dokumente vereinnahmen.
        if (! preg_match('/\bEWE\b/u', $upper)
            || (! str_contains($upper, 'VERTRAGSBESTÄTIGUNG') && ! str_contains($upper, 'VERTRAGSBESTAETIGUNG'))) {
            return null;
        }

        $this->lines = preg_split('/\R/', $text) ?: [];

        $person = $this->parsePerson();
        $sparte = $this->sparte();

        $insRaw = [
            'insurer' => 'EWE VERTRIEB GmbH',
            'sparte' => $sparte,
            // Dies ist die BESTAETIGUNG des Vertrags: sie darf einen frueher
            // hochgeladenen Auftrag desselben Kunden vervollstaendigen
            // (Vertragsnummer, Kundennummer, MaLo-ID, Lieferbeginn, Abschlag)
            // statt einen zweiten Vertrag anzulegen.
            'document_stage' => Contract::STAGE_VERTRAG,
        ];
        if (($nr = $this->labelValue('Ihre Vertragsnummer')) !== null && preg_match('/\d{6,}/', $nr, $m)) {
            $insRaw['contract_number'] = $m[0];
        }
        // Produktdetails-Tabelle: "<Sparte> <Tarif> <Preisgarantie> <Lieferbeginn>
        // <Preise...>". Tarif = 2. Spalte, Lieferbeginn = erstes Datum der Zeile.
        $prodCols = preg_split('/\s{2,}/', trim($this->afterLabelRow('Strom', 'Gas', 'Erdgas'))) ?: [];
        if (isset($prodCols[1]) && preg_match('/[A-Za-zÄÖÜ]{3,}/u', $prodCols[1])) {
            $insRaw['tariff'] = trim($prodCols[1]);
        }
        foreach ($prodCols as $c) {
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', trim($c), $m)) {
                $insRaw['start_date'] = $m[3].'-'.$m[2].'-'.$m[1];
                break;
            }
        }
        // Ende der Erstlaufzeit.
        if (preg_match('/Erstlaufzeit endet am\s+(\d{2})\.(\d{2})\.(\d{4})/u', $this->text(), $m)) {
            $insRaw['end_date'] = $m[3].'-'.$m[2].'-'.$m[1];
        }
        // Monatliche Zahlung (Abschlag) = LETZTE Betragsspalte der Datenzeile
        // "Nettobetrag | MwSt-% | MwSt-Betrag | Monatliche Zahlung".
        if (preg_match('/(\d{1,3}(?:\.\d{3})*,\d{2})\s+\d{1,3}\s*%\s+(\d{1,3}(?:\.\d{3})*,\d{2})\s+(\d{1,3}(?:\.\d{3})*,\d{2})/u', $this->text(), $m)) {
            $insRaw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[3]);
            $insRaw['premium_interval'] = 'monthly';
        }
        $insurance = $this->validatedInsurance(array_filter($insRaw, fn ($v) => $v !== null && $v !== ''));

        $enRaw = [];
        if (($kn = $this->labelValue('Ihre Kundennummer')) !== null && preg_match('/\d{4,}/', $kn, $m)) {
            $enRaw['customer_number'] = $m[0];
        }
        // Marktlokations-ID (11-stellig): Wert unter der Beschriftung.
        if (preg_match('/Marktlokations-ID\s*\R+\s*(?:Strom|Gas)?\s*(\d{11})\b/u', $this->text(), $m)) {
            $enRaw['malo_id'] = $m[1];
        } elseif (preg_match('/\b(\d{11})\b/u', $this->afterLabel('Marktlokations-ID'), $m)) {
            $enRaw['malo_id'] = $m[1];
        }
        if (isset($insurance['tariff'])) {
            $enRaw['tariff'] = $insurance['tariff'];
        }
        // Tarifpreise aus der Produktdetails-Tabelle. Die Spalten sind je
        // Preis netto UND brutto ("25,18  29,96   201,92  240,29") - erfasst
        // wird der BRUTTOPREIS (der zweite Wert je Paar), weil der Kunde ihn
        // zahlt. Der Grundpreis steht bei EWE pro JAHR; die Vertragsakte fuehrt
        // ihn pro MONAT -> wird umgerechnet.
        $preise = $this->productPrices($prodCols);
        if ($preise['working_price'] !== null) {
            $enRaw['working_price'] = $preise['working_price'];
        }
        if ($preise['base_price'] !== null) {
            $enRaw['base_price'] = $preise['base_price'];
        }
        // Netzbetreiber (erste Firmenzeile im Netzbetreiber-Block).
        if (($grid = $this->blockValue('zuständiger Netzbetreiber')) !== null) {
            $enRaw['grid_operator'] = $grid;
        }
        $energie = $this->validatedEnergy(array_filter($enRaw, fn ($v) => $v !== null && $v !== ''));

        if (($person['first_name'] ?? null) === null && ($person['last_name'] ?? null) === null
            && ($insurance['contract_number'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        $sparteLabel = $sparte === 'gas' ? 'Gas' : 'Strom';
        return [
            'type' => 'energieauftrag',
            'confidence' => 76,
            'summary' => 'EWE '.$sparteLabel.'-Vertragsbestaetigung'
                .($name !== '' ? ' - '.$name : '')
                .(isset($insurance['contract_number']) ? ' - Vertrag '.$insurance['contract_number'] : '')
                .(isset($enRaw['customer_number']) ? ' - Kundennr. '.$enRaw['customer_number'] : '')
                .(isset($insurance['tariff']) ? ' - '.$insurance['tariff'] : '')
                .(isset($insurance['premium_amount']) ? ' - Abschlag '.number_format($insurance['premium_amount'], 2, ',', '.').' EUR/Monat' : '')
                .' - Felder gratis aus der Bestaetigung gelesen (ohne KI).',
            'title' => 'EWE '.$sparteLabel.'-Vertrag'.($name !== '' ? ' '.$name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $insurance,
                'kfz' => [],
                'gesundheit' => [],
                'bank' => [],
                'personen' => [],
                'energie' => $energie,
            ],
        ];
    }

    /**
     * Arbeits- und Grundpreis aus den Zahlenspalten der Produktdetails-Zeile.
     * Layout: "<Sparte> <Produkt> <Preisgarantie> <Lieferbeginn> <AP netto>
     * <AP brutto> <GP netto> <GP brutto>". Genommen wird jeweils der BRUTTO-
     * Wert; steht der Grundpreis laut Tabellenkopf pro JAHR, wird er auf den
     * Monat umgerechnet (die Vertragsakte fuehrt EUR/Monat). Bei einer
     * unerwarteten Spaltenzahl bleibt es lieber leer als falsch.
     *
     * @param list<string> $cols
     * @return array{working_price: ?float, base_price: ?float}
     */
    private function productPrices(array $cols): array
    {
        $zahlen = [];
        foreach ($cols as $c) {
            if (preg_match('/^\d{1,3}(?:\.\d{3})*,\d{2}$/', trim($c))) {
                $zahlen[] = (float) str_replace(['.', ','], ['', '.'], trim($c));
            }
        }

        [$working, $base] = match (count($zahlen)) {
            4 => [$zahlen[1], $zahlen[3]], // netto/brutto je Preis -> brutto
            2 => [$zahlen[0], $zahlen[1]], // nur ein Wert je Preis
            default => [null, null],
        };

        // Grundpreis-Einheit aus der Kopfzeile der Tabelle.
        if ($base !== null && preg_match('/Grundpreis\s*\(\s*Euro\s*\/\s*Jahr/ui', $this->text())) {
            $base = round($base / 12, 2);
        }

        return ['working_price' => $working, 'base_price' => $base];
    }

    /** @return array<string,mixed> */
    private function parsePerson(): array
    {
        $raw = [];
        $name = $this->labelValue('Name');
        if ($name !== null && preg_match('/^[A-ZÄÖÜ][\p{L}\-]+(?:\s+[A-ZÄÖÜ][\p{L}\-]+)+$/u', $name)) {
            $parts = preg_split('/\s+/', $name) ?: [];
            $raw['last_name'] = array_pop($parts);
            $raw['first_name'] = implode(' ', $parts) ?: null;
        }
        $birth = $this->labelValue('Geburtsdatum');
        if ($birth !== null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $birth, $m)) {
            $raw['birth_date'] = $m[3].'-'.$m[2].'-'.$m[1];
        }
        // Adresse: "Adresse:  <Strasse Hausnr>" + Folgezeile "<PLZ Ort>".
        foreach ($this->lines as $i => $line) {
            if (! preg_match('/(?:^|\s)Adresse:\s{2,}(\S.*?)(?:\s{2,}|$)/u', $line, $m)) {
                continue;
            }
            $street = trim($m[1]);
            if (preg_match('/^(.*\D)\s+(\d+(?:\s*[a-zA-Z])?)\s*$/u', $street, $s)) {
                $raw['street'] = trim($s[1]);
                $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
            } else {
                $raw['street'] = $street;
            }
            for ($j = $i + 1; $j < count($this->lines) && $j <= $i + 3; $j++) {
                if (preg_match('/(?<!\d)(\d{5})\s+([A-ZÄÖÜ][\p{L}.\-]+(?:[ \-][A-ZÄÖÜ]?[\p{L}.\-]+)*)/u', $this->lines[$j], $z)) {
                    $raw['zip'] = $z[1];
                    $raw['city'] = trim((string) preg_replace('/\s{2,}.*$/u', '', $z[2]));
                    break;
                }
            }
            break;
        }
        // Telefonnummer.
        if (($tel = $this->labelValue('Telefonnummer')) !== null) {
            $digits = preg_replace('/[^\d]/', '', $tel);
            if (preg_match('/^0\d{9,14}$/', (string) $digits)) {
                $raw['phone'] = $digits;
            }
        }
        // E-Mail (kann durch die Spaltenbreite auf zwei Zeilen umgebrochen sein).
        $email = $this->emailAcrossLines();
        if ($email !== null) {
            $raw['email'] = $email;
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * E-Mail hinter "E-Mail:" - in der EWE-Bestaetigung bricht sie oft am
     * Spaltenrand um ("...gmail.c" / "om"). Der Rest wird aus der Folgezeile an
     * derselben Spaltenposition ergaenzt.
     */
    private function emailAcrossLines(): ?string
    {
        foreach ($this->lines as $i => $line) {
            $pos = mb_strpos($line, 'E-Mail:');
            if ($pos === false) {
                continue;
            }
            $frag = trim(mb_substr($line, $pos + mb_strlen('E-Mail:')));
            $frag = (string) preg_replace('/\s.*$/u', '', $frag); // nur das erste Token
            if ($frag === '' || ! str_contains($frag, '@')) {
                return null;
            }
            // Vollstaendige TLD schon da? -> fertig.
            if (preg_match('/\.[a-z]{2,}$/i', $frag)) {
                return filter_var($frag, FILTER_VALIDATE_EMAIL) ? strtolower($frag) : null;
            }
            // Sonst Fortsetzung aus der Folgezeile (gleiche Spalte, rechter Rand).
            $col = mb_strpos($line, $frag);
            for ($j = $i + 1; $j < count($this->lines) && $j <= $i + 2; $j++) {
                $tail = trim(mb_substr($this->lines[$j], (int) $col));
                if (preg_match('/^([a-z]{1,12})\b/i', $tail, $mm)) {
                    $frag .= $mm[1];
                    break;
                }
            }
            return filter_var($frag, FILTER_VALIDATE_EMAIL) ? strtolower($frag) : null;
        }
        return null;
    }

    private function sparte(): string
    {
        // Aus der Produkttabelle (erste Datenzeile beginnt mit "Strom"/"Gas"),
        // NICHT aus dem Fliesstext - der nennt z.B. die "Bundesnetzagentur ...
        // Erdgas" als Boilerplate.
        $row = ltrim($this->afterLabelRow('Gas', 'Erdgas', 'Strom'));
        if (str_starts_with($row, 'Gas') || str_starts_with($row, 'Erdgas')) {
            return 'gas';
        }
        return 'strom';
    }

    /** Wert hinter "Label:" bis zur naechsten Spalte (2+ Leerzeichen) / Zeilenende. */
    private function labelValue(string $label): ?string
    {
        $pattern = '/(?:^|\s)'.preg_quote($label, '/').':\s{1,}([^\r\n]+?)(?:\s{2,}|$)/mu';
        return preg_match($pattern, $this->text(), $m) ? trim($m[1]) : null;
    }

    /** Text ab einer Beschriftung (fuer Werte, die in der Folgezeile stehen). */
    private function afterLabel(string $label): string
    {
        $pos = mb_stripos($this->text(), $label);
        return $pos === false ? '' : mb_substr($this->text(), $pos, 200);
    }

    /** Die Datenzeile, die mit einem der Sparten-Woerter beginnt (Produkttabelle). */
    private function afterLabelRow(string ...$starts): string
    {
        foreach ($this->lines as $line) {
            $t = ltrim($line);
            foreach ($starts as $s) {
                if (str_starts_with($t, $s)) {
                    return $line;
                }
            }
        }
        return '';
    }

    /** Erster Firmenname in einem beschrifteten Block (z.B. Netzbetreiber). */
    private function blockValue(string $header): ?string
    {
        foreach ($this->lines as $i => $line) {
            if (mb_stripos($line, $header) === false) {
                continue;
            }
            // Ueberschriftenzeile ("Firmenname  Adresse  ...") ueberspringen,
            // dann die erste Wertzeile nehmen (linke Spalte = Firmenname).
            for ($j = $i + 1; $j < count($this->lines) && $j <= $i + 4; $j++) {
                $cols = preg_split('/\s{2,}/', trim($this->lines[$j])) ?: [];
                $first = trim($cols[0] ?? '');
                if ($first === '' || mb_stripos($first, 'Firmenname') !== false) {
                    continue;
                }
                if (preg_match('/^[A-ZÄÖÜ][\p{L}0-9 .\-]{2,60}$/u', $first)) {
                    return $first;
                }
            }
            return null;
        }
        return null;
    }

    private function text(): string
    {
        return implode("\n", $this->lines);
    }
}
