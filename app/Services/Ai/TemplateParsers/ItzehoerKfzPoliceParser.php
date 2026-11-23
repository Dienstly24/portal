<?php

namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer den Kfz-VERSICHERUNGSSCHEIN der Itzehoer Versicherungen
 * (Betreiber-Meldung 23.11.2026, KI-129). Vorher griff kein Parser; die
 * Heuristik uebernahm dabei E-Mail und IBAN aus dem BRIEFFUSS - also die
 * Daten der Itzehoer - als Kundendaten.
 *
 * Gelesen wird die Tabelle "Beschriftung  Wert" des Scheins:
 * Versicherungsnehmer (Name + Anschrift im Wertblock), Beginn/Ablauf,
 * Fahrzeug (Kennzeichen, FIN, Hersteller, HSN/TSN, Leistung, Erstzulassung,
 * Kilometerstand, Zulassung auf den Halter, Fahrleistung), SF-Klassen samt
 * Sondereinstufung und der Gesamtbeitrag BRUTTO zur Zahlungsperiode.
 *
 * NICHT uebernommen:
 * - Bankdaten: die Kunden-IBAN ist maskiert ("DE92 2145 0000 XXXX XXXX 34"),
 *   die vollstaendige IBAN im Brieffuss gehoert der Itzehoer.
 * - Kontakte der Itzehoer (info@itzehoer.de, Telefon) - nie Kundendaten.
 * - Der Grund einer Sondereinstufung: der Schein nennt ihn nicht, er wird
 *   nicht geraten (Zweitwagen? Fuehrerschein?).
 * - Erstbeitrag/Rechnung: sie decken einen Teilzeitraum ab; massgeblich ist
 *   der wiederkehrende Gesamtbeitrag.
 */
class ItzehoerKfzPoliceParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /** @var list<string> */
    private array $lines = [];

    private string $text = '';

    public function parse(string $text): ?array
    {
        $upper = mb_strtoupper($text);
        if (! str_contains($upper, 'ITZEHOER')
            || ! preg_match('/^\s*VERSICHERUNGSSCHEIN\b/mu', $upper)
            || ! str_contains($upper, 'VERSICHERUNGS-NR.')
            || ! preg_match('/AMTLICHES\s+KENNZEICHEN|FAHRZEUG-IDENTIFIZIERUNGS-NR/u', $upper)) {
            return null;
        }

        $this->text = $text;
        $this->lines = array_map('rtrim', preg_split('/\R/u', $text) ?: []);

        $person = $this->parsePerson();
        $vehicle = $this->parseVehicle($person);
        $insurance = $this->parseInsurance();

        if (! isset($insurance['contract_number']) && ! isset($vehicle['license_plate']) && ! isset($vehicle['vin'])) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        $nachtrag = (bool) preg_match('/^\s*Nachtrag\b/mu', $text);

        $summary = 'Itzehoer Kfz-Versicherungsschein'.($nachtrag ? ' (Nachtrag)' : '')
            .($name !== '' ? ' - '.$name : '')
            .(isset($vehicle['license_plate']) ? ' - '.$vehicle['license_plate'] : '')
            .(isset($insurance['contract_number']) ? ' - Vers.-Nr. '.$insurance['contract_number'] : '')
            .(isset($insurance['tariff']) ? ' - Tarif '.$insurance['tariff'] : '')
            .$this->sfSummary($vehicle)
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
            'confidence' => 80,
            'summary' => $summary,
            'title' => 'Itzehoer Kfz-Versicherung'.($name !== '' ? ' '.$name : ''),
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
     * "Versicherungsnehmer   Herr Max Muster" und darunter in DERSELBEN
     * Wertspalte Strasse und "PLZ Ort". Der Briefkopf wird bewusst nicht
     * gelesen - der Wertblock ist eindeutig beschriftet.
     *
     * @return array<string,mixed>
     */
    private function parsePerson(): array
    {
        $raw = [];
        foreach ($this->lines as $i => $line) {
            if (! preg_match('/^\s*Versicherungsnehmer\s{2,}(Herrn?|Frau)?\s*(\S.*)$/u', $line, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $nameText = $this->firstCell($m[2][0]);
            if (! preg_match('/^\p{L}[\p{L}\-\'.]*(?:\s+\p{L}[\p{L}\-\'.]*)+$/u', $nameText)) {
                continue;
            }
            $col = mb_strlen(substr($line, 0, $m[$m[1][0] !== '' ? 1 : 2][1]));
            $street = $zip = null;
            for ($j = $i + 1; $j <= $i + 3 && $j < count($this->lines); $j++) {
                $l = $this->lines[$j];
                $start = mb_strlen($l) - mb_strlen(ltrim($l));
                if (trim($l) === '' || abs($start - $col) > 3) {
                    break;
                }
                $cell = $this->firstCell($l);
                if ($street === null && preg_match('/^\p{L}.*\d/u', $cell)) {
                    $street = $cell;
                } elseif (preg_match('/^(\d{5})\s+(\p{L}.*)$/u', $cell, $z)) {
                    $zip = $z;
                    break;
                }
            }

            if ($m[1][0] !== '') {
                $raw['gender'] = mb_strtolower($m[1][0]) === 'frau' ? 'female' : 'male';
            }
            $parts = preg_split('/\s+/u', $nameText) ?: [];
            $raw['last_name'] = array_pop($parts);
            $raw['first_name'] = implode(' ', $parts);
            if ($street !== null && $zip !== null) {
                $raw['street'] = $street;
                $raw['zip'] = $zip[1];
                $raw['city'] = trim($zip[2]);
            }
            break;
        }

        if (($v = $this->labelValue('Geburtsdatum Versicherungsnehmer')) !== null
            && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $v, $d)) {
            $raw['birth_date'] = $d[3].'-'.$d[2].'-'.$d[1];
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
        if (($v = $this->labelValue('Fahrzeugart/-verwendung')) !== null) {
            $raw['vehicle_type'] = $this->mapVehicleType($v);
        }
        // "FIAT (I)" - die Klammer nennt das Herkunftsland.
        if (($v = $this->labelValue('Hersteller')) !== null) {
            $marke = trim((string) preg_replace('/\s*\([A-Z]{1,3}\)\s*$/u', '', $v));
            if (preg_match('/^\p{L}[\p{L}0-9 .\-]{1,40}$/u', $marke)) {
                $raw['manufacturer'] = $marke;
            }
        }
        if (($v = $this->labelValue('Hersteller-/Typschlüssel-Nr.')) !== null
            && preg_match('#^(\d{4})\s*/\s*([A-Z0-9]{3})#iu', $v, $m)) {
            $raw['hsn'] = $m[1];
            $raw['tsn'] = strtoupper($m[2]);
        }
        if (($v = $this->labelValue('Leistung')) !== null && preg_match('/^(\d{1,4})\s*kW/iu', $v, $m)) {
            $raw['power_kw'] = (int) $m[1];
        }
        if (($v = $this->labelValue('Erstzulassung')) !== null && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $v, $m)) {
            $raw['first_registration'] = $m[3].'-'.$m[2].'-'.$m[1];
        }
        if (($v = $this->labelValue('Zulassung auf den aktuellen Halter')) !== null
            && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $v, $m)) {
            $raw['acquisition_date'] = $m[3].'-'.$m[2].'-'.$m[1];
        }
        if (($v = $this->labelValue('Kilometerstand')) !== null && preg_match('/^([\d.]{1,9})\s*km/iu', $v, $m)) {
            $raw['initial_mileage'] = (int) str_replace('.', '', $m[1]);
        }
        if (($v = $this->labelValue('Jährliche Fahrleistung')) !== null && preg_match('/^([\d.]{1,7})\s*km/iu', $v, $m)) {
            $raw['annual_mileage'] = (int) str_replace('.', '', $m[1]);
        }

        // Halter = Versicherungsnehmer nur bei GLEICHEM Namen.
        $halter = $this->labelValue('Halter');
        $vn = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        if ($halter !== null && $vn !== '') {
            $halter = trim((string) preg_replace('/^(Herrn?|Frau)\s+/u', '', $halter));
            $raw['holder_type'] = $this->normName($halter) === $this->normName($vn)
                ? 'versicherungsnehmer' : 'abweichender_halter';
        }

        $raw += $this->sfClasses();
        $raw += $this->coverage();

        return $this->validatedVehicle($raw);
    }

    /**
     * Abschnitt "Vorversicherung und Schadenfreiheitsklassen": je Sparte
     * "Tatsaechliche SF-Klasse" und ggf. "Sondereinstufung bei der Itzehoer".
     * Mit Sondereinstufung ist SIE die Klasse des Vertrags (nach ihr wird
     * der Beitrag berechnet), die tatsaechliche Klasse ist die uebertragbare.
     *
     * @return array<string,mixed>
     */
    private function sfClasses(): array
    {
        $in = false;
        $sparte = null;
        $werte = [];
        foreach ($this->lines as $line) {
            $t = trim($line);
            if (str_starts_with($t, 'Vorversicherung und Schadenfreiheitsklassen')) {
                $in = true;

                continue;
            }
            if (! $in) {
                continue;
            }
            if (preg_match('/^(Hinweis|Versicherungsumfang|Seite\s+\d)/u', $t)) {
                break;
            }
            if (preg_match('/^Kfz-Haftpflicht(versicherung)?$/u', $t)) {
                $sparte = 'kh';
            } elseif (preg_match('/^Vollkasko(versicherung)?$/u', $t)) {
                $sparte = 'vk';
            } elseif ($sparte !== null
                && preg_match('/^(Tatsächliche SF-Klasse|Sondereinstufung bei der Itzehoer)\s{2,}(?:SF|Klasse)?\s*(\d{1,2}\/2|\d{1,2}|[MS])\b/u', $t, $m)) {
                $werte[$sparte][str_starts_with($m[1], 'Tats') ? 'real' : 'sonder'] = $m[2];
            }
        }

        $raw = [];
        if (isset($werte['kh'])) {
            if (isset($werte['kh']['sonder'])) {
                $raw['sf_liability_class'] = $werte['kh']['sonder'];
                $raw['sf_liability_type'] = 'sondereinstufung';
                if (isset($werte['kh']['real'])) {
                    $raw['sf_liability_real_class'] = $werte['kh']['real'];
                }
            } elseif (isset($werte['kh']['real'])) {
                $raw['sf_liability_class'] = $werte['kh']['real'];
                $raw['sf_liability_type'] = 'tatsaechlich';
            }
        }
        if (isset($werte['vk'])) {
            $raw['sf_comprehensive_class'] = $werte['vk']['sonder'] ?? $werte['vk']['real'] ?? null;
        }

        return array_filter($raw, fn ($v) => $v !== null);
    }

    /**
     * Deckung NUR aus "Versicherungsumfang/-beitrag" (die Rechtstexte nennen
     * Kasko allgemein).
     *
     * @return array<string,mixed>
     */
    private function coverage(): array
    {
        $umfang = $this->section('Versicherungsumfang', 'Gesamtbeitrag');
        if ($umfang === null) {
            return [];
        }
        $raw = [
            'has_vollkasko' => (bool) preg_match('/Vollkasko/iu', $umfang),
        ];
        $raw['has_teilkasko'] = $raw['has_vollkasko'] || (bool) preg_match('/Teilkasko/iu', $umfang);
        if (preg_match('/Teilkasko\S*\s+mit\s+([\d.]+)\s*€\s+Selbstbeteiligung/iu', $umfang, $m)) {
            $raw['teilkasko_deductible'] = (int) str_replace('.', '', $m[1]);
        }
        if (preg_match('/Vollkasko\S*\s+mit\s+([\d.]+)\s*€\s+Selbstbeteiligung/iu', $umfang, $m)) {
            $raw['vollkasko_deductible'] = (int) str_replace('.', '', $m[1]);
        }
        $extras = [];
        foreach (['schutzbrief' => 'Schutzbrief', 'werkstattbindung' => 'Werkstattbindung',
            'rabattschutz' => 'Rabattschutz', 'fahrerschutz' => 'Fahrerschutz'] as $key => $wort) {
            if (preg_match('/'.$wort.'/iu', $umfang)) {
                $extras[] = $key;
            }
        }
        if ($extras !== []) {
            $raw['extras'] = $extras;
        }

        return $raw;
    }

    /** @return array<string,mixed> */
    private function parseInsurance(): array
    {
        $raw = [
            'sparte' => 'kfz',
            'insurer' => 'Itzehoer Versicherungen',
            'document_stage' => Contract::STAGE_VERTRAG,
        ];

        if (preg_match('/Versicherungs-Nr\.\s+([0-9A-Z]{1,3}-\d{6,10}-\d{1,4})\b/u', $this->text, $m)) {
            $raw['contract_number'] = $m[1];
        }
        if (($v = $this->labelValue('Beginn der Versicherung')) !== null && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $v, $m)) {
            $raw['start_date'] = $m[3].'-'.$m[2].'-'.$m[1];
        }
        if (($v = $this->labelValue('Ablauf der Versicherung')) !== null && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $v, $m)) {
            $raw['end_date'] = $m[3].'-'.$m[2].'-'.$m[1];
        }

        // Tarif: "COMFORT DRIVE mit:" als Kopf der Leistungsliste.
        $umfang = $this->section('Versicherungsumfang', 'Gesamtbeitrag');
        if ($umfang !== null && preg_match('/^\s*(\p{Lu}[\p{Lu}0-9 \-]{2,40}?)\s+mit:\s*$/mu', $umfang, $m)) {
            $raw['tariff'] = trim($m[1]);
        }

        // "Gesamtbeitrag inkl. 19 % Versicherungssteuer (50,62 €)   317,02 €":
        // der LETZTE Betrag der Zeile, nicht die Steuer in der Klammer.
        foreach ($this->lines as $line) {
            if (preg_match('/^\s*Gesamtbeitrag\b/u', $line)
                && preg_match_all('/(\d{1,3}(?:\.\d{3})*,\d{2})\s*€/u', $line, $mm)) {
                $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $mm[1][count($mm[1]) - 1]);
                $raw['premium_interval'] = $this->interval();
                break;
            }
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => (string) $v !== ''));
    }

    private function interval(): string
    {
        $v = mb_strtolower((string) $this->labelValue('Zahlungsperiode'));

        return match (true) {
            str_contains($v, 'viertelj') => 'quarterly',
            str_contains($v, 'halbj') => 'semiannual',
            str_contains($v, 'monatlich') => 'monthly',
            default => 'yearly',
        };
    }

    /** @param array<string,mixed> $kfz */
    private function sfSummary(array $kfz): string
    {
        $out = '';
        if (isset($kfz['sf_liability_class'])) {
            $out .= ' - SF '.$kfz['sf_liability_class'].' (Haftpflicht'
                .(($kfz['sf_liability_type'] ?? null) === 'sondereinstufung'
                    ? ', Sondereinstufung'.(isset($kfz['sf_liability_real_class']) ? '; tatsächlich SF '.$kfz['sf_liability_real_class'] : '')
                    : '')
                .')';
        }
        if (isset($kfz['sf_comprehensive_class'])) {
            $out .= ' - SF '.$kfz['sf_comprehensive_class'].' (Vollkasko)';
        }

        return $out;
    }

    /** @param array<string,mixed> $kfz */
    private function coverageSummary(array $kfz): string
    {
        $parts = ['Haftpflicht'];
        if (! empty($kfz['has_vollkasko'])) {
            $parts[] = 'Vollkasko'.(isset($kfz['vollkasko_deductible']) ? ' (SB '.$kfz['vollkasko_deductible'].' EUR)' : '');
        } elseif (! empty($kfz['has_teilkasko'])) {
            $parts[] = 'Teilkasko'.(isset($kfz['teilkasko_deductible']) ? ' (SB '.$kfz['teilkasko_deductible'].' EUR)' : '');
        } elseif (isset($kfz['has_teilkasko'])) {
            $parts[] = 'keine Kasko';
        }

        return implode(', ', $parts);
    }

    /** Angaben ohne eigenes Feld - nur fuer die Zusammenfassung. */
    private function extraNotes(): string
    {
        $notes = '';
        foreach (['Vertragsgrund', 'Fahrzeugnutzung', 'Regionalklasse Kfz-Haftpflichtversicherung', 'Fahrzeugart/-verwendung'] as $label) {
            if (($v = $this->labelValue($label)) !== null) {
                $notes .= ' - '.$label.': '.$v;
            }
        }

        return $notes;
    }

    private function mapVehicleType(string $value): ?string
    {
        $v = mb_strtolower($value);

        return match (true) {
            str_contains($v, 'lieferwagen') => 'transporter',
            str_starts_with($v, 'pkw') || str_contains($v, 'personenkraftwagen') => 'pkw',
            str_contains($v, 'wohnmobil') => 'wohnmobil',
            str_contains($v, 'anhänger') => 'anhaenger',
            default => null,
        };
    }

    /** Text von einer Ueberschrift bis zur ersten Zeile, die $until enthaelt. */
    private function section(string $heading, string $until): ?string
    {
        $from = null;
        foreach ($this->lines as $i => $line) {
            if ($from === null && str_starts_with(trim($line), $heading)) {
                $from = $i;

                continue;
            }
            if ($from !== null && str_contains($line, $until)) {
                return implode("\n", array_slice($this->lines, $from, $i - $from + 1));
            }
        }

        return null;
    }

    /** Wert hinter "Beschriftung" (2+ Leerzeichen Abstand), erste Zelle. */
    private function labelValue(string $label): ?string
    {
        $re = '/^\s*'.preg_quote($label, '/').'\s{2,}(\S.*)$/u';
        foreach ($this->lines as $line) {
            if (preg_match($re, $line, $m)) {
                $v = $this->firstCell($m[1]);

                return $v !== '' ? $v : null;
            }
        }

        return null;
    }

    private function firstCell(string $s): string
    {
        return trim((string) (preg_split('/\s{3,}/u', trim($s))[0] ?? ''));
    }

    private function normName(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }
}
