<?php

namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\ReadsDocumentPages;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer den Antrag auf eine PRIVATHAFTPFLICHT-Versicherung
 * ("Haftpflicht gegen Dritte"), z.B. den AXA Privat-Schutz-Neuantrag. Diese
 * Antraege sind ueber alle Kunden hinweg gleich aufgebaut: ein
 * Antragsteller-Block (Anrede, Name, Anschrift, E-Mail, Geburtsdatum), ein
 * Laufzeiten-Block (Beginn, Ablauf), eine Beitragsuebersicht (Netto,
 * Versicherungsteuer, Gesamtbeitrag je Zahlweise) und der SEPA-Block mit der
 * Bankverbindung des Kunden.
 *
 * Gelesen werden Versicherer, Sparte (haftpflicht), Tarif, Beginn/Ablauf,
 * Beitrag + Zahlweise, Versicherungssumme sowie Person und Bankverbindung -
 * alles per fester Regel aus der Textebene (kein KI-Aufruf).
 *
 * Typ "versicherungsvertrag" (Neugeschaeft): das Dokument bleibt im
 * Dokumenten-Eingang mit Kunden-Vorschlag, damit der Mitarbeiter den Vertrag
 * anlegt. Alle Werte durchlaufen die harte Feldvalidierung; unsichere Felder
 * bleiben leer statt falsch.
 */
class PrivathaftpflichtAntragParser implements DocumentTemplateParser
{
    use ReadsDocumentPages;
    use ValidatesExtractedFields;

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        // Weiche Trennzeichen und Tabs normalisieren (die Antraege nutzen
        // Aufzaehlungs-Tabs im Fliesstext).
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $upper = mb_strtoupper($text);

        if ($this->looksLikeComparisonProtocol($text)) {
            return null;
        }
        // Nur Privathaftpflicht-Antraege/-Angebote (nicht die fertige Police
        // eines Kfz-Vertrags o.ae.).
        if (! str_contains($upper, 'PRIVATHAFTPFLICHT')
            || (! str_contains($upper, 'ANTRAG') && ! str_contains($upper, 'ANGEBOT'))) {
            return null;
        }

        $this->lines = preg_split('/\R/', $text) ?: [];

        $person = $this->parsePerson();
        $insurance = $this->parseInsurance($text);
        $bank = $this->parseBank();

        // Ohne belastbaren Namen der normalen Analyse/KI ueberlassen.
        if (($person['first_name'] ?? null) === null && ($person['last_name'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        $sum = $this->coverageSum($text);
        return [
            'type' => 'versicherungsvertrag',
            'confidence' => 76,
            'summary' => 'Privathaftpflicht-Antrag'
                .(isset($insurance['insurer']) ? ' ('.$insurance['insurer'].')' : '')
                .($name !== '' ? ' - '.$name : '')
                .(isset($insurance['tariff']) ? ' - '.$insurance['tariff'] : '')
                .($sum !== null ? ' - Versicherungssumme '.$sum : '')
                .(isset($insurance['premium_amount'])
                    ? ' - Beitrag '.number_format($insurance['premium_amount'], 2, ',', '.').' EUR'
                        .($insurance['premium_interval'] === 'monthly' ? '/Monat' : '')
                    : '')
                .' - Felder gratis aus dem Antrag gelesen (ohne KI).',
            'title' => 'Privathaftpflicht'.($name !== '' ? ' '.$name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $insurance,
                'kfz' => [],
                'gesundheit' => [],
                'bank' => $bank,
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /**
     * Antragsteller-Block: "Antragsteller  Herr   Geburtsdatum: 01.01.2002",
     * darunter Name, Strasse, "PLZ Ort" und "E-Mail: ...". Die rechte Spalte
     * (Geburtsdatum) wird sauber abgetrennt.
     *
     * @return array<string,mixed>
     */
    private function parsePerson(): array
    {
        $raw = [];

        // Geburtsdatum (steht in der rechten Spalte der Anrede-Zeile).
        if (preg_match('/Geburtsdatum:\s*(\d{2})\.(\d{2})\.(\d{4})/u', $this->text(), $m)) {
            $raw['birth_date'] = $m[3].'-'.$m[2].'-'.$m[1];
        }
        // E-Mail.
        if (preg_match('/E-Mail:\s*([\w.+\-]+@[\w.\-]+\.\w{2,})/u', $this->text(), $m)) {
            $raw['email'] = strtolower($m[1]);
        }

        // Anrede-Zeile finden ("Antragsteller  Herr  ..." oder nur "Herr").
        $start = null;
        foreach ($this->lines as $i => $line) {
            $cols = $this->columns($line);
            foreach ($cols as $c) {
                if (preg_match('/^(Herrn?|Frau)$/u', trim($c))) {
                    $raw['gender'] = mb_strtolower(trim($c)) === 'frau' ? 'female' : 'male';
                    $start = $i;
                    break 2;
                }
            }
        }
        if ($start === null) {
            return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
        }

        // Folgezeilen: Name, Strasse Hausnummer, PLZ Ort (linke Spalte).
        $block = [];
        for ($j = $start + 1; $j < count($this->lines) && count($block) < 4; $j++) {
            $c = $this->columns($this->lines[$j]);
            $val = trim($c[0] ?? '');
            if ($val === '' || str_starts_with($val, 'E-Mail')) {
                continue;
            }
            $block[] = $val;
        }

        // Name: erste Zeile aus 2+ Woertern (die Antraege schreiben ihn oft in
        // GROSSBUCHSTABEN -> "AHMAD ALJADDOU" wird zu "Ahmad Aljaddou").
        if (isset($block[0]) && preg_match('/^[\p{Lu}][\p{L}\-]+(?:\s+[\p{Lu}][\p{L}\-]+)+$/u', $block[0])) {
            $parts = preg_split('/\s+/', $this->normalizeName($block[0])) ?: [];
            $raw['last_name'] = array_pop($parts);
            $raw['first_name'] = implode(' ', $parts) ?: null;
        }
        // Strasse + Hausnummer.
        if (isset($block[1]) && preg_match('/^(.*\D)\s+(\d+(?:\s*[a-zA-Z])?)\s*$/u', $block[1], $s)) {
            $raw['street'] = $this->normalizeStreet(trim($s[1]));
            $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
        }
        // PLZ + Ort.
        foreach ($block as $line) {
            if (preg_match('/^(\d{5})\s+([A-ZÄÖÜ][\p{L}.\- ]+)$/u', $line, $z)) {
                $raw['zip'] = $z[1];
                $raw['city'] = trim($z[2]);
                break;
            }
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseInsurance(string $text): array
    {
        // Antrag/Angebot - noch keine Police: Stufe 'antrag'. Der spaeter
        // zugestellte Versicherungsschein ergaenzt denselben Vertrag
        // (Versicherungsscheinnummer, endgueltiger Beginn, Beitrag).
        $raw = ['sparte' => 'haftpflicht', 'document_stage' => Contract::STAGE_ANTRAG];

        // Versicherer ("AXA Versicherung AG").
        if (preg_match('/\b([A-ZÄÖÜ][\wÄÖÜäöüß.\-]*(?:\s+[A-ZÄÖÜ][\wÄÖÜäöüß.\-]*){0,3}\s+Versicherung(?:s)?\s+AG)\b/u', $text, $m)) {
            $raw['insurer'] = trim($m[1]);
        }

        // Tarifname ("Privathaftpflicht komfort"), oft mit Zusatzzeile
        // ("fuer eine Familie").
        if (preg_match('/Privathaftpflicht\s+(Privathaftpflicht\s+[\p{L}]+)/u', $text, $m)) {
            $raw['tariff'] = trim((string) preg_replace('/\s+/', ' ', $m[1]));
        }

        // Beginn: aus der Vertrags-/Laufzeitenzeile "Privathaftpflicht  <Datum>".
        if (preg_match('/^\s*Privathaftpflicht\s+(\d{2})\.(\d{2})\.(\d{4})\s*$/mu', $text, $m)) {
            $raw['start_date'] = $m[3].'-'.$m[2].'-'.$m[1];
        }
        // Ablauf ("Versicherungsablauf: 28.07.2027") - Verlaengerungs-Stichtag.
        if (preg_match('/Versicherungsablauf:?\s*(\d{2})\.(\d{2})\.(\d{4})/u', $text, $m)) {
            $raw['end_date'] = $m[3].'-'.$m[2].'-'.$m[1];
        }

        // Zahlweise ("Zahlweise  monatlich").
        $interval = null;
        if (preg_match('/Zahlweise\s+([a-zäöüA-ZÄÖÜ]+)/u', $text, $m)) {
            $interval = $this->interval($m[1]);
        }

        // Beitrag: der Gesamtbeitrag inkl. Versicherungsteuer (NICHT der
        // Nettobeitrag und nicht der Steueranteil).
        if (preg_match('/(?:Gesamtbeitrag|Beitrag)\s+(monatlich|viertelj[äa]hrlich|halbj[äa]hrlich|j[äa]hrlich)\s+inkl\.[^\d]*(\d{1,3}(?:\.\d{3})*,\d{2})\s*EUR/ui', $text, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[2]);
            $interval = $this->interval($m[1]) ?? $interval;
        } elseif (preg_match('/^\s*Summe\s+[\d.,]+\s+[\d.,]+\s+(\d{1,3}(?:\.\d{3})*,\d{2})\s*$/mu', $text, $m)) {
            // Fallback: letzte Spalte der Summenzeile der Beitragsuebersicht.
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
        }
        if (isset($raw['premium_amount']) && $interval !== null) {
            $raw['premium_interval'] = $interval;
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * SEPA-Block: IBAN/BIC des KUNDEN (die Antraege fuehren keine fremde
     * Bankverbindung; der Kontoinhaber ist der Antragsteller).
     *
     * @return array<string,mixed>
     */
    private function parseBank(): array
    {
        $raw = [];
        if (preg_match('/^\s*IBAN\s+(DE\d{2}(?:[ ]?\d){18})\b/mu', $this->text(), $m)) {
            $raw['iban'] = strtoupper((string) preg_replace('/\s+/', '', $m[1]));
        }
        if (preg_match('/^\s*BIC\s+([A-Z]{4}DE[A-Z0-9]{2,5})\b/mu', $this->text(), $m)) {
            $raw['bic'] = $m[1];
        }
        return $this->validatedBank(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** Versicherungssumme als Anzeigetext ("60.000.000 EUR") oder null. */
    private function coverageSum(string $text): ?string
    {
        if (preg_match('/([\d.]{5,})\s*EUR\s+Versicherungssumme/u', $text, $m)) {
            return $m[1].' EUR';
        }
        return null;
    }

    /** GROSSGESCHRIEBENE Namen normalisieren ("AHMAD ALJADDOU" -> "Ahmad Aljaddou"). */
    private function normalizeName(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ', $s));
        return $s === mb_strtoupper($s) ? mb_convert_case($s, MB_CASE_TITLE, 'UTF-8') : $s;
    }

    /** Strassennamen mit kleinem Anfangsbuchstaben korrigieren ("raabstr." -> "Raabstr."). */
    private function normalizeStreet(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ', $s));
        return $s === '' ? $s : mb_strtoupper(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }

    private function interval(string $german): ?string
    {
        return match (mb_strtolower(trim($german))) {
            'monatlich' => 'monthly',
            'vierteljährlich', 'vierteljahrlich' => 'quarterly',
            'halbjährlich', 'halbjahrlich' => 'semiannual',
            'jährlich', 'jahrlich' => 'yearly',
            default => null,
        };
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
