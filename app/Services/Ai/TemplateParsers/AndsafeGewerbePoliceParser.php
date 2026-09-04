<?php

namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\ReadsDocumentPages;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Parser fuer den Versicherungsschein der andsafe AG (Provinzial-Gruppe,
 * Online-Gewerbeversicherer) - z.B. die "andsafe Betriebshaftpflicht-
 * versicherung" eines Handwerksbetriebs. Aufbau (digitales PDF,
 * Beschriftung links, Wert rechts):
 *
 *   Kopf     : "Versicherungsschein" + "Versicherung <Produkt>"
 *              + "Vertragsnummer BH..."
 *   Kunde    : Block "Versicherungsnehmer:in" (Name / Strasse / PLZ Ort)
 *              + "Kontakt-E-Mail-Adresse"
 *   Laufzeit : "Versicherungsbeginn"/"Versicherungsablauf" (mit Uhrzeit)
 *   Umfang   : Hauptgewerbe, Mitversicherte Gewerbe, Jahresumsatz,
 *              Versicherungssumme, Selbstbeteiligung, optionale Einschluesse
 *   Beitrag  : "Jahresbeitrag" (brutto) und die Beitragsrechnung mit
 *              "Vereinbarte Zahlungsweise" + "Gesamtforderung" (der
 *              tatsaechlich wiederkehrende Betrag).
 *
 * Regeln (Betreiber-Vorgaben):
 *  - Die Sparte kommt AUSSCHLIESSLICH aus dem Feld "Versicherung"; der
 *    Abschnitt "Optionale Einschluesse" nennt zusaetzlich eine
 *    Privathaftpflicht - sie ist ein Baustein dieses Vertrags und darf die
 *    Sparte nicht kippen. Unbekanntes Produkt -> Sparte bleibt leer.
 *  - KEINE Bankdaten: die Kunden-IBAN ist maskiert ("DEXXXX...2807"), die
 *    vollstaendige IBAN im Brieffuss gehoert der andsafe AG selbst.
 *  - Die Absenderzeile und der Briefkopf der Gesellschaft werden NIE
 *    Kundendaten (gelesen wird der beschriftete VN-Block).
 *  - Stufe 'vertrag': echter Versicherungsschein mit Vertragsnummer.
 */
class AndsafeGewerbePoliceParser implements DocumentTemplateParser
{
    use ReadsDocumentPages;
    use ValidatesExtractedFields;

    private const INSURER = 'andsafe AG';

    /** Eigene Domains des Versicherers - nie Kunden-E-Mail. */
    private const INSURER_MAIL_DOMAINS = ['andsafe.de', 'provinzial.de'];

    private string $text = '';

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $this->text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $upper = mb_strtoupper($this->text);

        if ($this->looksLikeComparisonProtocol($this->text)) {
            return null;
        }
        if (! str_contains($upper, 'ANDSAFE')
            || ! str_contains($upper, 'VERSICHERUNGSSCHEIN')
            || ! str_contains($upper, 'VERTRAGSNUMMER')) {
            return null;
        }

        $this->lines = array_map('rtrim', preg_split('/\R/', $this->text) ?: []);

        $insurance = $this->parseInsurance();
        if (($insurance['contract_number'] ?? null) === null) {
            return null; // ohne Vertragsnummer lieber die normale Analyse
        }
        $person = $this->parsePerson();

        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        $sparte = $insurance['sparte'] ?? null;

        return [
            'type' => 'versicherungspolice',
            'confidence' => 78,
            'summary' => 'andsafe Versicherungsschein'
                .($sparte !== null ? ' - '.(Contract::TYPES[$sparte]['label'] ?? $sparte) : '')
                .($name !== '' ? ' - '.$name : '')
                .' - Vertrag '.$insurance['contract_number']
                .$this->extras()
                .' Keine Bankuebernahme (Kunden-IBAN ist maskiert).'
                .' Felder gratis aus dem Versicherungsschein gelesen (ohne KI).',
            'title' => 'andsafe '.($insurance['tariff'] ?? 'Versicherungsschein')
                .($name !== '' ? ' - '.$name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $insurance,
                'kfz' => [],
                'gesundheit' => [],
                // Bewusst leer: maskierte Kunden-IBAN + Konto der Gesellschaft.
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

        // Beschrifteter VN-Block: Name, Strasse, PLZ Ort (eingerueckte
        // Folgezeilen). Der Briefkopf/die Absenderzeile bleiben aussen vor.
        $block = $this->blockValues('Versicherungsnehmer:in')
            ?: $this->blockValues('Versicherungsnehmer');
        foreach ($block as $zeile) {
            if (! isset($raw['zip']) && preg_match('/^(\d{5})\s+(\p{L}[\p{L}.\- ]*)$/u', $zeile, $z)) {
                $raw['zip'] = $z[1];
                $raw['city'] = trim($z[2]);
                continue;
            }
            if (! isset($raw['street'])
                && preg_match('/^(\p{L}[\p{L}.\-\' ]*\p{L}\.?)\s+(\d+\s*[a-zA-Z]?)$/u', $zeile, $s)) {
                $raw['street'] = trim($s[1]);
                $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
                continue;
            }
            // Personenname (letztes Wort = Nachname) bzw. Firmenname.
            if (! isset($raw['last_name']) && ! isset($raw['company_name'])) {
                if ($this->looksLikeCompany($zeile)) {
                    $raw['company_name'] = $zeile;
                } elseif (preg_match('/^\p{Lu}[\p{L}\-\']+(?:\s+\p{Lu}[\p{L}\-\']+){1,3}$/u', $zeile)) {
                    $teile = preg_split('/\s+/u', $zeile) ?: [];
                    $raw['last_name'] = array_pop($teile);
                    $raw['first_name'] = implode(' ', $teile) ?: null;
                }
            }
        }

        if (($v = $this->labelValue('Kontakt-E-Mail-Adresse') ?? $this->labelValue('E-Mail-Adresse')) !== null
            && preg_match('/[\w.+\-]+@[\w.\-]+\.\w{2,}/u', $v, $m)
            && ! $this->isInsurerMail($m[0])) {
            $raw['email'] = mb_strtolower($m[0]);
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseInsurance(): array
    {
        $raw = [
            'insurer' => self::INSURER,
            'document_stage' => Contract::STAGE_VERTRAG,
        ];

        if (($v = $this->labelValue('Vertragsnummer')) !== null
            && preg_match('/^[A-Z0-9\-\/]{6,30}$/i', trim($v))) {
            $raw['contract_number'] = trim($v);
        }

        // Produkt + Sparte NUR aus dem Feld "Versicherung" - der Abschnitt
        // "Optionale Einschluesse" nennt zusaetzlich eine Privathaftpflicht.
        $produkt = $this->labelValue('Versicherung');
        if ($produkt !== null) {
            $raw['tariff'] = $produkt;
            $raw['sparte'] = $this->mapSparte($produkt);
        }

        if (($v = $this->labelValue('Versicherungsbeginn')) !== null
            && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $v, $m)) {
            $raw['start_date'] = $m[3].'-'.$m[2].'-'.$m[1];
        }
        if (($v = $this->labelValue('Versicherungsablauf')) !== null
            && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $v, $m)) {
            $raw['end_date'] = $m[3].'-'.$m[2].'-'.$m[1];
        }

        // Beitrag: der WIEDERKEHRENDE Brutto-Betrag der Beitragsrechnung
        // ("Gesamtforderung" zur vereinbarten Zahlungsweise), ersatzweise der
        // Jahresbeitrag. Der "Nettobeitrag" der optionalen Bausteine ist NICHT
        // der Vertragsbeitrag.
        $interval = $this->interval((string) $this->labelValue('Vereinbarte Zahlungsweise'));
        if ($interval !== null && ($v = $this->labelValue('Gesamtforderung')) !== null
            && preg_match('/([\d.]+,\d{2})/', $v, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = $interval;
        } elseif (($v = $this->labelValue('Jahresbeitrag')) !== null
            && preg_match('/([\d.]+,\d{2})/', $v, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = 'yearly';
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** Zusatzangaben (Gewerbe, Summen, Bausteine) fuer die Zusammenfassung. */
    private function extras(): string
    {
        $out = '.';
        foreach ([
            'Hauptgewerbe' => 'Hauptgewerbe',
            'Mitversicherte Gewerbe' => 'Mitversichert',
            'Jahresumsatz' => 'Jahresumsatz',
            'Versicherungssumme' => 'Versicherungssumme',
        ] as $label => $titel) {
            if (($v = $this->labelText($label)) !== null) {
                $out .= ' '.$titel.': '.rtrim($v, '.').'.';
            }
        }
        if (($v = $this->labelText('Selbstbeteiligung je Schadenfall')) !== null) {
            $out .= ' Selbstbeteiligung: '.rtrim($v, '.').'.';
        }
        // Optionale Bausteine stehen als eigene Ueberschrift im Dokument.
        if (preg_match('/Optionale Einschlüsse\s*\R+\s*\R*\s*(\p{Lu}[^\r\n]{3,60}?)\s{2,}/u', $this->text, $m)) {
            $out .= ' Optionaler Einschluss: '.trim($m[1]).'.';
        }
        if (($v = $this->labelValue('Jahresbeitrag')) !== null) {
            $out .= ' Jahresbeitrag (brutto): '.$v.'.';
        }
        if (($v = $this->labelValue('Antragsdatum')) !== null) {
            $out .= ' Antrag vom '.$v.'.';
        }
        return $out;
    }

    /** Sparte aus dem Produktnamen - unbekannt bleibt bewusst leer. */
    private function mapSparte(string $produkt): ?string
    {
        $p = mb_strtolower($produkt);
        return match (true) {
            str_contains($p, 'betriebshaftpflicht'), str_contains($p, 'berufshaftpflicht') => 'betriebshaftpflicht',
            str_contains($p, 'verkehrshaftung'), str_contains($p, 'frachtführer'),
            str_contains($p, 'frachtfuehrer') => 'frachtfuehrerhaftpflicht',
            str_contains($p, 'privathaftpflicht') => 'haftpflicht',
            str_contains($p, 'rechtsschutz') => 'rechtsschutz',
            str_contains($p, 'inhalt'), str_contains($p, 'geschäft'), str_contains($p, 'sach') => 'sach',
            default => null,
        };
    }

    private function looksLikeCompany(string $name): bool
    {
        return (bool) preg_match(
            '/(GmbH|mbH|\bUG\b|\bOHG\b|\bKG\b|\bGbR\b|\bAG\b|\be\.?\s?K\b|Einzelunt|Einzelfirma|Betrieb|Spedition|Logistik|Transport)/ui',
            $name
        );
    }

    private function interval(string $german): ?string
    {
        return match (mb_strtolower(trim($german))) {
            'monatlich' => 'monthly',
            'vierteljährlich' => 'quarterly',
            'halbjährlich' => 'semiannual',
            'jährlich' => 'yearly',
            default => null,
        };
    }

    private function isInsurerMail(string $mail): bool
    {
        $domain = ltrim(mb_strtolower(trim((string) strrchr(trim($mail), '@')), 'UTF-8'), '@');
        return in_array($domain, self::INSURER_MAIL_DOMAINS, true);
    }

    /**
     * Mehrzeiliger Wert als EIN Text: das PDF bricht lange Werte um und
     * trennt dabei mit Bindestrich ("... daraus resul-\ntierende Vermoegens-
     * schaeden"). Die Trennung wird rueckgaengig gemacht, damit in der
     * Zusammenfassung kein abgeschnittenes Wort steht.
     */
    private function labelText(string $label): ?string
    {
        $zeilen = $this->blockValues($label);
        if ($zeilen === []) {
            return null;
        }
        $text = '';
        foreach ($zeilen as $zeile) {
            if (str_ends_with($text, '-')) {
                $text = mb_substr($text, 0, -1).$zeile; // Silbentrennung aufheben
                continue;
            }
            $text = $text === '' ? $zeile : $text.' '.$zeile;
        }
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /** Wert hinter der Beschriftung (Spaltenlayout: mindestens zwei Leerzeichen). */
    private function labelValue(string $label): ?string
    {
        $re = '/^'.preg_quote($label, '/').'\s{2,}(\S[^\n]*?)\s*$/mu';
        return preg_match($re, $this->text, $m) ? trim($m[1]) : null;
    }

    /**
     * Wert-Zeilen eines mehrzeiligen Blocks: der Wert neben der Beschriftung
     * plus die eingerueckten Folgezeilen (bis zur naechsten Beschriftung am
     * Zeilenanfang oder einer Leerzeile).
     *
     * @return list<string>
     */
    private function blockValues(string $label): array
    {
        $out = [];
        foreach ($this->lines as $i => $line) {
            if (! preg_match('/^'.preg_quote($label, '/').'\s{2,}(\S.*)$/u', $line, $m)) {
                continue;
            }
            $out[] = trim($m[1]);
            $spalte = mb_strlen($line) - mb_strlen(ltrim($m[1])) - (mb_strlen($line) - mb_strlen(rtrim($line)));
            for ($j = $i + 1, $n = count($this->lines); $j < $n; $j++) {
                if (trim($this->lines[$j]) === '' || ! preg_match('/^\s{6,}(\S.*)$/u', $this->lines[$j], $f)) {
                    break;
                }
                $out[] = trim($f[1]);
            }
            break;
        }
        return $out;
    }
}
