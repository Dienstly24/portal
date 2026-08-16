<?php
namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Parser fuer die AUFTRAGS-UEBERSICHT aus dem Vertriebsportal eines
 * Energie-Vergleichsportals (Bildschirmfoto, z.B. RheinEnergie AG "Fair
 * Ökostrom 24"). Der Betrieb arbeitet den Auftrag dort ab und laedt die
 * Uebersicht als SCREENSHOT hoch - alle Kern-Daten stehen beschriftet da:
 *
 *   Kopf        : "<Auftragsnummer> - <Anbieter> - <Produkt>" + "Herr <Name>"
 *   Tarif       : Anbieter / Produkt / Abnehmer / Tariftyp
 *   Tarifdaten  : Grundpreis, Arbeitspreis, Laufzeit, Preisgarantie
 *   Person      : Block "Belieferungsanschrift" (Name, Anschrift, geboren am,
 *                 Tel, Mail)
 *   Bank        : Block "Anschrift des Kontoinhaber" (IBAN/BIC)
 *   Belieferung : Auftragsnummer, Netzbetreiber, MaLo-ID, Vorjahresverbrauch,
 *                 Zaehlernummer, bish. Kundennummer, Vorversorger, Status
 *
 * Regeln (Betreiber-Vorgaben):
 *  - Ein AUFTRAG hat KEINE Vertragsnummer: die Auftragsnummer steht nur in
 *    der Zusammenfassung (Stufe 'antrag'); die spaetere Vertragsbestaetigung
 *    bringt die echte Nummer und findet ihren Vertrag ueber MaLo-ID/
 *    Zaehlernummer.
 *  - Kein geschaetzter Lieferbeginn - Ausnahme Stadtwerke-Wechsel: steht
 *    kein Datum ("schnellstmoeglich") und ist der Vorversorger ein
 *    Stadtwerk, gilt die 14-Tage-Frist + Bearbeitung (~20 Tage).
 *  - IBAN nur, wenn der Kontoinhaber-Block auf DIESELBE Person laeuft.
 *  - Der Grundpreis steht hier je JAHR; die Kundenakte fuehrt ihn je Monat -
 *    umgerechnet wird deterministisch (/12), beide Werte stehen in der
 *    Zusammenfassung.
 *
 * Screenshot-OCR kennt kein Spaltenraster: eine Zeile kann Zellen aus
 * mehreren Spalten tragen ("Anbieter  RheinEnergie AG  24768 Rendsburg").
 * Gelesen wird deshalb zellenweise (Trennung am Spaltenabstand).
 */
class EnergiePortalAuftragParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /** Stadtwerke-Wechsel: 14 Tage Kuendigungsfrist + Bearbeitung. */
    private const EXPECTED_START_DAYS = 20;

    private string $text = '';

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $this->text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $upper = mb_strtoupper($this->text);

        // Nur diese Portal-Uebersicht: die Auftragsnummer-Beschriftung UND
        // eine der Portal-Ueberschriften. Die PDF-Auftraege der Versorger
        // (EWE, LichtBlick, PLAN-B) haben eigene Parser und tragen diese
        // Kombination nicht.
        if (!str_contains($upper, 'AUFTRAGSNUMMER')
            || (!str_contains($upper, 'TARIFÜBERSICHT')
                && !str_contains($upper, 'BELIEFERUNGSANSCHRIFT'))) {
            return null;
        }

        $this->lines = array_map('rtrim', preg_split('/\R/', $this->text) ?: []);

        $energie = $this->parseEnergy();
        $person = $this->parsePerson();
        if ($energie === [] && $person === []) {
            return null;
        }
        $bank = $this->parseBank($person);
        $insurance = $this->parseContract($energie);

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $art = ($insurance['sparte'] ?? 'strom') === 'gas' ? 'Gas' : 'Strom';

        return [
            'type' => 'energieauftrag',
            'confidence' => 72,
            'summary' => $art . '-Auftrag (Vertriebsportal)'
                . (isset($insurance['insurer']) ? ' - ' . $insurance['insurer'] : '')
                . (isset($energie['tariff']) ? ' (' . $energie['tariff'] . ')' : '')
                . ($name !== '' ? ' - ' . $name : '')
                . $this->extras($energie, $insurance)
                . ($bank !== [] ? ' Bankverbindung des Kunden uebernommen.' : ' Ohne Bankuebernahme.')
                . ' Felder gratis aus der Auftragsuebersicht gelesen (ohne KI).',
            'title' => ($insurance['insurer'] ?? 'Energie') . ' ' . $art . '-Auftrag'
                . ($name !== '' ? ' ' . $name : ''),
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

    /** @return array<string,mixed> */
    private function parsePerson(): array
    {
        $raw = [];

        // Name: eigene Zeile "Herr <Vorname> <Nachname>" (Kopf der Ansicht
        // bzw. Anschriftenblock). Letztes Namenswort = Nachname.
        $nameLine = null;
        foreach ($this->lines as $i => $line) {
            foreach ($this->cells($line) as $cell) {
                if (preg_match('/^(Herrn?|Frau)\s+(\p{L}[\p{L}\-\']+(?:\s+\p{L}[\p{L}\-\']+){1,3})$/u', $cell, $m)) {
                    $raw['gender'] = mb_strtolower($m[1]) === 'frau' ? 'female' : 'male';
                    $parts = preg_split('/\s+/u', $m[2]) ?: [];
                    $raw['last_name'] = array_pop($parts);
                    $raw['first_name'] = implode(' ', $parts) ?: null;
                    $nameLine = $nameLine ?? $i;
                    break 2;
                }
            }
        }

        // Anschrift: unter dem Anschriftenblock bzw. unter dem Namen. Beide
        // Anker werden probiert (die Screenshot-OCR ordnet die Spalten mal
        // nebeneinander, mal blockweise untereinander).
        $anker = [];
        foreach ($this->lines as $i => $line) {
            foreach ($this->cells($line) as $cell) {
                if (mb_stripos($cell, 'Belieferungsanschrift') !== false
                    || ($nameLine !== null && isset($raw['last_name'])
                        && preg_match('/^(?:Herrn?|Frau)\s+.*' . preg_quote((string) $raw['last_name'], '/') . '$/u', $cell))) {
                    $anker[] = $i;
                    break;
                }
            }
        }
        foreach ($anker as $start) {
            $found = [];
            $end = min(count($this->lines), $start + 7);
            for ($j = $start + 1; $j < $end; $j++) {
                foreach ($this->cells($this->lines[$j]) as $cell) {
                    if (!isset($found['street'])
                        && preg_match('/^(\p{L}[\p{L}.\-\' ]*\p{L}\.?)\s+(\d+\s*[a-zA-Z]?)$/u', $cell, $s)
                        && $this->looksLikeStreet($s[1])) {
                        $found['street'] = trim($s[1]);
                        $found['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
                        continue;
                    }
                    if (!isset($found['zip']) && preg_match('/^(\d{5})\s+(\p{L}[\p{L}.\- ]*)$/u', $cell, $z)) {
                        $found['zip'] = $z[1];
                        $found['city'] = trim($z[2]);
                    }
                }
                if (isset($found['street'], $found['zip'])) {
                    break;
                }
            }
            // Nur ein VOLLSTAENDIGER Block (Strasse UND PLZ/Ort) zaehlt -
            // sonst waere z.B. die Reiterleiste ("Dokumente 1") eine Adresse.
            if (isset($found['street'], $found['zip'])) {
                $raw += $found;
                break;
            }
        }

        if (($v = $this->labelValue('geboren am')) !== null
            && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $v, $m)) {
            $raw['birth_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        if (($v = $this->labelValue('Tel')) !== null) {
            $raw['phone'] = $this->normalizePhone($v);
        }
        if (($v = $this->labelValue('Mail') ?? $this->labelValue('E-Mail')) !== null
            && preg_match('/[\w.+\-]+@[\w.\-]+\.\w{2,}/u', $v, $m)) {
            $raw['email'] = mb_strtolower($m[0]);
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * IBAN/BIC nur, wenn der Block "Anschrift des Kontoinhaber" auf DIESELBE
     * Person laeuft - ein fremdes Konto gehoert nicht in die Kundenakte.
     *
     * @param array<string,mixed> $person
     * @return array<string,mixed>
     */
    private function parseBank(array $person): array
    {
        $last = trim((string) ($person['last_name'] ?? ''));
        $first = trim((string) ($person['first_name'] ?? ''));
        if ($last === '' || $first === '') {
            return [];
        }

        // Der Name unter der Ueberschrift "Anschrift des Kontoinhaber" zaehlt -
        // und zwar SPALTENGENAU: die Belieferungsanschrift steht in derselben
        // Zeile weiter links und wuerde sonst jeden fremden Kontoinhaber
        // uebertoenen. Verglichen wird daher die Zelle, die unter der
        // Ueberschrift beginnt.
        $gehoertKunde = false;
        foreach ($this->lines as $i => $line) {
            $spalte = null;
            foreach ($this->cellsWithOffsets($line) as [$cell, $offset]) {
                if (mb_stripos($cell, 'Kontoinhaber') !== false) {
                    $spalte = $offset;
                    break;
                }
            }
            if ($spalte === null) {
                continue;
            }

            $end = min(count($this->lines), $i + 7);
            for ($j = $i + 1; $j < $end; $j++) {
                foreach ($this->cellsWithOffsets($this->lines[$j]) as [$cell, $offset]) {
                    if (abs($offset - $spalte) > 30) {
                        continue; // andere Spalte
                    }
                    $hay = mb_strtolower($cell);
                    if (str_contains($hay, mb_strtolower($last)) && str_contains($hay, mb_strtolower($first))) {
                        $gehoertKunde = true;
                    } elseif (preg_match('/^(?:Herrn?|Frau)\s+\p{L}/u', $cell)) {
                        return []; // dort steht ein ANDERER Kontoinhaber
                    }
                }
                if ($gehoertKunde) {
                    break;
                }
            }
            if ($gehoertKunde) {
                break;
            }
        }
        if (!$gehoertKunde) {
            return [];
        }

        $raw = [];
        if (($v = $this->labelValue('IBAN')) !== null) {
            $iban = strtoupper((string) preg_replace('/\s+/', '', $v));
            if (preg_match('/^DE\d{20}$/', $iban)) {
                $raw['iban'] = $iban;
            }
        }
        if (($v = $this->labelValue('BIC')) !== null && preg_match('/^[A-Z0-9]{8,11}$/', strtoupper(trim($v)))) {
            $raw['bic'] = strtoupper(trim($v));
        }
        if ($raw !== []) {
            $raw['account_holder'] = trim($first . ' ' . $last);
        }

        return $this->validatedBank($raw);
    }

    /** @return array<string,mixed> */
    private function parseEnergy(): array
    {
        $raw = [];

        $raw['tariff'] = $this->labelValue('Produkt');
        $raw['grid_operator'] = $this->labelValue('Netzbetreiber');
        $raw['previous_provider'] = $this->labelValue('Vorversorger');

        if (($v = $this->labelValue('MaLo-ID') ?? $this->labelValue('MaLo')) !== null
            && preg_match('/\b(\d{11})\b/', $v, $m)) {
            $raw['malo_id'] = $m[1];
        }
        if (($v = $this->labelValue('Zählernummer')) !== null
            && preg_match('/^[\w\- ]{4,30}$/u', trim($v))) {
            $raw['meter_number'] = trim($v);
        }
        // Kundennummer beim BISHERIGEN Versorger (das leere Formularfeld
        // "Kundennummer" der Eingabemaske bleibt unberuehrt).
        if (($v = $this->labelValue('bish. Kundennummer') ?? $this->labelValue('bisherige Kundennummer')) !== null
            && preg_match('/^[\w\-]{4,40}$/u', trim($v))) {
            $raw['previous_customer_number'] = trim($v);
        }
        // Vorjahresverbrauch (ggf. mit Zaehlwerk-Kuerzel "HT"/"NT").
        if (preg_match('/Vorjahresverbrauch(?:\s+[A-Z]{2})?\s*:?\s{2,}([\d.]+)\s*kWh/u', $this->text, $m)) {
            $raw['consumption_kwh'] = (int) str_replace('.', '', $m[1]);
        }
        // Arbeitspreis "32,45 ct / kWh".
        if (($v = $this->labelValue('Arbeitspreis')) !== null
            && preg_match('/([\d.]+,\d+)\s*(?:ct|cent)/iu', $v, $m)) {
            $raw['working_price'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
        }
        // Grundpreis: die Kundenakte fuehrt ihn je MONAT - "je Jahr" wird
        // deterministisch umgerechnet (beide Werte in der Zusammenfassung).
        [$monat] = $this->grundpreis();
        if ($monat !== null) {
            $raw['base_price'] = $monat;
        }

        return $this->validatedEnergy(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Grundpreis als [EUR/Monat, Originaltext] - "167,80 € / Jahr" wird zu
     * 13,98 EUR/Monat, "13,35 €/Monat" bleibt unveraendert.
     *
     * @return array{0: ?float, 1: ?string}
     */
    private function grundpreis(): array
    {
        $v = $this->labelValue('Grundpreis');
        if ($v === null || !preg_match('/([\d.]+,\d{2})/', $v, $m)) {
            return [null, null];
        }
        $betrag = (float) str_replace(['.', ','], ['', '.'], $m[1]);
        if (preg_match('/Jahr/iu', $v)) {
            return [round($betrag / 12, 2), trim($v)];
        }
        return [$betrag, trim($v)];
    }

    /**
     * @param array<string,mixed> $energie
     * @return array<string,mixed>
     */
    private function parseContract(array $energie): array
    {
        $raw = [
            'insurer' => $this->labelValue('Anbieter'),
            'tariff' => $energie['tariff'] ?? null,
            // Ein Auftrag ist noch keine Bestaetigung.
            'document_stage' => Contract::STAGE_ANTRAG,
        ];

        // Sparte aus dem Feld "Tariftyp" (nicht aus Stichwoertern im Text).
        $typ = mb_strtolower((string) ($this->labelValue('Tariftyp') ?? ''));
        $raw['sparte'] = match (true) {
            str_contains($typ, 'gas')   => 'gas',
            str_contains($typ, 'strom') => 'strom',
            default                     => str_contains(mb_strtolower((string) ($energie['tariff'] ?? '')), 'gas')
                ? 'gas' : 'strom',
        };

        // Lieferbeginn NUR als echtes Datum ("schnellstmoeglich" ist keins).
        $lieferdatum = $this->labelValue('gew. Lieferdatum') ?? $this->labelValue('Lieferdatum');
        if ($lieferdatum !== null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $lieferdatum, $m)) {
            $raw['start_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        } elseif (preg_match('/stadtwerke/iu', (string) ($energie['previous_provider'] ?? ''))) {
            // Stadtwerke-Wechsel: 14 Tage Kuendigungsfrist + Bearbeitung.
            $raw['expected_start_within_days'] = self::EXPECTED_START_DAYS;
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Zusatzangaben fuer die Zusammenfassung - darunter die Auftragsnummer,
     * die BEWUSST keine Vertragsnummer ist.
     *
     * @param array<string,mixed> $energie
     * @param array<string,mixed> $insurance
     */
    private function extras(array $energie, array $insurance): string
    {
        $out = '.';
        if (($v = $this->labelValue('Auftragsnummer')) !== null && preg_match('/^[\w\-]{4,30}$/u', trim($v))) {
            $out .= ' Auftragsnummer ' . trim($v) . ' (keine Vertragsnummer - die bringt erst die Vertragsbestaetigung).';
        }
        if (isset($energie['previous_provider'])) {
            $out .= ' Wechsel von ' . $energie['previous_provider']
                . (isset($energie['previous_customer_number']) ? ' (Kd.-Nr. ' . $energie['previous_customer_number'] . ')' : '')
                . '.';
        }
        if (isset($energie['consumption_kwh'])) {
            $out .= ' Vorjahresverbrauch ' . number_format($energie['consumption_kwh'], 0, ',', '.') . ' kWh/Jahr.';
        }
        [$monat, $original] = $this->grundpreis();
        if ($monat !== null && $original !== null) {
            $out .= ' Grundpreis ' . $original
                . (preg_match('/Jahr/iu', $original)
                    ? ' = ' . number_format($monat, 2, ',', '.') . ' EUR/Monat (fuer die Kundenakte umgerechnet)'
                    : '')
                . '.';
        }
        foreach ([
            'Vertragslaufzeit' => '/(\d+)\s+Monate?\s+Vertragslaufzeit/u',
            'Preisgarantie' => '/(\d+)\s+Monate?\s+[\w\-]*Preisgarantie/u',
            'Kündigungsfrist' => '/(\d+)\s+Monate?\s+Kündigungsfrist/u',
        ] as $label => $re) {
            if (preg_match($re, $this->text, $m)) {
                $out .= ' ' . $label . ': ' . $m[1] . ' Monat' . ((int) $m[1] === 1 ? '' : 'e') . '.';
            }
        }
        if (($v = $this->labelValue('Status')) !== null) {
            $out .= ' Portal-Status: ' . $v . '.';
        }
        if (($v = $this->labelValue('Unterschriftsdatum')) !== null) {
            $out .= ' Unterschrieben am ' . $v . '.';
        }
        if (($v = $this->labelValue('Abnehmer')) !== null) {
            $out .= ' Abnehmer: ' . $v . '.';
        }
        if (($v = $this->labelValue('Zahlung')) !== null) {
            $out .= ' Zahlung: ' . $v . '.';
        }
        if (isset($insurance['expected_start_within_days'])) {
            $out .= ' Lieferbeginn nicht angegeben: voraussichtlich binnen ~'
                . $insurance['expected_start_within_days']
                . ' Tagen (Kuendigungsfrist Stadtwerke 14 Tage + Bearbeitung).';
        }
        return $out;
    }

    /**
     * Sieht die Zelle wie ein Strassenname aus? Entweder mit typischem
     * Grundwort (Strasse/Weg/Allee ...) oder mehrwortig - so wird die
     * Reiterleiste des Portals ("Dokumente 1") nie zur Anschrift.
     */
    private function looksLikeStreet(string $name): bool
    {
        $n = trim($name);
        if (preg_match('/(stra(?:ß|ss)e|str\.|weg|allee|platz|ring|gasse|damm|chaussee|ufer|steig|kamp|hof|feld)$/iu', $n)) {
            return true;
        }
        return preg_match('/\p{L}{3,}/u', $n) === 1 && count(preg_split('/\s+/u', $n) ?: []) >= 2;
    }

    /** "+49 0176 23681009" -> "017623681009"; sonst null. */
    private function normalizePhone(string $value): ?string
    {
        $d = (string) preg_replace('/[^\d+]/', '', $value);
        if (preg_match('/^(?:\+|00)49(\d+)$/', $d, $m)) {
            $d = '0' . ltrim($m[1], '0');
        }
        return preg_match('/^0\d{8,14}$/', $d) ? $d : null;
    }

    /**
     * Wert hinter einer Beschriftung - ZELLENWEISE gesucht, denn im
     * Screenshot steht eine Beschriftung selten am Zeilenanfang: die Zeile
     * traegt oft zuerst eine Zelle der linken Spalte ("Abnehmer  Privat  Mail:
     * kunde@example.com"). Zwei Bauformen: Beschriftung und Wert in EINER
     * Zelle ("Mail: kunde@example.com") oder Beschriftung allein, Wert in der
     * naechsten Zelle ("Grundpreis" | "167,80 € / Jahr").
     */
    private function labelValue(string $label): ?string
    {
        $re = '/^' . preg_quote($label, '/') . '(?=[\s:]|$)\s*:?\s*(.*)$/u';
        foreach ($this->lines as $line) {
            $cells = $this->cells($line);
            foreach ($cells as $k => $cell) {
                if (!preg_match($re, $cell, $m)) {
                    continue;
                }
                $rest = trim($m[1]);
                if ($rest !== '') {
                    return $rest;
                }
                if (isset($cells[$k + 1]) && trim($cells[$k + 1]) !== '') {
                    return trim($cells[$k + 1]);
                }
            }
        }
        return null;
    }

    /**
     * Zellen einer Zeile (Trennung am Spaltenabstand). Doppelt gesetzte
     * Zeilen ("Herr X   Herr X" aus zwei Spalten) bleiben dadurch je Zelle
     * sauber lesbar.
     *
     * @return list<string>
     */
    private function cells(string $line): array
    {
        return array_map(fn (array $c) => $c[0], $this->cellsWithOffsets($line));
    }

    /**
     * Zellen MIT ihrer Startspalte (Zeichenposition) - noetig, um Werte der
     * richtigen Spalte zuzuordnen (z.B. den Kontoinhaber rechts, nicht die
     * Belieferungsanschrift in der Mitte derselben Zeile).
     *
     * @return list<array{0: string, 1: int}>
     */
    private function cellsWithOffsets(string $line): array
    {
        $parts = preg_split('/\s{2,}/u', $line, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        $out = [];
        foreach ($parts as [$text, $byteOffset]) {
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            // Byte- in Zeichenposition umrechnen (Umlaute verschieben sonst
            // den Spaltenvergleich).
            $out[] = [$text, mb_strlen(substr($line, 0, $byteOffset))];
        }
        return $out;
    }
}
