<?php

namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\ReadsDocumentPages;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Parser fuer den Versicherungsschein der Dialog Versicherung AG zur
 * FRACHTFUEHRERHAFTUNGSVERSICHERUNG (Verkehrshaftungsschutz) - die Police
 * zur gewerblichen Sparte frachtfuehrerhaftpflicht. Aufbau:
 *
 *   Kopf   : "Versicherungsschein" + "Verkehrshaftungsschutz-Nr. 2-GK-..."
 *   Kunde  : Adressblock mit "Firma" / Firmenname / Personenname / Strasse /
 *            PLZ Ort (LINKE Spalte - rechts steht der Service-Kontakt der
 *            Versicherung, der NIE Kundendaten wird).
 *   Vertrag: "Beginn des Vertrags: 19.06.2026 12.00 Uhr",
 *            "Ablauf des Vertrags: 01.01.2028",
 *            "Jahresbeitrag 223,01 EUR" (BRUTTO inkl. Steuer + Ratenzuschlag;
 *            die Zeile "Jahresbeitrag netto" ist NICHT der Beitrag).
 *
 * Das versicherte FAHRZEUG (Kennzeichen/Fahrzeugart/Gewicht) steht NUR in
 * der Zusammenfassung, nie in data.kfz: dasselbe Fahrzeug hat daneben eine
 * eigene Kfz-Versicherung - Fahrzeug-Identitaet am Haftpflicht-Vertrag
 * wuerde beide Vertraege vermischen (gleiche Regel wie beim
 * Deckungsauftrag). Stufe 'vertrag' mit echter Vertragsnummer.
 */
class DialogFrachtfuehrerPoliceParser implements DocumentTemplateParser
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
        if (! str_contains($upper, 'DIALOG VERSICHERUNG')
            || (! str_contains($upper, 'VERKEHRSHAFTUNGSSCHUTZ')
                && ! str_contains($upper, 'FRACHTFÜHRERHAFTUNGSVERSICHERUNG'))) {
            return null;
        }

        $this->lines = preg_split('/\R/', $this->text) ?: [];

        $insurance = $this->parseInsurance();
        if (($insurance['contract_number'] ?? null) === null) {
            return null;
        }
        $person = $this->parsePerson();

        $who = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        if ($who === '') {
            $who = (string) ($person['company_name'] ?? '');
        }

        return [
            'type' => 'versicherungspolice',
            'confidence' => 78,
            'summary' => 'Dialog Versicherungsschein - Frachtfuehrerhaftpflicht (Verkehrshaftungsschutz)'
                .($who !== '' ? ' - '.$who : '')
                .' - Vertrag '.$insurance['contract_number']
                .$this->extras()
                .' Felder gratis aus dem Versicherungsschein gelesen (ohne KI).',
            'title' => 'Dialog Frachtfuehrerhaftpflicht'.($who !== '' ? ' - '.$who : ''),
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

        // Anker: Zeile, deren linke Spalte "Firma" bzw. eine Anrede ist; der
        // Block endet mit "PLZ Ort". Rechts steht der Service-Kontakt der
        // Versicherung (Ansprechpartner, Telefon, E-Mail) - nur linke Zellen.
        foreach ($this->lines as $i => $line) {
            $anker = $this->leftCell($line);
            if (! preg_match('/^(Firma|Herrn?|Frau)$/u', $anker, $a)) {
                continue;
            }
            if ($a[1] !== 'Firma') {
                $raw['gender'] = mb_strtolower($a[1]) === 'frau' ? 'female' : 'male';
            }
            $end = min(count($this->lines), $i + 8);
            for ($j = $i + 1; $j < $end; $j++) {
                $left = $this->leftCell($this->lines[$j]);
                if ($left === '') {
                    continue;
                }
                if (preg_match('/^(\d{5})\s+(\p{L}.*)$/u', $left, $z)) {
                    $raw['zip'] = $z[1];
                    $raw['city'] = trim($z[2]);
                    break;
                }
                if (! isset($raw['street'])
                    && preg_match('/^(.*\p{L}\.?)\s+(\d+\s*[a-zA-Z]?)$/u', $left, $s)
                    && preg_match('/\p{L}{3,}/u', $s[1])) {
                    $raw['street'] = trim($s[1]);
                    $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
                    continue;
                }
                // Mehrwortiger Name = Person (letztes Wort Nachname) ...
                if (! isset($raw['last_name'])
                    && preg_match('/^[A-ZÄÖÜ][\p{L}\-]+(?:\s+[A-ZÄÖÜ][\p{L}\-]+){1,3}$/u', $left)) {
                    $parts = preg_split('/\s+/', $left) ?: [];
                    $raw['last_name'] = array_pop($parts);
                    $raw['first_name'] = implode(' ', $parts) ?: null;
                    continue;
                }
                // ... EIN Wort direkt unter "Firma" = der Firmenname.
                if (! isset($raw['company_name']) && $anker === 'Firma'
                    && preg_match('/^[\p{L}\d&.\- ]{2,60}$/u', $left)) {
                    $raw['company_name'] = $left;
                }
            }
            break;
        }

        // Absicherung: "Versicherungsnehmer: RANKO" bestaetigt den Firmennamen.
        if (! isset($raw['company_name'])
            && preg_match('/Versicherungsnehmer:\s*(\S[^\r\n]*?)\s*$/mu', $this->text, $m)) {
            $vn = trim($m[1]);
            $full = trim(($raw['first_name'] ?? '').' '.($raw['last_name'] ?? ''));
            if ($vn !== '' && mb_strtolower($vn) !== mb_strtolower($full)) {
                $raw['company_name'] = $vn;
            }
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseInsurance(): array
    {
        $raw = [
            'insurer' => 'Dialog Versicherung AG',
            'sparte' => 'frachtfuehrerhaftpflicht',
            'document_stage' => Contract::STAGE_VERTRAG,
        ];

        if (preg_match('/Verkehrshaftungsschutz-Nr\.\s*([0-9A-Z][0-9A-Z.\-\/]{5,30})/iu', $this->text, $m)
            || preg_match('/der Versicherung Nr\.\s*([0-9A-Z][0-9A-Z.\-\/]{5,30})/iu', $this->text, $m)) {
            $raw['contract_number'] = rtrim($m[1], '.,');
        }

        if (preg_match('/Beginn des Vertrags:\s*(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $this->text, $m)) {
            $raw['start_date'] = sprintf('%s-%02d-%02d', $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/Ablauf des Vertrags:\s*(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $this->text, $m)) {
            $raw['end_date'] = sprintf('%s-%02d-%02d', $m[3], (int) $m[2], (int) $m[1]);
        }

        // Jahresbeitrag BRUTTO: die Zeile "Jahresbeitrag  223,01 EUR" (die
        // Netto-Zeile traegt das Wort "netto" und faellt am Regex vorbei).
        // Die Zahlweise (vierteljaehrlich) steht nur als Text - der Betrag
        // wird NIE selbst geteilt (Rundung waere geraten), sie steht in der
        // Zusammenfassung.
        if (preg_match('/^\s*Jahresbeitrag\s+([\d.]+,\d{2})\s*EUR\s*$/mu', $this->text, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = 'yearly';
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** Zusatzangaben fuer die Zusammenfassung. */
    private function extras(): string
    {
        $out = '.';
        if (preg_match('/Selbstbehalt[\s\S]{0,200}?beträgt:\s*([\d.]+)\s*EUR/u', $this->text, $m)) {
            $out .= ' Selbstbehalt '.$m[1].' EUR.';
        }
        if (preg_match('/vierteljährlich im Voraus/u', $this->text)) {
            $out .= ' Zahlbar vierteljaehrlich im Voraus.';
        }
        // Versichertes Fahrzeug NUR zur Info (kein Kfz-Vertrag!).
        if (preg_match('/Amtl\.\s*Kennzeichen:\s*(\S[^\r\n]*?)\s*$/mu', $this->text, $m)) {
            $fahrzeug = trim($m[1]);
            $details = [];
            if (preg_match('/Fahrzeugart:\s*(\S[^\r\n]*?)\s*$/mu', $this->text, $f)) {
                $details[] = trim($f[1]);
            }
            if (preg_match('/Zulässiges Gewicht:\s*(\S[^\r\n]*?)\s*$/mu', $this->text, $g)) {
                $details[] = trim($g[1]);
            }
            $out .= ' Versichertes Fahrzeug: '.$fahrzeug
                .($details !== [] ? ' ('.implode(', ', $details).')' : '').'.';
        }
        // Spaltenlayout: "Ausfertigungsgrund:" und der Wert ("Neuantrag")
        // stehen in getrennten Zeilen der rechten Spalte.
        if (str_contains($this->text, 'Ausfertigungsgrund')
            && preg_match('/\b(Neuantrag|Ersatzausfertigung|Nachtrag)\b/u', $this->text, $m)) {
            $out .= ' Ausfertigungsgrund: '.$m[1].'.';
        }
        return $out;
    }

    /** Linke Spalte einer Zeile (bis zum ersten grossen Spaltenabstand). */
    private function leftCell(string $line): string
    {
        return trim((string) (preg_split('/\s{2,}/', trim($line))[0] ?? ''));
    }
}
