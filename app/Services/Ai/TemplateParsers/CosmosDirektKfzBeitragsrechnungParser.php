<?php

namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ReadsDocumentPages;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer die Kfz-BEITRAGSRECHNUNG der CosmosDirekt (Cosmos
 * Versicherung AG, Saarbruecken), Betreiber-Meldung 22.11.2026: das Schreiben
 * landete im Eingang als "Rechnung, 40 % sicher, Kein Kunde gefunden" und
 * wartete auf die kostenpflichtige KI-Freigabe.
 *
 * Aufbau (digitale Textebene, pdftotext -layout):
 *   - Kopf zweispaltig: links Empfaenger (Anrede, Name, Strasse, PLZ Ort),
 *     rechts Werbetext/Service ("Wir sind immer / für Sie da!",
 *     "www.cosmosdirekt.de") auf DERSELBEN Zeile.
 *   - "Beitragsrechnung für Ihre Autoversicherung Nr. 924 073 119" und
 *     "Amtliches Kennzeichen: NM-QY 8194".
 *   - Je Sparte eine Tabelle ALTES Jahr | NEUES Jahr (SF-Klasse,
 *     Beitragssatz, Regionalklasse, Typklasse, Beitrag).
 *   - "Ihr neuer Beitrag 607,62 EUR jährlich" = Gesamtbeitrag BRUTTO.
 *
 * Regeln:
 *   - Gelesen wird die Spalte des NEUEN Jahres (sie gilt ab Faelligkeit).
 *   - Die Faelligkeit ("Am 01.01.2027 wird Ihr Beitrag fällig") ist NICHT der
 *     Vertragsbeginn - sie steht nur in der Zusammenfassung.
 *   - Vergleichsbeitrag und Sonderkuendigungsrecht stehen nur in der
 *     Zusammenfassung (kein eigenes Feld).
 *   - KEINE Bankdaten: die Kunden-IBAN ist maskiert, die IBANs im Brieffuss
 *     gehoeren der Cosmos.
 */
class CosmosDirektKfzBeitragsrechnungParser implements DocumentTemplateParser
{
    use ReadsDocumentPages;
    use ValidatesExtractedFields;

    private const INSURER = 'CosmosDirekt';

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $upper = mb_strtoupper($text);

        if ($this->looksLikeComparisonProtocol($text)) {
            return null;
        }
        if (! preg_match('/COSMOS\s*DIREKT|COSMOS VERSICHERUNG/u', $upper)
            || ! preg_match('/BEITRAGSRECHNUNG\s+F[ÜU]R\s+IHRE\s+AUTOVERSICHERUNG/u', $upper)) {
            return null;
        }

        $this->lines = preg_split('/\R/', $text) ?: [];
        $flat = (string) preg_replace('/\s+/u', ' ', $text);

        $person = $this->parsePerson();
        $vehicle = $this->parseVehicle($text, $flat);
        $insurance = $this->parseInsurance($flat);

        if (($insurance['contract_number'] ?? null) === null && ($vehicle['license_plate'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));

        return [
            'type' => 'kfz_vertrag',
            'confidence' => 80,
            'summary' => $this->summary($text, $flat, $name, $vehicle, $insurance),
            'title' => 'CosmosDirekt Kfz-Versicherung'.($name !== '' ? ' '.$name : ''),
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

    /** @return array<string,mixed> */
    private function parsePerson(): array
    {
        $raw = [];
        foreach ($this->lines as $i => $line) {
            $cols = $this->columns($line);
            if ($cols === [] || ! preg_match('/^(Herrn|Herr|Frau)$/u', $cols[0])) {
                continue;
            }
            $raw['gender'] = $cols[0] === 'Frau' ? 'female' : 'male';

            $block = [];
            for ($j = $i + 1; $j < count($this->lines) && count($block) < 3; $j++) {
                $c = $this->columns($this->lines[$j]);
                if ($c !== []) {
                    $block[] = $c[0];
                }
            }
            if (count($block) === 3
                && preg_match('/^[A-ZÄÖÜ][\p{L}\-]+(?:\s+[A-ZÄÖÜ][\p{L}\-]+)+$/u', $block[0])
                && preg_match('/^(\d{5})\s+(.+)$/u', $block[2], $z)) {
                $parts = preg_split('/\s+/', $block[0]) ?: [];
                $raw['last_name'] = array_pop($parts);
                $raw['first_name'] = implode(' ', $parts) ?: null;
                // Strasse + Hausnummer trennt validatedPerson() zentral.
                $raw['street'] = $block[1];
                $raw['zip'] = $z[1];
                $raw['city'] = trim($z[2]);
            }
            break;
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseVehicle(string $text, string $flat): array
    {
        $raw = [];

        if (preg_match('/Amtliches Kennzeichen:\s*([A-ZÄÖÜ]{1,3}-[A-Z]{1,2}\s?\d{1,4}[EH]?)\b/u', $text, $m)) {
            $raw['license_plate'] = $m[1];
        }

        $haft = $this->section($text, 'Kfz-Haftpflichtversicherung');
        if ($haft !== null && ($sf = $this->newYearValue($haft, 'SF-Klasse')) !== null) {
            $raw['sf_liability_class'] = $sf;
        }

        $teil = $this->section($text, 'Teilkaskoversicherung');
        $voll = $this->section($text, 'Vollkaskoversicherung');
        if ($teil !== null || $voll !== null) {
            $raw['has_teilkasko'] = true; // Vollkasko schliesst Teilkasko ein
            $raw['has_vollkasko'] = $voll !== null;
        }
        if ($teil !== null && preg_match('/Selbstbeteiligung:?\s*([\d.]+)\s*(?:EUR|€)/u', $teil, $m)) {
            $raw['teilkasko_deductible'] = (int) str_replace('.', '', $m[1]);
        }
        if ($voll !== null) {
            if (preg_match('/Selbstbeteiligung:?\s*([\d.]+)\s*(?:EUR|€)/u', $voll, $m)) {
                $raw['vollkasko_deductible'] = (int) str_replace('.', '', $m[1]);
            }
            if (($sf = $this->newYearValue($voll, 'SF-Klasse')) !== null) {
                $raw['sf_comprehensive_class'] = $sf;
            }
        }

        $extras = [];
        if (preg_match('/mit Werkstattbindung/u', $flat)) {
            $extras[] = 'werkstattbindung';
        }
        if ($this->section($text, 'Verkehrs-Rechtsschutzversicherung') !== null) {
            $extras[] = 'verkehrsrechtsschutz';
        }
        if (preg_match('/^\s*Schutzbrief/um', $text)) {
            $extras[] = 'schutzbrief';
        }
        if ($extras !== []) {
            $raw['extras'] = $extras;
        }

        return $this->validatedVehicle($raw);
    }

    /** @return array<string,mixed> */
    private function parseInsurance(string $flat): array
    {
        $raw = ['sparte' => 'kfz', 'insurer' => self::INSURER];

        if (preg_match('/Autoversicherung Nr\.\s*(\d{3}\s?\d{3}\s?\d{3})\b/u', $flat, $m)) {
            $raw['contract_number'] = str_replace(' ', '', $m[1]);
        }
        if (preg_match('/Kfz-Haftpflichtversicherung\s+(Basis-Schutz|Komfort-Schutz|Premium-Schutz|[A-ZÄÖÜ][\p{L}]+-Schutz)/u', $flat, $m)) {
            $raw['tariff'] = $m[1];
        }
        // Gesamtbeitrag der Tabelle ("Ihr neuer Beitrag 607,62 EUR jährlich").
        if (preg_match('/Ihr neuer Beitrag\s+([\d.]+,\d{2})\s*(?:EUR|€)\s*(jährlich|halbjährlich|vierteljährlich|monatlich)?/u', $flat, $m)) {
            $raw['premium_amount'] = $this->amount($m[1]);
            $raw['premium_interval'] = match ($m[2] ?? '') {
                'monatlich' => 'monthly',
                'vierteljährlich' => 'quarterly',
                'halbjährlich' => 'semiannual',
                default => 'yearly',
            };
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    private function summary(string $text, string $flat, string $name, array $kfz, array $ins): string
    {
        $s = 'CosmosDirekt Kfz-Beitragsrechnung (Kfz-Vertrag)'
            .($name !== '' ? ' - '.$name : '')
            .(isset($kfz['license_plate']) ? ' - '.$kfz['license_plate'] : '')
            .(isset($ins['contract_number']) ? ' - Vertrag '.$ins['contract_number'] : '');

        if (preg_match('/Am (\d{2}\.\d{2}\.\d{4}) wird Ihr Beitrag fällig/u', $flat, $m)) {
            $s .= ' - neuer Beitrag fällig ab '.$m[1];
        }
        if (isset($ins['premium_amount'])) {
            $s .= ' - Gesamtbeitrag '.number_format($ins['premium_amount'], 2, ',', '.').' EUR';
        }

        $teile = [];
        foreach (['Kfz-Haftpflichtversicherung' => 'Haftpflicht', 'Teilkaskoversicherung' => 'Teilkasko',
            'Vollkaskoversicherung' => 'Vollkasko', 'Verkehrs-Rechtsschutzversicherung' => 'Verkehrs-Rechtsschutz'] as $kopf => $label) {
            $blk = $this->section($text, $kopf);
            if ($blk === null) {
                continue;
            }
            $b = $this->yearPair($blk, 'Beitrag');
            $sf = $this->yearPair($blk, 'SF-Klasse');
            $teile[] = $label
                .($sf !== null ? ' '.$sf[0].' -> '.$sf[1] : '')
                .($b !== null ? ' ('.$b[0].' -> '.$b[1].' EUR)' : '');
        }
        if ($teile !== []) {
            $s .= ' - '.implode('; ', $teile);
        }
        if (preg_match('/Ihr Vergleichsbeitrag:\s*([^\n]+?EUR)\.?\s*Der Vergleichsbeitrag/u', $flat, $m)) {
            $s .= ' - Vergleichsbeitrag: '.trim($m[1]);
        }

        return $s.' - Felder gratis aus dem Schreiben gelesen (ohne KI).';
    }

    /**
     * Abschnitt einer Sparte: ab ihrer Ueberschrift (Zeilenanfang) bis zur
     * naechsten Sparten-Ueberschrift oder dem Gesamtbeitrag. Nur die
     * Tabellenseite zaehlt - das Kleingedruckte nennt die Sparten im
     * Fliesstext ("Kfz-Haftpflichtversicherung: 446,90 EUR").
     */
    private function section(string $text, string $heading): ?string
    {
        if (! preg_match('/^\s*'.preg_quote($heading, '/').'(?!:)[^\n]*\n/um', $text, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $rest = substr($text, $m[0][1]);
        $end = strlen($rest);
        if (preg_match('/\n\s*(?:Kfz-Haftpflichtversicherung|Teilkaskoversicherung|Vollkaskoversicherung|Verkehrs-Rechtsschutzversicherung|Schutzbrief|Ihr neuer Beitrag)\b/u',
            $rest, $n, PREG_OFFSET_CAPTURE, strlen($m[0][0]) - 1)) {
            $end = $n[0][1];
        }

        return substr($rest, 0, $end);
    }

    /**
     * "Label  alt   Label  neu" aus der Zweispalten-Tabelle. Bewusst mit
     * einfachem Leerraum - eine OCR-Fassung haelt die Spaltenabstaende nicht.
     *
     * @return array{0:string,1:string}|null
     */
    private function yearPair(string $block, string $label): ?array
    {
        $l = preg_quote($label, '/');
        if (! preg_match('/^\s*'.$l.'\s+(.+?)\s+'.$l.'\s+(.+?)\s*$/um', $block, $m)) {
            return null;
        }
        $clean = fn (string $v) => trim((string) preg_replace('/\s*(EUR|€|%)\s*$/u', '', $v));

        return [$clean($m[1]), $clean($m[2])];
    }

    private function newYearValue(string $block, string $label): ?string
    {
        return $this->yearPair($block, $label)[1] ?? null;
    }

    private function amount(string $v): float
    {
        return (float) str_replace(['.', ','], ['', '.'], $v);
    }

    /** @return list<string> */
    private function columns(string $line): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\s{2,}/', trim($line)) ?: []),
            fn ($c) => $c !== ''
        ));
    }
}
