<?php

namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer die Entgelt-/Gehaltsabrechnung (Lohnabrechnung). Diese
 * Abrechnungen tragen die verlaesslichsten Personendaten des Arbeitnehmers
 * (Name, Anschrift, Geburtsdatum) - ideal, um das Dokument dem richtigen
 * Kunden zuzuordnen - sowie weitere wertvolle Angaben: die Krankenkasse, das
 * Ueberweisungskonto (IBAN des Arbeitnehmers) und die Einkommenshoehe
 * (Brutto/Netto), die der Betrieb bisher von Hand nachtragen musste.
 *
 * Das Layout ist zweispaltig: links der Arbeitnehmer-Anschriftenblock,
 * rechts die Steuer-/SV-Merkmale (Geburtsdatum, Krankenkasse ...). Alle Werte
 * durchlaufen die harte Feldvalidierung; unsichere Felder bleiben leer statt
 * falsch. Der Arbeitgeber und die Einkommenshoehe stehen (mangels eigener
 * Felder) in der Zusammenfassung.
 */
class GehaltsabrechnungParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $upper = mb_strtoupper($text);
        if (! str_contains($upper, 'ENTGELTABRECHNUNG') && ! str_contains($upper, 'GEHALTSABRECHNUNG')
            && ! str_contains($upper, 'LOHNABRECHNUNG') && ! str_contains($upper, 'ENTGELTBESCHEINIGUNG')) {
            return null;
        }

        $this->lines = preg_split('/\R/', $text) ?: [];

        $person = $this->parsePerson();
        if (($person['first_name'] ?? null) === null && ($person['last_name'] ?? null) === null) {
            return null; // ohne Namen der normalen Analyse/KI ueberlassen
        }

        // Krankenkasse (rechte Merkmalsspalte).
        $health = [];
        $kk = $this->labelValue('Krankenkasse');
        if ($kk !== null && mb_strlen($kk) >= 2 && ! preg_match('/beitrag/i', $kk)) {
            $health['health_insurance_company'] = $kk;
            $health['health_insurance_type'] = 'gesetzlich';
        }
        $health = $this->validatedHealth($health);

        // Ueberweisungskonto = IBAN des Arbeitnehmers.
        $bank = [];
        if (preg_match('/IBAN\s+(DE\d{2}(?:\s?\d){18})\b/u', $this->text(), $m)) {
            $bank['iban'] = strtoupper((string) preg_replace('/\s+/', '', $m[1]));
            $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
            if ($name !== '') {
                $bank['account_holder'] = $name;
            }
        }
        $bank = $this->validatedBank($bank);

        $employer = $this->employer();
        $brutto = $this->amountAfter('Gesamtbrutto');
        $netto = $this->amountAfter('Gesamtnetto');
        $month = $this->periodLabel();

        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        return [
            'type' => 'gehaltsabrechnung',
            'confidence' => 72,
            'summary' => 'Entgeltabrechnung'
                .($month !== null ? ' '.$month : '')
                .($name !== '' ? ' - '.$name : '')
                .($employer !== null ? ' - Arbeitgeber '.$employer : '')
                .($brutto !== null ? ' - Brutto '.number_format($brutto, 2, ',', '.').' EUR' : '')
                .($netto !== null ? ' / Netto '.number_format($netto, 2, ',', '.').' EUR' : '')
                .(isset($health['health_insurance_company']) ? ' - Krankenkasse '.$health['health_insurance_company'] : '')
                .' - Felder gratis aus der Abrechnung gelesen (ohne KI).',
            'title' => 'Entgeltabrechnung'.($name !== '' ? ' '.$name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => [],
                'kfz' => [],
                'gesundheit' => $health,
                'bank' => $bank,
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /** Arbeitnehmer-Anschrift aus dem linken Block (nach "Herrn/Frau"). @return array<string,mixed> */
    private function parsePerson(): array
    {
        $raw = [];
        foreach ($this->lines as $i => $line) {
            $cols = $this->columns($line);
            if ($cols === [] || ! preg_match('/^(Herrn|Herr|Frau)$/u', $cols[0])) {
                continue;
            }
            $raw['gender'] = mb_strtolower($cols[0]) === 'frau' ? 'female' : 'male';
            $block = [];
            for ($j = $i + 1; $j < count($this->lines) && count($block) < 3; $j++) {
                $c = $this->columns($this->lines[$j]);
                if ($c !== []) {
                    $block[] = $c[0];
                }
            }
            if (count($block) >= 3
                && preg_match('/^[A-ZÄÖÜ][\p{L}\-]+(?:\s+[A-ZÄÖÜ][\p{L}\-]+)+$/u', $block[0])
                && preg_match('/^(\d{5})\s+(.+)$/', $block[2], $z)) {
                $parts = preg_split('/\s+/', $block[0]) ?: [];
                $raw['last_name'] = array_pop($parts);
                $raw['first_name'] = implode(' ', $parts) ?: null;
                if (preg_match('/^(.*\D)\s*(\d+(?:\s*[a-zA-Z])?)\s*$/u', $block[1], $s)) {
                    $raw['street'] = trim($s[1]);
                    $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
                } else {
                    $raw['street'] = $block[1];
                }
                $raw['zip'] = $z[1];
                $raw['city'] = trim($z[2]);
            }
            break;
        }

        // Geburtsdatum aus der rechten Merkmalsspalte.
        $birth = $this->labelValue('Geburtsdatum');
        if ($birth !== null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $birth, $m)) {
            $raw['birth_date'] = $m[3].'-'.$m[2].'-'.$m[1];
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** Arbeitgeber: erste Zeile mit Rechtsform (GmbH/AG/...) im Kopf. */
    private function employer(): ?string
    {
        foreach (array_slice($this->lines, 0, 20) as $line) {
            $col = $this->columns($line)[0] ?? '';
            if (preg_match('/^[A-ZÄÖÜ][\p{L}0-9 .,&\-]{2,60}\s(GmbH|AG|KG|GbR|mbH|e\.K\.|OHG|SE|UG)\b/u', trim($col), $m)) {
                return trim($m[0]);
            }
        }
        return null;
    }

    /** Betrag nach einem Label ("Gesamtbrutto ... 2.512,00") - der erste Wert. */
    private function amountAfter(string $label): ?float
    {
        if (preg_match('/'.preg_quote($label, '/').'\s+([\d.]+,\d{2})/u', $this->text(), $m)) {
            return (float) str_replace(['.', ','], ['', '.'], $m[1]);
        }
        return null;
    }

    /** Abrechnungszeitraum ("Mai 2026") aus der Kopfzeile. */
    private function periodLabel(): ?string
    {
        if (preg_match('/\b(Januar|Februar|März|Maerz|April|Mai|Juni|Juli|August|September|Oktober|November|Dezember)\s+(\d{4})\b/u', $this->text(), $m)) {
            return $m[1].' '.$m[2];
        }
        return null;
    }

    /** Wert nach "Label" bis zur naechsten Spalte/Zeilenende (rechte Merkmalsspalte). */
    private function labelValue(string $label): ?string
    {
        $pattern = '/(?<![\p{L}\-])'.preg_quote($label, '/').'\s{2,}(\S.*?)(?:\s{2,}|$)/mu';
        return preg_match($pattern, $this->text(), $m) ? trim($m[1]) : null;
    }

    /** @return list<string> */
    private function columns(string $line): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\s{2,}/', trim($line)) ?: []),
            fn ($c) => $c !== ''
        ));
    }

    private function text(): string
    {
        return implode("\n", $this->lines);
    }
}
