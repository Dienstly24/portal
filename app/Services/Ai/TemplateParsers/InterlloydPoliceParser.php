<?php

namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\ReadsDocumentPages;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Parser fuer den Versicherungsschein der Interlloyd Versicherungs-AG
 * (ARAG-Gruppe), z.B. die Betriebshaftpflicht "BHV Business Secure" eines
 * Paketdienstes. Aufbau (Spaltenlayout):
 *
 *   Kopf   : "Versicherungsschein" + Produkt rechts ("BHV Business Secure"),
 *            "Nr. : 000782243", "Kunden-Nr. : ..."
 *   Kunde  : LINKE Spalte unter "Versicherungsnehmer" (Anrede, Name, Firma,
 *            Strasse, PLZ Ort) - RECHTS steht der Makler (Fonds Finanz),
 *            der NIE Kundendaten wird.
 *   Vertrag: "Versicherungsbeginn: 19.06.2026  Ablauf: 1.01.2028" (Tag auch
 *            einstellig!), "Zahlungsweise : vierteljaehrlich",
 *            "Praemie gemaess Zahlungsweise : 57,18 EUR" (= der tatsaechlich
 *            wiederkehrende BRUTTO-Betrag inkl. Steuer und Ratenzuschlag).
 *
 * Der Schein ist der ECHTE Vertrag (Stufe 'vertrag') mit gueltiger
 * Vertragsnummer; die Kunden-Nr. des Versicherers steht in der
 * Zusammenfassung. Die Sparte kommt aus dem Produktnamen (BHV =
 * Betriebshaftpflicht) - unbekannte Produkte lassen die Sparte leer,
 * lieber waehlt der Mitarbeiter als eine falsche Zuordnung.
 */
class InterlloydPoliceParser implements DocumentTemplateParser
{
    use ReadsDocumentPages;
    use ValidatesExtractedFields;

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
        if (! str_contains($upper, 'INTERLLOYD') || ! str_contains($upper, 'VERSICHERUNGSSCHEIN')) {
            return null;
        }

        $this->lines = preg_split('/\R/', $this->text) ?: [];

        $insurance = $this->parseInsurance();
        if (($insurance['contract_number'] ?? null) === null) {
            return null; // ohne Vertragsnummer lieber der normalen Analyse ueberlassen
        }
        $person = $this->parsePerson();

        $who = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        if ($who === '') {
            $who = (string) ($person['company_name'] ?? '');
        }
        $sparte = $insurance['sparte'] ?? null;

        return [
            'type' => 'versicherungspolice',
            'confidence' => 78,
            'summary' => 'Interlloyd Versicherungsschein'
                .(isset($insurance['tariff']) ? ' - '.$insurance['tariff'] : '')
                .($sparte !== null ? ' ('.(Contract::TYPES[$sparte]['label'] ?? $sparte).')' : '')
                .($who !== '' ? ' - '.$who : '')
                .' - Vertrag '.$insurance['contract_number']
                .$this->extras()
                .' Felder gratis aus dem Versicherungsschein gelesen (ohne KI).',
            'title' => 'Interlloyd '.($insurance['tariff'] ?? 'Versicherungsschein')
                .($who !== '' ? ' - '.$who : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $insurance,
                'kfz' => [],
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

        // Anker: Zeile, deren LINKE Spalte "Versicherungsnehmer" ist (rechts
        // daneben steht der Produktname). Danach die linken Zellen lesen -
        // rechts steht der Makler und bleibt draussen.
        foreach ($this->lines as $i => $line) {
            if ($this->leftCell($line) !== 'Versicherungsnehmer') {
                continue;
            }
            $end = min(count($this->lines), $i + 10);
            for ($j = $i + 1; $j < $end; $j++) {
                $left = $this->leftCell($this->lines[$j]);
                if ($left === '') {
                    continue;
                }
                if (preg_match('/^(Herrn?|Frau)$/u', $left, $m)) {
                    $raw['gender'] = mb_strtolower($m[1]) === 'frau' ? 'female' : 'male';
                    continue;
                }
                if (preg_match('/^(\d{5})\s+(\p{L}.*)$/u', $left, $z)) {
                    $raw['zip'] = $z[1];
                    $raw['city'] = trim($z[2]);
                    break; // PLZ+Ort schliessen den Block ab
                }
                if (! isset($raw['street'])
                    && preg_match('/^(.*\p{L}\.?)\s+(\d+\s*[a-zA-Z]?)$/u', $left, $s)
                    && preg_match('/\p{L}{3,}/u', $s[1])
                    && ! $this->looksLikeCompany($left)) {
                    $raw['street'] = trim($s[1]);
                    $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
                    continue;
                }
                if (! isset($raw['company_name']) && $this->looksLikeCompany($left)) {
                    $raw['company_name'] = $left;
                    continue;
                }
                // Personenname (2-4 Grosswoerter): letztes Wort = Nachname.
                if (! isset($raw['last_name'])
                    && preg_match('/^[A-ZÄÖÜ][\p{L}\-]+(?:\s+[A-ZÄÖÜ][\p{L}\-]+){1,3}$/u', $left)) {
                    $parts = preg_split('/\s+/', $left) ?: [];
                    $raw['last_name'] = array_pop($parts);
                    $raw['first_name'] = implode(' ', $parts) ?: null;
                }
            }
            break;
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseInsurance(): array
    {
        $raw = [
            'insurer' => 'Interlloyd Versicherungs-AG',
            'document_stage' => Contract::STAGE_VERTRAG,
        ];

        // Vertragsnummer: "Nr. : 000782243" im Kopf bzw. "Anlage zum
        // Versicherungsschein Nr. 000782243". Der Lookbehind schliesst die
        // "Kunden-Nr." aus - sie ist NICHT die Vertragsnummer.
        if (preg_match('/(?<![-\p{L}])Nr\.\s*:\s*(\d{6,12})\b/u', $this->text, $m)
            || preg_match('/Versicherungsschein Nr\.\s*(\d{6,12})\b/u', $this->text, $m)) {
            $raw['contract_number'] = $m[1];
        }

        // Produkt rechts neben "Versicherungsnehmer" bzw. im Deckungsumfang.
        $produkt = null;
        if (preg_match('/^\s*Versicherungsnehmer\s{2,}(\S[^\n]*?)\s*$/mu', $this->text, $m)
            || preg_match('/Deckungsumfang\s*-\s*(\S[^\n]*?)\s*$/mu', $this->text, $m)) {
            $produkt = trim($m[1]);
        }
        if ($produkt !== null && $produkt !== '') {
            $raw['tariff'] = $produkt;
            $raw['sparte'] = $this->mapSparte($produkt);
        }

        // Beginn/Ablauf in EINER Zeile - der Tag darf einstellig sein
        // ("Ablauf: 1.01.2028").
        if (preg_match('/Versicherungsbeginn:\s*(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $this->text, $m)) {
            $raw['start_date'] = sprintf('%s-%02d-%02d', $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/Ablauf:\s*(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $this->text, $m)) {
            $raw['end_date'] = sprintf('%s-%02d-%02d', $m[3], (int) $m[2], (int) $m[1]);
        }

        // Beitrag: "Praemie gemaess Zahlungsweise" ist der tatsaechlich
        // wiederkehrende BRUTTO-Betrag (inkl. Steuer + Ratenzuschlag);
        // ersatzweise die Jahrespraemie mit Versicherungsteuer.
        $interval = null;
        if (preg_match('/^\s*Zahlungsweise\s*:\s*([\p{L}]+)/mu', $this->text, $m)) {
            $interval = $this->interval($m[1]);
        }
        if (preg_match('/Prämie gemäß Zahlungsweise\s*:\s*([\d.]+,\d{2})/u', $this->text, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = $interval ?? 'yearly';
        } elseif (preg_match('/Jahresprämie mit Vers\.-Steuer\s*:\s*([\d.]+,\d{2})/u', $this->text, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = 'yearly';
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** Zusatzangaben fuer die Zusammenfassung. */
    private function extras(): string
    {
        $out = '.';
        if (preg_match('/Kunden-Nr\.\s*:?\s*(\d{6,12})\b/u', $this->text, $m)) {
            $out .= ' Kunden-Nr. beim Versicherer: '.$m[1].'.';
        }
        if (preg_match('/Jahresprämie mit Vers\.-Steuer\s*:\s*([\d.]+,\d{2})/u', $this->text, $m)) {
            $out .= ' Jahrespraemie mit Steuer: '.$m[1].' EUR.';
        }
        if (preg_match('/Betriebsart:\s*([^\r\n]+)/u', $this->text, $m)) {
            $out .= ' Betriebsart: '.trim($m[1]).'.';
        }
        if (preg_match('/Risikoort:\s*([^\r\n]+)/u', $this->text, $m)) {
            $out .= ' Risikoort: '.trim($m[1]).'.';
        }
        if (preg_match('/pauschal Pers-,\s*Sach-,\s*Vermögens\s+([\d.]+)\s+EUR/u', $this->text, $m)) {
            $out .= ' Deckungssumme pauschal '.$m[1].' EUR.';
        }
        return $out;
    }

    /** Sparte aus dem Produktnamen - unbekannt bleibt bewusst leer. */
    private function mapSparte(string $produkt): ?string
    {
        $p = mb_strtolower($produkt);
        return match (true) {
            str_contains($p, 'bhv') || str_contains($p, 'betriebshaftpflicht') => 'betriebshaftpflicht',
            str_contains($p, 'verkehrshaftung')
                || str_contains($p, 'frachtführer') || str_contains($p, 'frachtfuehrer') => 'frachtfuehrerhaftpflicht',
            str_contains($p, 'phv') || str_contains($p, 'privathaftpflicht') => 'haftpflicht',
            str_contains($p, 'hausrat') => 'hausrat',
            str_contains($p, 'unfall') => 'unfall',
            default => null,
        };
    }

    private function looksLikeCompany(string $name): bool
    {
        return (bool) preg_match(
            '/(GmbH|mbH|\bUG\b|\bOHG\b|\bKG\b|\bGbR\b|\bAG\b|\be\.?\s?K\b|Einzelunt|Einzelfirma|Gewerbe|Unternehmen|Betrieb|Spedition|Logistik|Transport)/ui',
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

    /** Linke Spalte einer Zeile (bis zum ersten grossen Spaltenabstand). */
    private function leftCell(string $line): string
    {
        return trim((string) (preg_split('/\s{2,}/', trim($line))[0] ?? ''));
    }
}
