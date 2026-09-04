<?php

namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\ReadsDocumentPages;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer den LichtBlick-AUFTRAG (OekoStrom/OekoGas) - den
 * Wechsel-Auftrag von einem bisherigen Versorger (typisch: die oertlichen
 * Stadtwerke) zu LichtBlick. Das Formular ist ueber alle Kunden hinweg
 * identisch aufgebaut, die Werte stehen jeweils UEBER ihrer Beschriftung
 * ("Altahan" ueber "Nachname"): Kundendaten (Anrede, Name, Geburtsdatum als
 * TTMMJJ, Anschrift, Telefon, E-Mail), Zaehlernummer/MaLo-ID, bisheriger
 * Versorger samt Kundennummer und Jahresverbrauch, Tarifpreise (Arbeits-/
 * Grundpreis) und die Kunden-IBAN (SEPA).
 *
 * Stufe 'antrag' (Auftrag-zuerst-System): die Auftragsnummer wird als
 * VORLAEUFIGE Vertragsnummer gefuehrt und spaeter von der endgueltigen
 * Nummer der Vertragsbestaetigung ersetzt; spaetere Post findet den Vertrag
 * ueber MaLo-ID/Zaehlernummer.
 *
 * Betreiber-Regel Lieferbeginn (Stadtwerke-Wechsel): bei den Stadtwerken gilt
 * eine Kuendigungsfrist von 14 Tagen; der Betrieb reicht den Auftrag am
 * Upload-Tag ein (+ Bearbeitungspuffer). Ist im Auftrag KEIN Lieferbeginn
 * angegeben, beginnt der Vertrag daher SPAETESTENS ~20 Tage nach dem Upload -
 * der Parser meldet expected_start_within_days=20, die Vertragsanlage setzt
 * daraus den voraussichtlichen Beginn (die spaetere Vertragsbestaetigung
 * ersetzt ihn mit dem endgueltigen Datum, feldgenau in der Version History).
 *
 * Gelesen wird ausschliesslich die ERSTE Seite (Betreiber-Vorgabe 30.07.2026):
 * dort steht das komplette Auftragsformular, die Seiten 2 ff. sind AGB,
 * Widerrufsbelehrung und Datenschutzhinweise. Dieser Rechtstext hat das
 * Dokument frueher zweimal unlesbar gemacht - er nennt "Beratungsprotokolle"
 * (Ausschlussmerkmal fuer Vergleichsangebote) und mit "jeweils" die
 * Buchstabenfolge "EWE".
 *
 * Alle Werte durchlaufen die harte Feldvalidierung; unsichere Felder bleiben
 * leer statt falsch.
 */
class LichtblickAuftragParser implements DocumentTemplateParser
{
    use ReadsDocumentPages;
    use ValidatesExtractedFields;

    /**
     * Spaetester erwarteter Lieferbeginn nach Einreichung (Tage):
     * 14 Tage Kuendigungsfrist Stadtwerke + Einreichung am Upload-Tag +
     * Bearbeitungspuffer (Betreiber-Vorgabe 29.07.2026).
     */
    public const EXPECTED_START_DAYS = 20;

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        // Nur das Auftragsformular selbst lesen (Seite 1) - die Seiten 2 ff.
        // tragen ausschliesslich Rechtstext und wuerden die Erkennung stoeren.
        $text = $this->firstPage($text);
        $upper = mb_strtoupper($text);

        if ($this->looksLikeComparisonProtocol($text)) {
            return null;
        }
        // Nur der LichtBlick-Auftrag selbst (nicht eine spaetere Rechnung o.ae.).
        if (! str_contains($upper, 'LICHTBLICK') || ! str_contains($upper, 'AUFTRAG')) {
            return null;
        }

        $this->lines = preg_split('/\R/', $text) ?: [];

        $person = $this->parsePerson();
        $energie = $this->parseEnergy();
        $insurance = $this->parseInsurance($upper, $energie);
        $bank = $this->parseBank($person);

        // Ohne belastbaren Kern der normalen Analyse/KI ueberlassen.
        if (($person['last_name'] ?? null) === null
            && ($energie['meter_number'] ?? null) === null && ($energie['malo_id'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        $sparteLabel = ($insurance['sparte'] ?? 'strom') === 'gas' ? 'Gas' : 'Strom';
        return [
            'type' => 'energieauftrag',
            'confidence' => 76,
            'summary' => 'LichtBlick '.$sparteLabel.'-Auftrag (Versorgerwechsel)'
                .($name !== '' ? ' - '.$name : '')
                .(($order = $this->orderNumber()) !== null ? ' - Auftragsnummer '.$order : '')
                .(isset($energie['previous_provider']) ? ' - Wechsel von '.$energie['previous_provider'] : '')
                .(isset($energie['consumption_kwh']) ? ' - '.number_format($energie['consumption_kwh'], 0, ',', '.').' kWh/Jahr' : '')
                .(isset($insurance['start_date'])
                    ? ' - Lieferbeginn '.$this->displayDate($insurance['start_date'])
                    : (isset($insurance['expected_start_within_days'])
                        ? ' - Lieferbeginn nicht angegeben: voraussichtlich binnen ~'.$insurance['expected_start_within_days']
                            .' Tagen (Kuendigungsfrist Stadtwerke 14 Tage + Bearbeitung)'
                        : ''))
                .' - Felder gratis aus dem Auftrag gelesen (ohne KI).',
            'title' => 'LichtBlick '.$sparteLabel.'-Auftrag'.($name !== '' ? ' '.$name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $insurance,
                'kfz' => [],
                'gesundheit' => [],
                'bank' => $bank,
                'personen' => [],
                'energie' => $energie,
            ],
        ];
    }

    /**
     * Kundendaten-Block: Wert steht UEBER der Beschriftung, linke Spalte.
     * Geburtsdatum als TTMMJJ ("021180" -> 1980-11-02).
     *
     * @return array<string,mixed>
     */
    private function parsePerson(): array
    {
        $raw = [];

        // Anrede-Ankreuzzeile ("Frau  4 Herr  Divers  Firma"): die Marke steht
        // direkt VOR der gewaehlten Option. In der digitalen Textebene rendert
        // der Haken als "4"/"X"; liest OCR einen SCAN, kommt er als Haken-
        // Zeichen oder "v"/"V" an - die Zeile selbst ist durch die drei
        // Anreden eindeutig, daher sind die Marken hier unverwechselbar.
        foreach ($this->lines as $line) {
            if (preg_match('/\bFrau\b/u', $line) && preg_match('/\bHerr\b/u', $line) && preg_match('/\bDivers\b/u', $line)) {
                if (preg_match('/[4Xx✓✔vV]\s+Frau\b/u', $line)) {
                    $raw['gender'] = 'female';
                } elseif (preg_match('/[4Xx✓✔vV]\s+Herr\b/u', $line)) {
                    $raw['gender'] = 'male';
                }
                break;
            }
        }

        $raw['last_name'] = $this->valueAbove('Nachname');
        // Die Vorname-Zeile traegt rechts das Geburtsdatum als TTMMJJ:
        // " Mashhour                021180" ueber "Vorname     Geburtsdatum".
        // Das Datum wird auf der GANZEN Zeile gesucht (OCR eines Scans trennt
        // die Spalten oft nur mit EINEM Leerzeichen: "Hussam 210785") und aus
        // dem Namen entfernt.
        $vornameLine = $this->rawValueAbove('Vorname');
        if ($vornameLine !== null) {
            if (preg_match('/\b(\d{2})(\d{2})(\d{2})\b/', $vornameLine, $m)) {
                $yy = (int) $m[3];
                $raw['birth_date'] = ($yy <= 30 ? 2000 + $yy : 1900 + $yy)
                    .'-'.$m[2].'-'.$m[1];
            }
            $first = trim((string) preg_replace('/\s*\b\d{6}\b.*$/u', '', $this->columns($vornameLine)[0] ?? ''));
            $raw['first_name'] = $first !== '' ? $first : null;
        }

        // Strasse + Hausnummer stehen in EINER Wertzeile ueber "Straße ... Hausnummer".
        $streetLine = $this->rawValueAbove('Straße') ?? $this->rawValueAbove('Strasse');
        if ($streetLine !== null) {
            $cols = $this->columns($streetLine);
            if (count($cols) >= 2 && preg_match('/^\d{1,4}\s*[a-zA-Z]?$/', end($cols))) {
                $raw['house_number'] = trim((string) end($cols));
                $raw['street'] = trim($cols[0]);
            } elseif (preg_match('/^(.*\D)\s+(\d+(?:\s*[a-zA-Z])?)\s*$/u', trim($streetLine), $s)) {
                $raw['street'] = trim($s[1]);
                $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
            }
        }

        // PLZ + Ort ueber "Postleitzahl   Ort".
        $zipLine = $this->rawValueAbove('Postleitzahl');
        if ($zipLine !== null && preg_match('/(?<!\d)(\d{5})\s+([A-ZÄÖÜ][\p{L}.\-]+(?:[ \-][A-ZÄÖÜ]?[\p{L}.\-]+)*)/u', $zipLine, $m)) {
            $raw['zip'] = $m[1];
            $raw['city'] = trim((string) preg_replace('/\s{2,}.*$/u', '', $m[2]));
        }

        // Telefon ueber "Telefon- oder Mobilnummer ..." ("0176-32406432").
        $telLine = $this->rawValueAbove('Telefon- oder Mobilnummer');
        if ($telLine !== null) {
            $digits = (string) preg_replace('/[^\d]/', '', $this->columns($telLine)[0] ?? '');
            if (preg_match('/^0\d{9,14}$/', $digits)) {
                $raw['phone'] = $digits;
            }
        }

        // E-Mail ueber "E-Mail-Adresse".
        $mailLine = $this->rawValueAbove('E-Mail-Adresse');
        if ($mailLine !== null && preg_match('/[\w.+\-]+@[\w.\-]+\.\w{2,}/u', $mailLine, $m)) {
            $raw['email'] = strtolower($m[0]);
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseEnergy(): array
    {
        $raw = [];

        // Zaehlernummer / MaLo-ID ueber der Beschriftung "Zählernummer  MaLo-ID".
        // Die beiden Werte koennen auf EINER Zeile stehen ("42811442 - oder -
        // 51214022992") oder auf ZWEI Zeilen umgebrochen sein - deshalb werden
        // bis zu zwei Wertzeilen zusammengefasst.
        $meterLine = $this->rawValuesAbove('Zählernummer', 2) ?? $this->rawValuesAbove('Zaehlernummer', 2);
        if ($meterLine !== null) {
            $clean = str_ireplace(['– oder –', '- oder -'], ' ', $meterLine);
            $nums = [];
            if (preg_match_all('/\b([A-Z0-9]{6,20})\b/u', $clean, $mm)) {
                $nums = $mm[1];
            }
            foreach ($nums as $n) {
                if (preg_match('/^\d{11}$/', $n) && ! isset($raw['malo_id'])) {
                    $raw['malo_id'] = $n; // MaLo-ID ist immer 11-stellig
                } elseif (! isset($raw['meter_number'])) {
                    $raw['meter_number'] = $n;
                }
            }
        }

        // Bisheriger Versorger ("Stadtwerke Rendsburg GmbH" ueber
        // "Derzeitiger Stromversorger"). Ist das Feld LEER, steht darueber die
        // Ankreuz-Zeile des Abschnitts ("Ich möchte LichtBlick ÖkoStrom in
        // meiner/m jetzigen Wohnung/Haus beziehen.") - ein Formularsatz und
        // der Name des NEUEN Anbieters sind nie der bisherige Versorger; dann
        // bleibt das Feld leer statt falsch.
        $prevLine = $this->rawValueAbove('Derzeitiger Strom') ?? $this->rawValueAbove('Derzeitiger Gas');
        if ($prevLine !== null) {
            $prev = trim($this->columns($prevLine)[0] ?? '');
            if (preg_match('/\p{L}{3,}/u', $prev) && mb_strlen($prev) <= 100
                && mb_stripos($prev, 'LichtBlick') === false
                && ! preg_match('/\.\s*$/u', $prev)) {
                $raw['previous_provider'] = $prev;
            }
        }

        // Kundennummer beim BISHERIGEN Versorger + Abschlag im Monat (eine
        // Wertzeile: "200111411    64,24  €").
        $prevNoLine = $this->rawValueAbove('Kundennummer beim derzeitigen');
        if ($prevNoLine !== null) {
            $cols = $this->columns(str_replace('€', ' ', $prevNoLine));
            if (isset($cols[0]) && preg_match('/^\d{4,}$/', $cols[0])) {
                $raw['previous_customer_number'] = $cols[0];
            }
        }

        // Letzter Jahresverbrauch ("1800   kWh"); beim Umzug traegt das
        // Formular stattdessen den GESCHAETZTEN Jahresverbrauch.
        $consLine = $this->rawValueAbove('Letzter Jahres') ?? $this->rawValueAbove('Geschätzter Jahres');
        if ($consLine !== null && preg_match('/([\d.]+)\s*kWh/iu', $consLine, $m)) {
            $raw['consumption_kwh'] = (int) str_replace('.', '', $m[1]);
        }

        // Umzugs-Variante: der Zaehlerstand bei Schluesseluebergabe ist eine
        // echte Ablesung und gehoert in die Akte ("042536 kWh" ueber
        // "Zählerstand bei Schlüsselübergabe").
        $standLine = $this->rawValueAbove('Zählerstand bei Schlüssel') ?? $this->rawValueAbove('Zaehlerstand bei Schluessel');
        if ($standLine !== null && preg_match('/\b0*(\d{1,8})\b\s*kWh/iu', $standLine, $m)) {
            $raw['meter_reading'] = (float) $m[1];
        }

        // Tarifpreise (Brutto-Spalte): "Arbeitspreis: 33,93 Cent/kWh ...",
        // "Grundpreis: 13,35 €/Monat ...".
        if (preg_match('/Arbeitspreis:\s*([\d.]+,\d{2})\s*Cent/u', $this->text(), $m)) {
            $raw['working_price'] = (float) str_replace(',', '.', $m[1]);
        }
        if (preg_match('/Grundpreis:\s*([\d.]+,\d{2})\s*€\s*\/\s*Monat/u', $this->text(), $m)) {
            $raw['base_price'] = (float) str_replace(',', '.', $m[1]);
        }

        // Tarif aus der Kopfzeile ("LichtBlick ÖkoStrom" / "LichtBlick ÖkoGas").
        if (preg_match('/LichtBlick\s+(Öko\p{L}+)/u', $this->text(), $m)) {
            $raw['tariff'] = 'LichtBlick '.$m[1];
        }

        return $this->validatedEnergy(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * @param array<string,mixed> $energie
     * @return array<string,mixed>
     */
    private function parseInsurance(string $upper, array $energie): array
    {
        $raw = [
            // Auftragsnummer = Referenz des Vorgangs (KEINE Vertragsnummer,
            // siehe Hinweis unten) - Bruecke zur Vertragsbestaetigung.
            'reference_number' => $this->orderNumber(),
            'insurer' => 'LichtBlick',
            'sparte' => str_contains($upper, 'ÖKOGAS') || str_contains($upper, 'OEKOGAS') ? 'gas' : 'strom',
            // Auftrag = Angebot des Kunden, noch keine Bestaetigung.
            'document_stage' => Contract::STAGE_ANTRAG,
            'tariff' => $energie['tariff'] ?? null,
        ];

        // HINWEIS zur Auftragsnummer (Wert ueber "UVP"): sie wird BEWUSST NICHT
        // als Vertragsnummer gefuehrt (Betreiber-Vorgabe 02.08.2026) - ein
        // Auftrag hat noch keine Vertragsnummer, und eine Auftragsnummer in
        // diesem Feld ist eine falsche Angabe in der Kundenakte. Sie steht in
        // der Zusammenfassung; die spaetere Vertragsbestaetigung bringt die
        // echte Nummer und findet ihren Vertrag ueber MaLo-ID/Zaehlernummer
        // (Auftrag-zuerst-System).

        // Ausdruecklich gewuenschter Lieferbeginn - meist LEER
        // (= naechstmoeglich). Das Datum kann in derselben Zeile stehen, in
        // der Wertzeile DARUEBER (digitale Textebene) oder - bei der
        // Formularvariante mit Eintrag unter der Ueberschrift bzw. im OCR
        // eines Scans - direkt DARUNTER.
        $start = null;
        foreach ($this->lines as $i => $line) {
            if (mb_stripos($line, 'Datum des Lieferbeginns') === false) {
                continue;
            }
            foreach ([$line, $this->lines[$i - 1] ?? '', $this->lines[$i + 1] ?? ''] as $cand) {
                if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $cand, $m)) {
                    $start = $m[3].'-'.$m[2].'-'.$m[1];
                    break 2;
                }
            }
            break;
        }
        if ($start !== null) {
            $raw['start_date'] = $start;
        } elseif (preg_match('/stadtwerke/i', $energie['previous_provider'] ?? '')) {
            // Kein Lieferbeginn angegeben + Wechsel von den Stadtwerken:
            // 14 Tage Kuendigungsfrist + Bearbeitung -> Beginn spaetestens
            // ~20 Tage nach Einreichung (Betreiber-Regel). Das konkrete Datum
            // setzt die Vertragsanlage relativ zum Upload-Tag.
            $raw['expected_start_within_days'] = self::EXPECTED_START_DAYS;
        }

        // Erwarteter Monatsabschlag: nur uebernehmen, wenn der Formularwert
        // ("Abschlag im Monat") rechnerisch zum NEUEN Tarif passt (Arbeitspreis
        // x Verbrauch / 12 + Grundpreis) - sonst waere es der alte Abschlag
        // des bisherigen Versorgers, der nicht in den neuen Vertrag gehoert.
        $abschlag = null;
        $prevNoLine = $this->rawValueAbove('Kundennummer beim derzeitigen');
        if ($prevNoLine !== null && preg_match('/(\d{1,3}(?:\.\d{3})*,\d{2})/', $prevNoLine, $m)) {
            $abschlag = (float) str_replace(['.', ','], ['', '.'], $m[1]);
        }
        if ($abschlag !== null
            && isset($energie['working_price'], $energie['base_price'], $energie['consumption_kwh'])) {
            $calc = $energie['working_price'] * $energie['consumption_kwh'] / 100 / 12 + $energie['base_price'];
            if (abs($calc - $abschlag) <= 0.5) {
                $raw['premium_amount'] = $abschlag;
                $raw['premium_interval'] = 'monthly';
            }
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Kunden-IBAN aus dem SEPA-Block (rechte Spalte). Die deutsche IBAN im
     * Dokument ist normalerweise die des Kunden - die Kreditor-ID der
     * LichtBlick ("DE41ZZZ...") faellt durch das Nur-Ziffern-Muster.
     *
     * Nennt das Formular jedoch AUSDRUECKLICH einen abweichenden Kontoinhaber
     * (Dritt-/Firmenkonto), wird die IBAN NICHT uebernommen - sonst landet ein
     * Fremdkonto in der Kundenakte (CLAUDE.md: "IBAN nur, wenn der
     * Kontoinhaber der Antragsteller ist"; Audit PARSER-2). Fehlt ein
     * Kontoinhaber-Feld (Standardlayout), gilt die Eigenauftrags-Annahme.
     *
     * @param array<string,mixed> $person
     * @return array<string,mixed>
     */
    private function parseBank(array $person = []): array
    {
        $raw = [];
        if (preg_match('/\bDE\d{2}(?:[ ]?\d){18}\b/', $this->text(), $m)) {
            $last = $person['last_name'] ?? null;
            if ($last !== null && ($holder = $this->accountHolderName()) !== null
                && mb_stripos($holder, (string) $last) === false) {
                return $this->validatedBank([]); // abweichender Kontoinhaber -> keine IBAN
            }
            $raw['iban'] = strtoupper((string) preg_replace('/\s+/', '', $m[0]));
        }
        return $this->validatedBank($raw);
    }

    /** Explizit genannter Kontoinhaber, falls das Formular ihn auffuehrt. */
    private function accountHolderName(): ?string
    {
        foreach ($this->lines as $i => $line) {
            if (preg_match('/^\s*Kontoinhaber(?:in)?\b\s*:?\s*(.*)$/u', $line, $m)) {
                $val = trim($m[1]);
                if ($val !== '') {
                    return $val;
                }
                // Wert steht (Spaltenlayout) in der Zeile DARUEBER.
                if ($i > 0 && trim($this->lines[$i - 1]) !== '') {
                    return trim($this->lines[$i - 1]);
                }
            }
        }
        return null;
    }

    /**
     * Rechts der Formularspalte beginnt der AGB-/Vertragstext (zweite Spalte,
     * ab ca. Zeichen 110). Beschriftungen und Werte des Formulars werden daher
     * NUR im linken Bereich gesucht - sonst wuerde eine reine Rechtstext-Zeile
     * als "Wert ueber dem Label" fehlgelesen (die Labels wie "Nachname" tauchen
     * auch im rechten SEPA-/AGB-Block auf).
     */
    private const LEFT_COLUMN_END = 105;

    /**
     * Erste nicht-leere LINKE-Spalten-Zeile UEBER der ersten Zeile, deren
     * linke Spalte mit $label beginnt. Rechte-Spalten-Zeilen (Einrueckung
     * jenseits der Formularspalte) werden uebersprungen; der Rueckgabewert ist
     * auf die linke Spalte beschnitten.
     */
    private function rawValueAbove(string $label): ?string
    {
        foreach ($this->lines as $i => $line) {
            if ($this->leadingSpaces($line) >= 40 || ! str_starts_with(trim($line), $label)) {
                continue;
            }
            for ($j = $i - 1; $j >= 0; $j--) {
                $cand = $this->lines[$j];
                if (trim($cand) === '' || $this->leadingSpaces($cand) >= self::LEFT_COLUMN_END) {
                    continue; // leer oder reine Rechte-Spalten-Zeile
                }
                return rtrim(mb_substr($cand, 0, self::LEFT_COLUMN_END));
            }
            return null;
        }
        return null;
    }

    /**
     * Bis zu $count nicht-leere LINKE-Spalten-Zeilen ueber dem Label,
     * zu EINEM String verbunden (fuer Werte, die ueber zwei Zeilen
     * umbrechen, z.B. Zaehlernummer + MaLo-ID).
     */
    private function rawValuesAbove(string $label, int $count): ?string
    {
        foreach ($this->lines as $i => $line) {
            if ($this->leadingSpaces($line) >= 40 || ! str_starts_with(trim($line), $label)) {
                continue;
            }
            $vals = [];
            for ($j = $i - 1; $j >= 0 && count($vals) < $count; $j--) {
                $cand = $this->lines[$j];
                if (trim($cand) === '' || $this->leadingSpaces($cand) >= self::LEFT_COLUMN_END) {
                    // Leere/Rechte-Spalten-Zeile beendet den Block nicht sofort,
                    // wird aber nur EINMAL uebersprungen (sonst wuerden entfernte
                    // Formularteile faelschlich zusammengezogen).
                    if ($vals !== []) {
                        break;
                    }
                    continue;
                }
                $vals[] = rtrim(mb_substr($cand, 0, self::LEFT_COLUMN_END));
            }
            return $vals === [] ? null : implode(' ', array_reverse($vals));
        }
        return null;
    }

    /**
     * Auftragsnummer aus dem Kopf (Wert ueber "UVP") - nur fuer die
     * Zusammenfassung, sie ist KEINE Vertragsnummer.
     */
    private function orderNumber(): ?string
    {
        $line = $this->rightValueAbove('UVP');
        return ($line !== null && preg_match('/^(\d{5,12})$/', trim($line), $m)) ? $m[1] : null;
    }

    /** Wie rawValueAbove, aber nur die erste Spalte als getrimmter Wert. */
    private function valueAbove(string $label): ?string
    {
        $line = $this->rawValueAbove($label);
        if ($line === null) {
            return null;
        }
        $val = trim($this->columns($line)[0] ?? '');
        return $val !== '' ? $val : null;
    }

    /**
     * Wert ueber einem Label der RECHTEN Spalte (z.B. "UVP" mit der
     * Auftragsnummer darueber) - ohne Links-Beschraenkung.
     */
    private function rightValueAbove(string $label): ?string
    {
        foreach ($this->lines as $i => $line) {
            if (trim($line) !== $label) {
                continue;
            }
            for ($j = $i - 1; $j >= 0; $j--) {
                if (trim($this->lines[$j]) !== '') {
                    return trim($this->lines[$j]);
                }
            }
            return null;
        }
        return null;
    }

    private function leadingSpaces(string $line): int
    {
        return mb_strlen($line) - mb_strlen(ltrim($line));
    }

    /** @return list<string> */
    private function columns(string $line): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\s{2,}/', trim($line)) ?: []),
            fn ($c) => $c !== ''
        ));
    }

    private function displayDate(string $iso): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) ? $m[3].'.'.$m[2].'.'.$m[1] : $iso;
    }

    private function text(): string
    {
        return implode("\n", $this->lines);
    }
}
