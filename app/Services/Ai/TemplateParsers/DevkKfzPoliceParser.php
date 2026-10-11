<?php

namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\ReadsDocumentPages;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer den Kfz-Versicherungsschein der DEVK
 * ("VERSICHERUNGSSCHEIN ZUR KFZ-VERSICHERUNG", DEVK Allgemeine
 * Versicherungs-AG) - als NEUVERTRAG und als ANPASSUNG ("heute informieren
 * wir Sie ueber Aenderungen zu Ihrer o.g. Versicherung", Betreiber-Meldung
 * 11.10.2026). Beides ist derselbe Schein mit derselben Versicherungsnummer;
 * die Anpassung ergaenzt den vorhandenen Vertrag ueber die Nummer/FIN
 * (`findExistingContractByIdentity`), statt einen zweiten anzulegen.
 *
 * DIE KOPFTABELLE ist der Kern: "Sparte | Versicherung Nr. | Beginn der
 * Versicherung | Beginn der Aenderung | Ablauf der Versicherung" - darunter
 * EINE Zeile mit den Werten, und bei einer Anpassung ist "Beginn der
 * Versicherung" LEER. Wer die Daten der Reihe nach liest, macht aus dem
 * Aenderungsdatum den Vertragsbeginn - ein falscher Beginn in der Akte. Die
 * Daten werden deshalb ueber die SPALTENPOSITION der Kopfzeile zugeordnet;
 * ohne erkennbare Kopfzeile wird kein Beginn geraten. Der Aenderungstag
 * steht nur in der Zusammenfassung.
 *
 * NICHT uebernommen:
 * - Bankdaten: der Schein nennt nur "Lastschriftverfahren", keine IBAN.
 * - Die Vermittler-Zeile ("online vermittelt ueber CHECK24 ...") - sie steht
 *   nur in der Zusammenfassung. Deshalb hier NICHT `looksLikeComparisonProtocol`
 *   als Sperre: das Wort CHECK24 allein macht aus einer Police kein
 *   Beratungsprotokoll.
 * - Anschrift/Kontakt der DEVK (rechte Spalte des Briefkopfs).
 * - Der NETTO-Beitrag; uebernommen wird der Gesamtbeitrag BRUTTO zur
 *   Zahlungsweise.
 */
class DevkKfzPoliceParser implements DocumentTemplateParser
{
    use ReadsDocumentPages;
    use ValidatesExtractedFields;

    /** @var list<string> */
    private array $lines = [];

    private string $text = '';

    public function parse(string $text): ?array
    {
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $upper = mb_strtoupper($text);

        if (! str_contains($upper, 'DEVK')
            || ! preg_match('/VERSICHERUNGSSCHEIN\s+ZUR\s+(KFZ-VERSICHERUNG|KRAFTFAHRTVERSICHERUNG)/u', $upper)) {
            return null;
        }
        // Ein Beratungsprotokoll/Antrag, der die DEVK nur als Tarif nennt,
        // ist kein Versicherungsschein.
        if (str_contains(mb_strtoupper($this->firstPage($text)), 'BERATUNGSPROTOKOLL')) {
            return null;
        }

        $this->text = $text;
        $this->lines = array_map('rtrim', preg_split('/\R/', $text) ?: []);

        $person = $this->parsePerson();
        $vehicle = $this->parseVehicle($person);
        [$insurance, $changeDate] = $this->parseInsurance();

        if (($insurance['contract_number'] ?? null) === null && ($vehicle['license_plate'] ?? null) === null) {
            return null;
        }

        $isChange = $changeDate !== null || (bool) preg_match('/informieren\s+wir\s+Sie\s+über\s+Änderungen/u', $text);
        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));

        $summary = 'DEVK Kfz-Versicherungsschein'.($isChange ? ' (Anpassung/Nachtrag)' : '')
            .($name !== '' ? ' - '.$name : '')
            .(isset($vehicle['license_plate']) ? ' - '.$vehicle['license_plate'] : '')
            .(isset($insurance['contract_number']) ? ' - Vers.-Nr. '.$insurance['contract_number'] : '')
            .($changeDate !== null ? ' - Änderung zum '.$changeDate : '')
            .(isset($insurance['tariff']) ? ' - Tarif '.$insurance['tariff'] : '')
            .(isset($vehicle['sf_liability_class']) ? ' - SF '.$vehicle['sf_liability_class'].' (Haftpflicht)' : '')
            .(isset($vehicle['sf_comprehensive_class']) ? ' - SF '.$vehicle['sf_comprehensive_class'].' (Vollkasko)' : '')
            .' - Deckung: '.$this->coverageSummary($vehicle)
            .(isset($insurance['premium_amount'])
                ? ' - Beitrag brutto '.number_format($insurance['premium_amount'], 2, ',', '.').' EUR'
                    .match ($insurance['premium_interval'] ?? null) {
                        'monthly' => '/Monat', 'quarterly' => '/Vierteljahr',
                        'semiannual' => '/Halbjahr', 'yearly' => '/Jahr', default => '',
                    }
                : '')
            .$this->extraNotes()
            .' - Felder gratis aus dem Versicherungsschein gelesen (ohne KI).';

        return [
            'type' => 'kfz_vertrag',
            'confidence' => 78,
            'summary' => $summary,
            'title' => 'DEVK Kfz-Versicherung'.($name !== '' ? ' '.$name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $insurance,
                'kfz' => $vehicle,
                'gesundheit' => [],
                'bank' => [],
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /**
     * Anschriftenblock: "Herrn"/"Frau" -> Name -> Strasse -> PLZ Ort, jeweils
     * die LINKE Zelle der Zeile (rechts steht die Regionaldirektion).
     *
     * @return array<string,mixed>
     */
    private function parsePerson(): array
    {
        $raw = [];
        foreach ($this->lines as $i => $line) {
            if (! preg_match('/^\s*(Herrn|Herr|Frau)\s*(?:\s{2,}|$)/u', $line, $g)) {
                continue;
            }
            // Nur Zeilen, die in der SPALTE der Anrede beginnen - eine Zeile,
            // in der links nichts steht, traegt sonst die rechte Spalte
            // ("Postanschrift:") als Kundenzeile ein.
            $col = mb_strlen($line) - mb_strlen(ltrim($line));
            $block = [];
            for ($j = $i + 1; $j < count($this->lines) && $j <= $i + 8 && count($block) < 3; $j++) {
                $l = $this->lines[$j];
                if (trim($l) === '' || abs((mb_strlen($l) - mb_strlen(ltrim($l))) - $col) > 3) {
                    continue;
                }
                $block[] = $this->firstCell($l);
            }
            if (count($block) < 3
                || ! preg_match('/^\p{L}[\p{L}\-\']*(?:\s+\p{L}[\p{L}\-\']*)+$/u', $block[0])
                || ! preg_match('/^(\d{5})\s+(\p{L}.+)$/u', $block[2], $z)) {
                continue;
            }
            $raw['gender'] = mb_strtolower($g[1]) === 'frau' ? 'female' : 'male';
            $parts = preg_split('/\s+/', $block[0]) ?: [];
            $raw['last_name'] = array_pop($parts);
            $raw['first_name'] = implode(' ', $parts);
            $raw['street'] = $block[1];
            $raw['zip'] = $z[1];
            $raw['city'] = trim($z[2]);
            break;
        }

        // "- Versicherungsnehmer (gleichgestellt ...), geboren am 15.01.1970."
        if (preg_match('/Versicherungsnehmer\b.{0,250}?geboren\s+am\s+(\d{2})\.(\d{2})\.(\d{4})/su', $this->text, $m)) {
            $raw['birth_date'] = $m[3].'-'.$m[2].'-'.$m[1];
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * @param array<string,mixed> $person
     * @return array<string,mixed>
     */
    private function parseVehicle(array $person): array
    {
        $raw = [];

        if (($v = $this->labelValue('Amtliches Kennzeichen')) !== null
            && preg_match('/^[A-ZÄÖÜ]{1,3}[- ]?[A-ZÄÖÜ]{1,2}[- ]?\d{1,4}[EH]?$/u', $v)) {
            $raw['license_plate'] = $v;
        }
        if (($v = $this->labelValue('Fahrzeug-Identifizierungs-Nr.')) !== null
            && preg_match('/\b([A-HJ-NPR-Z0-9]{11,17})\b/', strtoupper($v), $m)) {
            $raw['vin'] = $m[1];
        }
        if (($v = $this->labelValue('Fahrzeugart')) !== null) {
            $raw['vehicle_type'] = $this->mapVehicleType($v);
        }
        // "HYUNDAI (Hersteller-/Typ-Schlüssel: 1349/AAB)".
        if (($v = $this->labelValue('Hersteller')) !== null) {
            $marke = trim((string) preg_split('/\(/u', $v)[0]);
            if (preg_match('/^\p{L}[\p{L}0-9 .\-]{1,40}$/u', $marke)) {
                $raw['manufacturer'] = $marke;
            }
            if (preg_match('#Typ-Schl[üu]ssel:?\s*(\d{4})\s*/\s*([A-Z0-9]{3})#iu', $v, $m)) {
                $raw['hsn'] = $m[1];
                $raw['tsn'] = strtoupper($m[2]);
            }
        }
        if (($v = $this->labelValue('Erstzulassung')) !== null && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $v, $m)) {
            $raw['first_registration'] = $m[3].'-'.$m[2].'-'.$m[1];
        }
        if (($v = $this->labelValue('Leistung')) !== null && preg_match('/^(\d{1,4})\s*kW/iu', $v, $m)) {
            $raw['power_kw'] = (int) $m[1];
        }

        // "Kfz-Haftpflicht: Berücksichtigt ist die Schadenfreiheits(SF-)Klasse 4"
        // bzw. "Vollkasko: ... Klasse 2". Teilkasko kennt keine SF-Klasse.
        if (preg_match('/Kfz-Haftpflicht:\s*Berücksichtigt\s+ist\s+die\s+Schadenfreiheits\s*\(SF-?\)\s*-?Klasse\s+(SF\s*)?(\d{1,2}(?:\/2)?|M|S|0)\b/u', $this->text, $m)) {
            $raw['sf_liability_class'] = $m[2];
        }
        if (preg_match('/Vollkasko:\s*Berücksichtigt\s+ist\s+die\s+Schadenfreiheits\s*\(SF-?\)\s*-?Klasse\s+(SF\s*)?(\d{1,2}(?:\/2)?|M|S|0)\b/u', $this->text, $m)) {
            $raw['sf_comprehensive_class'] = $m[2];
        }

        // Deckung NUR aus dem Abschnitt "Versicherungsumfang" (die Anlage und
        // die Hinweise erwaehnen Kasko/Werkstatt allgemein).
        $umfang = $this->section('Versicherungsumfang', 'Gesamtbeitrag brutto');
        if ($umfang !== null) {
            $raw['has_vollkasko'] = (bool) preg_match('/Vollkasko/iu', $umfang);
            $raw['has_teilkasko'] = $raw['has_vollkasko'] || (bool) preg_match('/Teilkasko/iu', $umfang);
            if (preg_match('/Teilkasko\s+mit\s+([\d.]+)\s*Euro\s+Selbstbeteiligung/iu', $umfang, $m)) {
                $raw['teilkasko_deductible'] = (int) str_replace('.', '', $m[1]);
            } elseif (preg_match('/Teilkasko\s+ohne\s+Selbstbeteiligung/iu', $umfang)) {
                $raw['teilkasko_deductible'] = 0;
            }
            if (preg_match('/Vollkasko\s+mit\s+([\d.]+)\s*Euro\s+Selbstbeteiligung/iu', $umfang, $m)) {
                $raw['vollkasko_deductible'] = (int) str_replace('.', '', $m[1]);
            }
            $extras = [];
            if (preg_match('/Schutzbrief/iu', $umfang)) {
                $extras[] = 'schutzbrief';
            }
            if (preg_match('/Werkstattbindung/iu', $umfang)) {
                $extras[] = 'werkstattbindung';
            }
            if (preg_match('/Rabattschutz/iu', $umfang)) {
                $extras[] = 'rabattschutz';
            }
            if (preg_match('/Fahrerschutz/iu', $umfang)) {
                $extras[] = 'fahrerschutz';
            }
            if ($extras !== []) {
                $raw['extras'] = $extras;
            }
        }

        if (preg_match('/Jahresfahrleistung\s+bis\s+([\d.]+)\s*km/iu', $this->text, $m)) {
            $raw['annual_mileage'] = (int) str_replace('.', '', $m[1]);
        }
        if (preg_match('/Kilometerstand\s+am\s+\d{2}\.\d{2}\.\d{4}:?\s*([\d.]{3,})\s*km/iu', $this->text, $m)) {
            $raw['initial_mileage'] = (int) str_replace('.', '', $m[1]);
        }
        // "Das Fahrzeug wurde am 11.04.2024 auf Ihren Namen ... zugelassen."
        if (preg_match('/Fahrzeug\s+wurde\s+am\s+(\d{2})\.(\d{2})\.(\d{4})\s+auf\s+Ihren\s+Namen/u', $this->text, $m)) {
            $raw['acquisition_date'] = $m[3].'-'.$m[2].'-'.$m[1];
        }

        // Halter = Versicherungsnehmer nur bei GLEICHEM Namen; sonst wird
        // nichts behauptet.
        $halter = $this->labelValue('Fahrzeughalter');
        $vn = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        if ($halter !== null && $vn !== '') {
            $raw['holder_type'] = $this->normName($halter) === $this->normName($vn)
                ? 'versicherungsnehmer' : 'abweichender_halter';
        }

        return $this->validatedVehicle($raw);
    }

    /**
     * @return array{0: array<string,mixed>, 1: ?string} Versicherung + Aenderungsdatum (TT.MM.JJJJ)
     */
    private function parseInsurance(): array
    {
        $raw = [
            'sparte' => 'kfz',
            'insurer' => str_contains($this->text, 'DEVK Allgemeine Versicherungs-AG')
                ? 'DEVK Allgemeine Versicherungs-AG' : 'DEVK Versicherungen',
            'document_stage' => Contract::STAGE_VERTRAG,
        ];

        foreach (['/Versicherungsschein\s+Nr\.\s*(\d{6,15})\b/u', '/Kfz-Versicherung\s+Nr\.\s*(\d{6,15})\b/u'] as $re) {
            if (preg_match($re, $this->text, $m)) {
                $raw['contract_number'] = $m[1];
                break;
            }
        }

        // Tarif steht als "- Aktiv-Schutz -" unter "Versicherungsumfang".
        $umfang = $this->section('Versicherungsumfang', 'Ihre Vertragsdaten');
        if ($umfang !== null && preg_match('/^\s*-\s+(\p{L}[\p{L}\- ]{2,40}?)\s+-\s*$/mu', $umfang, $m)) {
            $raw['tariff'] = trim($m[1]);
        }

        $dates = $this->headerDates();
        if (isset($dates['beginn'])) {
            $raw['start_date'] = $this->iso($dates['beginn']);
        }
        if (isset($dates['ablauf'])) {
            $raw['end_date'] = $this->iso($dates['ablauf']);
        }

        if (preg_match('/Gesamtbeitrag\s+brutto:?\s*([\d.]+,\d{2})\s*Euro/u', $this->text, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = $this->interval();
        }

        return [
            $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== '')),
            $dates['aenderung'] ?? null,
        ];
    }

    /**
     * Daten der Kopftabelle ueber die SPALTENPOSITION: jede Spalte der
     * Kopfzeile ("Beginn der" / "Ablauf der") bekommt das Datum, das unter
     * ihr steht. Welche "Beginn der"-Spalte die Aenderung ist, sagt die
     * Folgezeile der Kopfzeile ("Versicherung" / "Änderung").
     *
     * @return array<string,string> beginn/aenderung/ablauf => TT.MM.JJJJ
     */
    private function headerDates(): array
    {
        foreach ($this->lines as $i => $line) {
            if (! str_contains($line, 'Versicherung Nr.') || ! preg_match('/Ablauf\s+der/u', $line)) {
                continue;
            }
            $cols = [];
            preg_match_all('/(Beginn der|Ablauf der)/u', $line, $mm, PREG_OFFSET_CAPTURE);
            $sub = $this->lines[$i + 1] ?? '';
            foreach ($mm[1] as [$label, $byte]) {
                $pos = mb_strlen(substr($line, 0, $byte));
                if ($label === 'Ablauf der') {
                    $key = 'ablauf';
                } else {
                    $unter = mb_substr($sub, max(0, $pos - 2), 16);
                    $key = str_contains($unter, 'Änderung') ? 'aenderung' : 'beginn';
                }
                $cols[$key] = $pos;
            }

            // Wertezeile: die naechste Zeile mit einem Datum.
            for ($j = $i + 1; $j < min(count($this->lines), $i + 8); $j++) {
                if (! preg_match_all('/\b\d{2}\.\d{2}\.\d{4}\b/u', $this->lines[$j], $dm, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                $out = [];
                foreach ($dm[0] as [$date, $byte]) {
                    $pos = mb_strlen(substr($this->lines[$j], 0, $byte));
                    $best = null;
                    $bestDist = PHP_INT_MAX;
                    foreach ($cols as $key => $cpos) {
                        $dist = abs($cpos - $pos);
                        if ($dist < $bestDist) {
                            [$best, $bestDist] = [$key, $dist];
                        }
                    }
                    // Zu weit von jeder Spalte entfernt -> nicht zuordnen.
                    if ($best !== null && $bestDist <= 12 && ! isset($out[$best])) {
                        $out[$best] = $date;
                    }
                }

                return $out;
            }
        }

        return [];
    }

    /** Hinweise ohne eigenes Feld - nur fuer die Zusammenfassung. */
    private function extraNotes(): string
    {
        $notes = '';
        if (preg_match('/Hauptfälligkeit\s+ist\s+am\s+(\d{2}\.\d{2}\.)/u', $this->text, $m)) {
            $notes .= ' - Hauptfälligkeit '.$m[1];
        }
        if (preg_match('/Kaufpreis:?\s*([\d.]+)\s*Euro/u', $this->text, $m)) {
            $notes .= ' - Kaufpreis '.$m[1].' EUR';
        }
        if (preg_match('/Regionalklasse\s+(\d+)/u', $this->text, $m)) {
            $notes .= ' - Regionalklasse KH '.$m[1];
        }
        if (preg_match('/Typklasse\s+(\d+)/u', $this->text, $m)) {
            $notes .= ' - Typklasse KH '.$m[1];
        }
        if (preg_match('/vermittelt\s+über:?\s*\R\s*([^\n]{3,80})/u', $this->text, $m)) {
            $notes .= ' - vermittelt über '.trim((string) preg_replace('/\s{2,}.*$/u', '', $m[1]));
        }

        return $notes;
    }

    private function interval(): string
    {
        $line = '';
        foreach ($this->lines as $l) {
            if (str_contains($l, 'Zahlungsweise')) {
                $line = $l;
                break;
            }
        }
        $hay = $line !== '' ? $line : $this->text;

        return match (true) {
            (bool) preg_match('/viertelj[äa]hrlich/iu', $hay) => 'quarterly',
            (bool) preg_match('/halbj[äa]hrlich/iu', $hay) => 'semiannual',
            (bool) preg_match('/\bmonatlich\b/iu', $hay) => 'monthly',
            default => 'yearly',
        };
    }

    private function mapVehicleType(string $value): ?string
    {
        $v = mb_strtolower($value);

        return match (true) {
            str_contains($v, 'pkw') || str_contains($v, 'personenkraftwagen') => 'pkw',
            str_contains($v, 'krad') || str_contains($v, 'motorrad') => 'motorrad',
            str_contains($v, 'lkw') || str_contains($v, 'lastkraft') => 'lkw',
            str_contains($v, 'anhänger') => 'anhaenger',
            str_contains($v, 'wohnmobil') => 'wohnmobil',
            default => null,
        };
    }

    private function coverageSummary(array $kfz): string
    {
        $parts = ['Haftpflicht'];
        if (! empty($kfz['has_vollkasko'])) {
            $parts[] = 'Vollkasko';
        } elseif (! empty($kfz['has_teilkasko'])) {
            $parts[] = 'Teilkasko'.(isset($kfz['teilkasko_deductible']) ? ' (SB '.$kfz['teilkasko_deductible'].' EUR)' : '');
        } elseif (isset($kfz['has_teilkasko'])) {
            $parts[] = 'keine Kasko';
        }

        return implode(', ', $parts);
    }

    /** Text zwischen einer Ueberschrift und dem ersten Vorkommen von $until. */
    private function section(string $heading, string $until): ?string
    {
        $from = null;
        foreach ($this->lines as $i => $line) {
            if ($from === null && preg_match('/^\s*'.preg_quote($heading, '/').'\s*:?\s*$/u', $line)) {
                $from = $i;
                continue;
            }
            if ($from !== null && str_contains($line, $until)) {
                return implode("\n", array_slice($this->lines, $from, $i - $from + 1));
            }
        }

        return $from !== null ? implode("\n", array_slice($this->lines, $from)) : null;
    }

    /** Wert hinter "Beschriftung:" bis zur naechsten Spalte. */
    private function labelValue(string $label): ?string
    {
        $re = '/^\s*'.preg_quote($label, '/').'\s*:\s*(.+)$/u';
        foreach ($this->lines as $line) {
            if (preg_match($re, $line, $m)) {
                $v = $this->firstCell($m[1]);

                return $v !== '' ? $v : null;
            }
        }

        return null;
    }

    /** Linke Zelle einer Layout-Zeile (getrennt durch 3+ Leerzeichen). */
    private function firstCell(string $line): string
    {
        return trim((string) (preg_split('/\s{3,}/u', trim($line))[0] ?? ''));
    }

    private function normName(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }

    private function iso(string $date): string
    {
        [$d, $mo, $y] = explode('.', $date);

        return $y.'-'.$mo.'-'.$d;
    }
}
