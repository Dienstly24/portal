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
 * LEHRE AUS DEM ECHTEN LAUF (16.08.2026): Screenshot-OCR erhaelt das
 * Spaltenraster NICHT. Die drei nebeneinander stehenden Spalten landen in
 * EINER Zeile - oft nur durch EIN Leerzeichen getrennt:
 *
 *   "Produkt Fair Ökostrom 24 IBAN: DE82..."      (Tarif + Bankspalte)
 *   "Herr Max Muster Herr Max Muster"             (Anschrift + Kontoinhaber)
 *   "1 Monat Kündigungsfrist MaLo-ID 51214126166" (Tarifdaten + Belieferung)
 *
 * Deshalb wird NICHT auf Spaltenabstaende vertraut, sondern auf das
 * BESCHRIFTUNGS-VOKABULAR dieser Ansicht: eine Beschriftung darf mitten in
 * der Zeile stehen, und ihr Wert endet dort, wo die naechste bekannte
 * Beschriftung beginnt. Doppelt gesetzte Texte ("X X" aus zwei Spalten)
 * werden zusammengefasst.
 *
 * Weitere Regeln (Betreiber-Vorgaben):
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
 */
class EnergiePortalAuftragParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /** Stadtwerke-Wechsel: 14 Tage Kuendigungsfrist + Bearbeitung. */
    private const EXPECTED_START_DAYS = 20;

    /**
     * Beschriftungen dieser Portal-Ansicht - LAENGSTE ZUERST, damit
     * "bish. Kundennummer" vor "Kundennummer" und "Anschrift des
     * Kontoinhaber" vor "Kontoinhaber" greift. Sie dienen doppelt: als
     * Suchbegriff und als ENDE-Marke des davorstehenden Wertes.
     *
     * @var list<string>
     */
    private const KNOWN_LABELS = [
        'Anschrift des Kontoinhaber', 'Belieferungsanschrift',
        'bish. Kundennummer', 'bisherige Kundennummer', 'Vorjahresverbrauch',
        'Unterschriftsdatum', 'gew. Lieferdatum', 'Zählernummer', 'Netzbetreiber',
        'Auftragsnummer', 'Kundennummer', 'Vorversorger', 'Tarifübersicht',
        'Arbeitspreis', 'Kontoinhaber', 'Zusatzinfos', 'Lieferdatum',
        'Grundpreis', 'Belieferung', 'Tarifdaten', 'geboren am', 'Telefon',
        'Abnehmer', 'Tariftyp', 'Anbieter', 'Zahlung', 'Produkt', 'MaLo-ID',
        'Status', 'E-Mail', 'Konto', 'MaLo', 'Mail', 'IBAN', 'BLZ', 'BIC', 'Tel',
        // Bedienelemente der Ansicht - kein Inhalt, aber sie stehen in
        // derselben OCR-Zeile und wuerden sonst als Anschrift gelesen
        // ("Übersicht Dokumente 1 Anfrage zum Vertrag").
        'Anfrage zum Vertrag', 'Dokumente', 'Übersicht',
    ];

    private string $text = '';

    /** @var list<string> */
    private array $lines = [];

    /** Hinweis zur Bankverbindung fuer die Zusammenfassung (Abweichung o.ae.). */
    private ?string $bankHinweis = null;

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
                . ($this->bankHinweis !== null ? ' HINWEIS: ' . $this->bankHinweis . '.' : '')
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

        // Name: "Herr <Vorname(n)> <Nachname>" - irgendwo in der Zeile, denn
        // links davon kann eine Zelle der Nachbarspalte stehen ("Tarifübersicht
        // Herr Max Muster"). Ein direkt folgendes zweites "Herr ..." ist die
        // Kontoinhaber-Spalte und gehoert NICHT zum Namen.
        $nameLine = null;
        foreach ($this->lines as $i => $line) {
            if (preg_match($this->nameRegex(), $line, $m)) {
                $raw['gender'] = mb_strtolower($m[1]) === 'frau' ? 'female' : 'male';
                $parts = preg_split('/\s+/u', trim($m[2])) ?: [];
                $raw['last_name'] = array_pop($parts);
                $raw['first_name'] = implode(' ', $parts) ?: null;
                $nameLine = $i;
                break;
            }
        }

        // Anschrift: unter dem Anschriftenblock bzw. unter dem Namen. Beide
        // Anker werden probiert (die OCR ordnet die Spalten mal nebeneinander,
        // mal blockweise untereinander).
        $anker = [];
        foreach ($this->lines as $i => $line) {
            if (mb_stripos($line, 'Belieferungsanschrift') !== false
                || ($nameLine !== null && preg_match($this->nameRegex(), $line))) {
                $anker[] = $i;
            }
        }
        foreach ($anker as $start) {
            $found = [];
            $end = min(count($this->lines), $start + 7);
            for ($j = $start + 1; $j < $end; $j++) {
                foreach ($this->cells($this->lines[$j]) as $cell) {
                    if (!isset($found['street'])
                        && preg_match('/(\p{Lu}[\p{L}.\-\']*(?:\s+\p{L}[\p{L}.\-\']*){0,3})\s+(\d{1,4}\s*[a-zA-Z]?)(?![\p{L}\d])/u', $cell, $s)
                        && $this->looksLikeStreet($s[1])) {
                        $found['street'] = trim($s[1]);
                        $found['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
                        continue;
                    }
                    if (!isset($found['zip'])
                        && preg_match('/(?<!\d)(\d{5})\s+(\p{Lu}[\p{L}.\-]+(?:[ \-]\p{Lu}?[\p{L}.\-]+){0,2})/u', $cell, $z)) {
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
        if (($v = $this->labelValue('Tel') ?? $this->labelValue('Telefon')) !== null) {
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
     * Verglichen werden ALLE Namen, die unter der Ueberschrift stehen: taucht
     * dort ein anderer Name auf, bleibt die Bankverbindung draussen (auch
     * wenn daneben - in der Nachbarspalte derselben Zeile - der Kundenname
     * steht).
     *
     * @param array<string,mixed> $person
     * @return array<string,mixed>
     */
    private function parseBank(array $person): array
    {
        $voll = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        if ($voll === '' || ($person['last_name'] ?? '') === '') {
            return [];
        }

        $gehoertKunde = false;
        foreach ($this->lines as $i => $line) {
            if (mb_stripos($line, 'Kontoinhaber') === false) {
                continue;
            }
            $end = min(count($this->lines), $i + 7);
            for ($j = $i; $j < $end; $j++) {
                $namen = $this->namesIn($this->lines[$j], $j === $i);
                if ($namen === []) {
                    continue;
                }
                foreach ($namen as $n) {
                    if ($this->sameName($n, $voll)) {
                        $gehoertKunde = true;
                    } else {
                        return []; // dort steht ein ANDERER Kontoinhaber
                    }
                }
                break; // erste Namenszeile unter der Ueberschrift entscheidet
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
            $iban = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $v));
            // PRUEFZIFFER (Modulo 97): ein von der OCR verlesenes Zeichen faellt
            // damit auf - eine kaputte IBAN darf NIE in die Kundenakte.
            if (preg_match('/^DE\d{20}$/', $iban) && $this->ibanChecksumValid($iban)) {
                $raw['iban'] = $iban;
                // Gegenprobe mit der separat gedruckten Kontonummer + BLZ: die
                // deutsche IBAN besteht genau daraus (BLZ + 10-stellige Kontonr.).
                $blz = (string) preg_replace('/\D/', '', (string) $this->labelValue('BLZ'));
                $konto = (string) preg_replace('/\D/', '', (string) $this->labelValue('Konto'));
                if (strlen($blz) >= 8 && $konto !== '') {
                    $erwartet = substr($blz, 0, 8) . str_pad(substr($konto, 0, 10), 10, '0', STR_PAD_LEFT);
                    if ($erwartet !== substr($iban, 4)) {
                        $this->bankHinweis = 'IBAN und die separat gedruckte Konto-/BLZ-Angabe weichen ab - bitte pruefen';
                    }
                }
            } elseif (preg_match('/^DE\d{20}$/', $iban)) {
                $this->bankHinweis = 'IBAN im Bild nicht eindeutig lesbar (Pruefziffer stimmt nicht) - nicht uebernommen';
            }
        }
        // BIC nur im offiziellen Format (4 Buchstaben Bank, 2 Land, 2 Ort,
        // optional 3 Filiale).
        if (($v = $this->labelValue('BIC')) !== null
            && preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/', strtoupper(trim($v)))) {
            $raw['bic'] = strtoupper(trim($v));
        }
        if ($raw !== []) {
            $raw['account_holder'] = $voll;
        }

        return $this->validatedBank($raw);
    }

    /**
     * Kopfzeile der Ansicht: "<Auftragsnummer> - <Anbieter> - <Produkt>".
     * Sie ist gross gesetzt und damit die ZUVERLAESSIGSTE Quelle - in der
     * Tarif-Tabelle verliest die OCR den Produktnamen gern ("orodukt ea 2a").
     *
     * @return array{nummer: ?string, anbieter: ?string, produkt: ?string}
     */
    private function kopfzeile(): array
    {
        foreach (array_slice($this->lines, 0, 4) as $line) {
            if (preg_match('/(?:^|\s)(\d{5,12})\s*[-–]\s*([^-–]{3,60}?)\s*[-–]\s*(\S[^\n]{2,60})$/u', trim($line), $m)) {
                return [
                    'nummer' => $m[1],
                    'anbieter' => trim($m[2]),
                    'produkt' => trim($m[3]),
                ];
            }
        }
        return ['nummer' => null, 'anbieter' => null, 'produkt' => null];
    }

    /** @return array<string,mixed> */
    private function parseEnergy(): array
    {
        $raw = [];

        // Produktname bevorzugt aus der KOPFZEILE: sie ist gross gesetzt und
        // wird sauber gelesen, waehrend die kleine Tarif-Tabelle im Bild gern
        // verstuemmelt ankommt ("Fair ö 24" statt "Fair Ökostrom 24").
        $raw['tariff'] = $this->kopfzeile()['produkt'] ?? $this->labelValue('Produkt');
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
        // Vorjahresverbrauch (ggf. mit Zaehlwerk-Kuerzel "HT"/"NT") - der
        // Wert steht je nach OCR mit einem oder mehreren Leerzeichen dahinter.
        if (preg_match('/Vorjahresverbrauch(?:\s+[A-Z]{2})?\s*:?\s+([\d.]+)\s*kWh/u', $this->text, $m)) {
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
            // Auftragsnummer des Portals = Referenz des Vorgangs (KEINE
            // Vertragsnummer) - die Bruecke zur spaeteren Vertragsbestaetigung.
            'reference_number' => $this->labelValue('Auftragsnummer') ?? $this->kopfzeile()['nummer'],
            'insurer' => $this->kopfzeile()['anbieter'] ?? $this->labelValue('Anbieter'),
            'tariff' => $energie['tariff'] ?? null,
            // Ein Auftrag ist noch keine Bestaetigung.
            'document_stage' => Contract::STAGE_ANTRAG,
        ];

        // Sparte aus dem Feld "Tariftyp"; verliest die OCR die Beschriftung
        // ("Tarityp"), entscheidet der Produktname ("Fair Ökostrom 24").
        $typ = mb_strtolower((string) ($this->labelValue('Tariftyp') ?? ''));
        $produkt = mb_strtolower((string) ($raw['tariff'] ?? ''));
        $raw['sparte'] = match (true) {
            str_contains($typ, 'gas'), str_contains($produkt, 'gas')     => 'gas',
            str_contains($typ, 'strom'), str_contains($produkt, 'strom') => 'strom',
            default                                                      => 'strom',
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
        $nummer = $this->labelValue('Auftragsnummer') ?? $this->kopfzeile()['nummer'];
        if ($nummer !== null && preg_match('/^[\w\-]{4,30}$/u', trim($nummer))) {
            $out .= ' Auftragsnummer ' . trim($nummer) . ' (keine Vertragsnummer - die bringt erst die Vertragsbestaetigung).';
        }
        if (isset($energie['malo_id'])) {
            $out .= ' MaLo-ID ' . $energie['malo_id'] . '.';
        }
        if (isset($energie['meter_number'])) {
            $out .= ' Zaehlernummer ' . $energie['meter_number'] . '.';
        }
        if (isset($energie['previous_provider'])) {
            $out .= ' Wechsel von ' . $energie['previous_provider']
                . (isset($energie['previous_customer_number']) ? ' (Kd.-Nr. ' . $energie['previous_customer_number'] . ')' : '')
                . '.';
        }
        if (isset($energie['consumption_kwh'])) {
            $out .= ' Vorjahresverbrauch ' . number_format($energie['consumption_kwh'], 0, ',', '.') . ' kWh/Jahr.';
        }
        if (isset($energie['working_price'])) {
            $out .= ' Arbeitspreis ' . number_format($energie['working_price'], 2, ',', '.') . ' ct/kWh.';
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
     * Regex fuer "Herr/Frau <Vorname(n)> <Nachname>". Die Namensteile duerfen
     * KEINE weitere Anrede sein - sonst verschluckt der Ausdruck bei
     * zusammengeschobenen Spalten ("Herr Max Muster Herr Max Muster") den
     * zweiten Eintrag.
     */
    private function nameRegex(): string
    {
        return '/(?<![\p{L}])(Herrn?|Frau)\s+(\p{Lu}[\p{L}\-\']+(?:\s+(?!Herrn?\b|Frau\b)\p{Lu}[\p{L}\-\']+){1,3})/u';
    }

    /**
     * Alle Namen einer Zeile ("Herr A B  Frau C D"). Auf der Zeile MIT der
     * Ueberschrift zaehlt nur, was RECHTS davon steht - links steht die
     * Nachbarspalte (Belieferungsanschrift).
     *
     * @return list<string>
     */
    private function namesIn(string $line, bool $nurNachUeberschrift = false): array
    {
        if ($nurNachUeberschrift) {
            $pos = mb_stripos($line, 'Kontoinhaber');
            $line = $pos === false ? '' : mb_substr($line, $pos + mb_strlen('Kontoinhaber'));
        }
        if (!preg_match_all($this->nameRegex(), $line, $all, PREG_SET_ORDER)) {
            return [];
        }
        return array_map(fn (array $m) => trim($m[2]), $all);
    }

    /** Namensvergleich ohne Gross-/Kleinschreibung und Mehrfach-Leerzeichen. */
    private function sameName(string $a, string $b): bool
    {
        $norm = fn (string $v) => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $v)));
        return $norm($a) === $norm($b);
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

    /**
     * IBAN-Pruefziffer nach ISO 7064 (Modulo 97): die ersten vier Zeichen
     * wandern ans Ende, Buchstaben werden zu Zahlen (A=10 ... Z=35), der Rest
     * der Division durch 97 muss 1 sein. Damit faellt ein von der OCR
     * verlesenes Zeichen praktisch immer auf.
     */
    private function ibanChecksumValid(string $iban): bool
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
     * Wert hinter einer Beschriftung. Die Beschriftung darf MITTEN in der
     * Zeile stehen (Nachbarspalte davor); der Wert endet an der naechsten
     * bekannten Beschriftung oder am naechsten Spaltenabstand. Steht in der
     * Zeile nichts mehr, gilt die naechste nicht-leere Zeile.
     */
    private function labelValue(string $label): ?string
    {
        $re = '/(?<![\p{L}\d])' . preg_quote($label, '/') . '(?![\p{L}\d])\s*:?\s*(.*)$/u';
        foreach ($this->lines as $i => $line) {
            if (!preg_match($re, $line, $m)) {
                continue;
            }
            $wert = $this->cutAtNextLabel(trim($m[1]), $label);
            $wert = trim((string) (preg_split('/\s{2,}/u', $wert)[0] ?? ''));
            if ($wert !== '') {
                return $wert;
            }
            // Beschriftung allein in der Zeile -> Wert steht darunter (nur
            // wenn dort nicht schon die naechste Beschriftung beginnt).
            for ($j = $i + 1, $n = min(count($this->lines), $i + 3); $j < $n; $j++) {
                $next = trim($this->lines[$j]);
                if ($next === '') {
                    continue;
                }
                $kandidat = trim((string) (preg_split('/\s{2,}/u', $this->cutAtNextLabel($next, $label))[0] ?? ''));
                return ($kandidat === '' || $kandidat !== $next) ? null : $kandidat;
            }
        }
        return null;
    }

    /**
     * Schneidet den Wert dort ab, wo die naechste bekannte Beschriftung
     * beginnt - oder wo eine Anschrift der Nachbarspalte anfaengt ("… 24768
     * Rendsburg"): eine PLZ gehoert in dieser Ansicht nie zu einem Feldwert.
     */
    private function cutAtNextLabel(string $value, string $current): string
    {
        $ende = null;
        if (preg_match('/(?<![\d.,])\d{5}\s+\p{Lu}/u', $value, $plz, PREG_OFFSET_CAPTURE)) {
            $ende = mb_strlen(substr($value, 0, $plz[0][1]));
        }
        foreach (self::KNOWN_LABELS as $label) {
            if ($label === $current) {
                continue;
            }
            if (preg_match('/(?<![\p{L}\d])' . preg_quote($label, '/') . '(?![\p{L}\d])/u', $value, $m, PREG_OFFSET_CAPTURE)) {
                $pos = mb_strlen(substr($value, 0, $m[0][1]));
                $ende = $ende === null ? $pos : min($ende, $pos);
            }
        }
        return $ende === null ? $value : trim(mb_substr($value, 0, $ende));
    }

    /**
     * Zellen einer Zeile fuer die Anschrift-Suche: bekannte Beschriftungen
     * werden entfernt (sie gehoeren zur Nachbarspalte), danach wird am
     * Spaltenabstand getrennt und ein doppelt gesetzter Text ("X X" aus zwei
     * Spalten) auf EIN Vorkommen reduziert.
     *
     * @return list<string>
     */
    private function cells(string $line): array
    {
        foreach (self::KNOWN_LABELS as $label) {
            $line = (string) preg_replace(
                '/(?<![\p{L}\d])' . preg_quote($label, '/') . '(?![\p{L}\d])\s*:?/u',
                '  ',
                $line
            );
        }
        $out = [];
        foreach (preg_split('/\s{2,}/u', trim($line)) ?: [] as $cell) {
            $cell = trim($cell);
            if ($cell === '') {
                continue;
            }
            // "Alte Kieler Landstr. 141 Alte Kieler Landstr. 141" -> einmal.
            if (preg_match('/^(.{3,}?)\s+\1$/u', $cell, $m)) {
                $cell = trim($m[1]);
            }
            $out[] = $cell;
        }
        return $out;
    }
}
