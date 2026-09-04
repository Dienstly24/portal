<?php

namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer die LIEFERBESTAETIGUNG der Gruenwelt-Gesellschaften
 * (Gruenwelt Waermestrom GmbH / Gruenwelt Energie GmbH, Strom und Gas).
 *
 * Das ist die zweite Haelfte des Auftrag-zuerst-Systems (siehe
 * docs/AUFTRAG_UND_VERTRAG_ZUSAMMENFUEHREN.md): zuerst laedt der Betrieb den
 * AUFTRAG hoch (viele Daten, keine Bestaetigung), Wochen spaeter kommt dieses
 * Schreiben mit den endgueltigen Angaben - Lieferbeginn, Zaehlernummer,
 * Marktlokations-ID und Abschlag. Stufe ist deshalb 'vertrag': die
 * Vertragsanlage ergaenzt damit den vorhandenen ANTRAGS-Vertrag (gefunden
 * ueber Zaehlernummer/MaLo-ID/Referenz-Nr.), statt einen zweiten anzulegen.
 *
 * Aufbau des Schreibens (digitale Textebene, kein Scan noetig):
 *  - KOPFBLOCK rechts mit "Bestellnummer", "Vertragskontonummer",
 *    "Mandatsreferenz" und "Verbrauchsstelle" - Beschriftung und Wert mit nur
 *    EINEM Leerzeichen getrennt;
 *  - Empfaengerblock links (Anrede, Name, Strasse, PLZ Ort) - auf DERSELBEN
 *    Zeile steht rechts die Service-Spalte des Versorgers (E-Mail, Telefon,
 *    Fax). Gelesen wird deshalb immer nur die ERSTE Spalte der Zeile, sonst
 *    landeten Telefonnummer und E-Mail des Versorgers in der Kundenakte;
 *  - "ZUSAMMENFASSUNG IHRER VERTRAGSDATEN" als Beschriftung/Wert-Tabelle mit
 *    grossem Spaltenabstand.
 *
 * Feste Regeln dieses Hauses:
 *  - Die BESTELLNUMMER ist keine Vertragsnummer, sondern die Kennung des
 *    Vorgangs -> `reference_number` (genau dafuer ist das Feld da). Der
 *    Vertrag laeuft unter der VERTRAGSKONTONUMMER -> `contract_number`.
 *  - KEINE Bankdaten: die Kunden-IBAN ist im Schreiben maskiert
 *    ("DE70 XXXX ... 55 30"), und die vollstaendige IBAN im Brieffuss gehoert
 *    Gruenwelt selbst (Aareal Bank). Uebernommen wird nichts davon.
 *  - Der namentlich genannte Betrieb ist der MESSSTELLENbetreiber; den
 *    Netzbetreiber nennt das Schreiben nicht ("bei Ihrem zustaendigen
 *    Netzbetreiber angemeldet"). `grid_operator` bleibt deshalb leer, der
 *    Name steht nur in der Zusammenfassung.
 *  - Der Grundpreis steht je JAHR, die Kundenakte fuehrt ihn je MONAT
 *    (`base_price` = EUR/Monat) - deterministisch /12 umgerechnet, beide
 *    Werte stehen in der Zusammenfassung.
 *  - Laufzeit, Kuendigungsfrist, Verlaengerung und Preisgarantie haben kein
 *    eigenes Feld und werden NICHT in ein Enddatum umgerechnet (die
 *    Verlaengerung laeuft "auf unbestimmte Zeit") - sie stehen in der
 *    Zusammenfassung.
 */
class GruenweltLieferbestaetigungParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $upper = mb_strtoupper($text);

        // Erkennung: der Absender UND das Schreiben. Beides muss zusammen
        // kommen - "Gruenwelt" allein steht auch in einer Rechnung oder
        // Jahresabrechnung, die keine Vertragsdaten bestaetigt.
        $istGruenwelt = str_contains($upper, 'GRÜNWELT') || str_contains($upper, 'GRUENWELT');
        $istBestaetigung = str_contains($upper, 'LIEFERBESTÄTIGUNG')
            || str_contains($upper, 'LIEFERBESTAETIGUNG');
        if (! $istGruenwelt || ! $istBestaetigung) {
            return null;
        }

        $this->lines = preg_split('/\R/', $text) ?: [];

        $insurance = $this->parseInsurance($text, $upper);
        $energie = $this->parseEnergy($text, $insurance);

        // Ohne die Vertragskontonummer traegt das Schreiben nichts bei, was
        // einen Vertrag identifizieren koennte.
        if (($insurance['contract_number'] ?? null) === null) {
            return null;
        }

        $person = $this->parsePerson($text);
        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        $sparteLabel = ($insurance['sparte'] ?? 'strom') === 'gas' ? 'Gas' : 'Strom';

        return [
            'type' => 'energieauftrag',
            'confidence' => 78,
            'summary' => 'Gruenwelt '.$sparteLabel.'-Lieferbestaetigung'
                .($name !== '' ? ' - '.$name : '')
                .' - Vertragskonto '.$insurance['contract_number']
                .(isset($insurance['reference_number']) ? ' - Bestellnr. '.$insurance['reference_number'] : '')
                .(isset($energie['tariff']) ? ' - '.$energie['tariff'] : '')
                .(isset($insurance['start_date']) ? ' - Lieferbeginn '.$this->displayDate($insurance['start_date']) : '')
                .(isset($insurance['premium_amount'])
                    ? ' - Abschlag '.number_format($insurance['premium_amount'], 2, ',', '.').' EUR/Monat brutto' : '')
                .$this->zusatzHinweise($text)
                .' Bankdaten wurden bewusst nicht uebernommen (Kunden-IBAN im Schreiben maskiert).'
                .' Felder gratis aus der Textebene gelesen (ohne KI).',
            'title' => 'Gruenwelt Lieferbestaetigung'.($name !== '' ? ' '.$name : ''),
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
     * Empfaenger aus dem Briefkopf: Anrede, Name, Strasse+Hausnummer, PLZ+Ort.
     * Gelesen wird IMMER nur die erste Spalte der Zeile (rechts steht die
     * Service-Spalte des Versorgers). Der Nachname wird zusaetzlich an der
     * Anrede "Sehr geehrter Herr <Nachname>," geprueft.
     *
     * @return array<string,mixed>
     */
    private function parsePerson(string $text): array
    {
        $raw = [];

        // Anrede im Fliesstext -> Geschlecht und Nachname.
        $nachname = null;
        if (preg_match('/Sehr geehrter?\s+(Herrn?|Frau)\s+([\p{L}\-]{2,40})\s*,/u', $text, $m)) {
            $raw['gender'] = mb_strtolower($m[1]) === 'frau' ? 'female' : 'male';
            $nachname = $m[2];
        }

        foreach ($this->lines as $i => $line) {
            $spalte = $this->columnOfFirstCell($line);
            if ($spalte === null || ! preg_match('/^(Herrn?|Frau|Firma)$/u', $this->cellAt($line, $spalte), $a)) {
                continue;
            }
            if (! isset($raw['gender']) && $a[1] !== 'Firma') {
                $raw['gender'] = mb_strtolower($a[1]) === 'frau' ? 'female' : 'male';
            }

            // Die naechsten Zeilen des Blocks - gelesen wird die Zelle an
            // DERSELBEN Spaltenposition wie die Anrede. Auf die "erste Zelle
            // der Zeile" ist kein Verlass: wo der Empfaengerblock eine Zeile
            // auslaesst, steht dort die Service-Spalte des Versorgers
            // ("Telefon 0800 ...") und wuerde sonst als Name gelesen.
            $block = [];
            for ($j = $i + 1; $j < count($this->lines) && count($block) < 4; $j++) {
                $zelle = $this->cellAt($this->lines[$j], $spalte);
                if ($zelle === '') {
                    continue;
                }
                $block[] = $zelle;
            }
            $this->readAddressBlock($block, $raw, $nachname);
            break;
        }

        // Ohne Empfaengerblock wenigstens den Nachnamen aus der Anrede.
        if (($raw['last_name'] ?? null) === null && $nachname !== null) {
            $raw['last_name'] = $nachname;
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * @param list<string> $block
     * @param array<string,mixed> $raw
     */
    private function readAddressBlock(array $block, array &$raw, ?string $nachname): void
    {
        foreach ($block as $index => $zeile) {
            // "30457 Hannover"
            if (preg_match('/^(\d{5})\s+([\p{L}][\p{L}.\- \/]{1,50})$/u', $zeile, $z)) {
                $raw['zip'] = $z[1];
                $raw['city'] = trim($z[2]);
                continue;
            }
            // "Tresckowstr. 10" - Strasse + Hausnummer.
            if (preg_match('/^(.{2,60}?\p{L}\.?)\s+(\d{1,4}\s*[a-zA-Z]?)$/u', $zeile, $s)
                && preg_match('/\p{L}{3,}/u', $s[1])) {
                $raw['street'] = trim($s[1]);
                $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
                continue;
            }
            // Namenszeile: nur die ERSTE Zeile des Blocks, und nur wenn sie zum
            // Nachnamen aus der Anrede passt (sofern der bekannt ist) - so wird
            // eine Firmenzeile nie zum Personennamen.
            if ($index === 0
                && preg_match('/^([\p{L}][\p{L}\-]+)((?:\s+[\p{L}][\p{L}\-]+){1,4})$/u', $zeile, $n)
                && ($nachname === null || mb_stripos($zeile, $nachname) !== false)) {
                $teile = preg_split('/\s+/', trim($zeile)) ?: [];
                $raw['last_name'] = array_pop($teile);
                $raw['first_name'] = implode(' ', $teile);
            }
        }
    }

    /** @return array<string,mixed> */
    private function parseInsurance(string $text, string $upper): array
    {
        $raw = [
            'insurer' => $this->gesellschaft($text),
            'sparte' => $this->sparte($upper),
            // Bestaetigung = der Vertrag ist zustande gekommen.
            'document_stage' => Contract::STAGE_VERTRAG,
        ];

        // Vertragskontonummer = die Nummer, unter der der Vertrag laeuft.
        if (preg_match('/Vertragskontonummer\s+(\d{6,20})/iu', $text, $m)) {
            $raw['contract_number'] = $m[1];
        }
        // Bestellnummer = Kennung des VORGANGS, nie eine Vertragsnummer.
        if (preg_match('/Bestellnummer\s+([A-Z0-9\-]{5,30})/iu', $text, $m)) {
            $raw['reference_number'] = strtoupper($m[1]);
        }

        if (($v = $this->rowValue('Lieferbeginn')) !== null
            && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $v, $m)) {
            $raw['start_date'] = $m[3].'-'.$m[2].'-'.$m[1];
        } elseif (preg_match('/Stromlieferung ab dem\s+(\d{2})\.(\d{2})\.(\d{4})/iu', $text, $m)) {
            $raw['start_date'] = $m[3].'-'.$m[2].'-'.$m[1];
        }

        if (($tarif = $this->rowValue('Tarif')) !== null && preg_match('/\p{L}{3,}/u', $tarif)) {
            $raw['tariff'] = $tarif;
        }

        // Monatlicher Abschlag BRUTTO: "monatlicher Abschlag in Hoehe von
        // 65,00 EUR brutto (54,62 EUR netto)". Der Nettowert in der Klammer
        // wird nie genommen - der Kunde zahlt brutto.
        if (preg_match('/monatlicher Abschlag[^\r\n]{0,40}?([\d.]+,\d{2})\s*EUR\s*\R?\s*brutto/iu', $text, $m)
            || preg_match('/Abschlag in H(?:ö|oe)he von\s*([\d.]+,\d{2})\s*EUR/iu', $text, $m)) {
            $raw['premium_amount'] = $this->euro($m[1]);
            $raw['premium_interval'] = 'monthly';
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * @param array<string,mixed> $insurance
     * @return array<string,mixed>
     */
    private function parseEnergy(string $text, array $insurance): array
    {
        $raw = ['tariff' => $insurance['tariff'] ?? null];

        if (($v = $this->rowValue('Zählernummer')) !== null && preg_match('/^[A-Z0-9]{4,20}$/i', $v)) {
            $raw['meter_number'] = strtoupper($v);
        }
        if (($v = $this->rowValue('Marktlokationsidentifikationsnummer')) !== null
            && preg_match('/\b(\d{11})\b/', $v, $m)) {
            $raw['malo_id'] = $m[1];
        }
        if (($v = $this->rowValue('Ihr Jahresverbrauch')) !== null
            && preg_match('/([\d.]+)\s*kWh/iu', $v, $m)) {
            $raw['consumption_kwh'] = (int) str_replace('.', '', $m[1]);
        }
        // Arbeitspreis: der BRUTTO-Wert steht vorn, der Nettowert in Klammern.
        if (($v = $this->rowValue('Arbeitspreis HT')) !== null
            && preg_match('/([\d.]+,\d+)\s*ct\/kWh\s*brutto/iu', $v, $m)) {
            $raw['working_price'] = $this->euro($m[1]);
        }
        // Grundpreis steht je JAHR - die Kundenakte fuehrt EUR/MONAT.
        if (($jahr = $this->grundpreisProJahr($text)) !== null) {
            $raw['base_price'] = round($jahr / 12, 2);
        }

        return $this->validatedEnergy(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** Grundpreis in EUR/Jahr (brutto) oder null. */
    private function grundpreisProJahr(string $text): ?float
    {
        if (($v = $this->rowValue('Grundpreis')) !== null
            && preg_match('/([\d.]+,\d{2})\s*EUR\/Jahr\s*brutto/iu', $v, $m)) {
            return $this->euro($m[1]);
        }
        return null;
    }

    /**
     * Hinweise ohne eigenes Feld: Laufzeit, Kuendigungsfrist, Verlaengerung,
     * Preisgarantie, Grundpreis je Jahr und der Messstellenbetreiber.
     */
    private function zusatzHinweise(string $text): string
    {
        $teile = [];
        if (($v = $this->rowValue('Vertragslaufzeit ab Lieferbeginn')) !== null) {
            $teile[] = 'Laufzeit '.$v.' ab Lieferbeginn';
        }
        if (($v = $this->rowValue('Kündigungsfrist zum Laufzeitende')) !== null) {
            $teile[] = 'Kuendigungsfrist '.$v;
        }
        if (($v = $this->rowValue('Verlängerung jeweils um')) !== null) {
            $teile[] = 'Verlaengerung um '.$v;
        }
        if (($v = $this->rowValue('Eingeschränkte Preisgarantie ab Lieferbeginn')) !== null) {
            $teile[] = 'eingeschraenkte Preisgarantie '.$v;
        }
        if (($jahr = $this->grundpreisProJahr($text)) !== null) {
            $teile[] = 'Grundpreis '.number_format($jahr, 2, ',', '.').' EUR/Jahr brutto';
        }
        if (preg_match('/Messstellenbetreiber\s+([\p{L}][\p{L}. \-&]{2,60}?(?:GmbH|AG|KG|SE|GmbH & Co\. KG))/u', $text, $m)) {
            $teile[] = 'Messstellenbetreiber '.trim($m[1]);
        }

        return $teile === [] ? '' : ' - '.implode(', ', $teile).'.';
    }

    /** Die absendende Gesellschaft aus dem Briefkopf. */
    private function gesellschaft(string $text): string
    {
        if (preg_match('/(Gr(?:ü|ue)nwelt\s+[\p{L}]+\s+GmbH)/u', $text, $m)) {
            return trim((string) preg_replace('/\s+/', ' ', $m[1]));
        }
        return 'Grünwelt';
    }

    private function sparte(string $upper): string
    {
        return (str_contains($upper, 'GASLIEFERUNG') || str_contains($upper, 'ERDGAS'))
            ? 'gas' : 'strom';
    }

    /**
     * Wert einer Zeile der Vertragsdaten-Tabelle: die Beschriftung steht am
     * Zeilenanfang, der Wert nach einem GROSSEN Spaltenabstand.
     */
    private function rowValue(string $label): ?string
    {
        $re = '/^\s*'.preg_quote($label, '/').'\s{2,}(\S.*?)\s*$/u';
        foreach ($this->lines as $line) {
            if (preg_match($re, $line, $m) && trim($m[1]) !== '') {
                return trim($m[1]);
            }
        }
        return null;
    }

    /** Startspalte des ersten Textblocks einer Zeile (null bei Leerzeile). */
    private function columnOfFirstCell(string $line): ?int
    {
        return preg_match('/^(\s*)\S/u', $line, $m) ? mb_strlen($m[1]) : null;
    }

    /**
     * Zelle, die an dieser Spaltenposition BEGINNT - bis zum naechsten grossen
     * Spaltenabstand. Steht dort Leerraum (die Zeile fuehrt nur die rechte
     * Spalte), ist die Zelle leer.
     */
    private function cellAt(string $line, int $column): string
    {
        $rest = mb_substr($line, $column);
        if (trim($rest) === '' || preg_match('/^\s{2,}/u', $rest)) {
            return '';
        }
        $cells = preg_split('/\s{2,}/u', ltrim($rest)) ?: [];

        return trim($cells[0] ?? '');
    }

    private function euro(string $value): float
    {
        return (float) str_replace(['.', ','], ['', '.'], $value);
    }

    private function displayDate(string $iso): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) ? $m[3].'.'.$m[2].'.'.$m[1] : $iso;
    }
}
