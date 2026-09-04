<?php

namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\ReadsDocumentPages;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer die Kundenschreiben der LichtBlick SE NACH dem Auftrag:
 * die VERTRAGSBESTAETIGUNG ("hiermit bestaetigen wir Ihren Vertrag") und die
 * ABSCHLAGSUEBERSICHT ("Ihre Abschlagsuebersicht"). Beide tragen denselben
 * Kopfblock mit den endgueltigen Nummern:
 *
 *   Kundennummer / Vertragsnummer / Zaehlernummer / Marktlokations-ID /
 *   Lieferstelle (Name + Anschrift) / Tarif
 *
 * dazu Lieferbeginn ("Ab dem 15.08.2026" bzw. "gueltig ab dem ...") und den
 * monatlichen BRUTTO-Abschlag. Das ist die zweite Haelfte des
 * Auftrag-zuerst-Systems: die Stufe ist 'vertrag', und die Vertragsanlage
 * findet den offenen ANTRAGS-Vertrag ueber Zaehlernummer/MaLo-ID und traegt
 * die echte Vertragsnummer nach (feldgenau in der Version History).
 *
 * Diese Schreiben kommen per Post und werden oft als HANDYFOTO hochgeladen -
 * die Extraktion arbeitet daher mit zeilenweisen "Beschriftung: Wert"-Regeln
 * auf dem OCR-Text statt mit Spaltengeometrie.
 *
 * Bewusst KEINE Bankdaten: die Kunden-IBAN ist im Schreiben maskiert
 * ("DE58***2795"), und die vollstaendige IBAN im Fusstext gehoert der
 * LichtBlick selbst (Commerzbank) - beides darf nie in die Kundenakte.
 */
class LichtblickVertragsbestaetigungParser implements DocumentTemplateParser
{
    use ReadsDocumentPages;
    use ValidatesExtractedFields;

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $upper = mb_strtoupper($text);

        if ($this->looksLikeComparisonProtocol($text)) {
            return null;
        }
        // Erkennung NUR auf der ERSTEN Seite (Briefkopf des Bestaetigungs-/
        // Abschlagsschreibens): die beiden Kopf-Beschriftungen MIT Doppelpunkt
        // und Wert stehen dort. Der AUFTRAG (eigener Parser, laeuft danach) hat
        // keine "Vertragsnummer:" - taucht das Wort ausnahmsweise im Rechtstext
        // einer Folgeseite auf, darf es die Bestaetigung nicht vortaeuschen.
        // Die WERTE liest der Parser weiterhin aus dem vollen Text.
        $head = $this->firstPage($text);
        if (! str_contains(mb_strtoupper($head), 'LICHTBLICK')
            || ! preg_match('/Kundennummer:\s*\d{5,12}/iu', $head)
            || ! preg_match('/Vertragsnummer:\s*\d{5,15}/iu', $head)) {
            return null;
        }

        $this->lines = preg_split('/\R/', $text) ?: [];

        $person = $this->parsePerson($text);
        $energie = $this->parseEnergy($text);
        $insurance = $this->parseInsurance($text, $upper, $energie);

        // Ohne die endgueltige Vertragsnummer traegt das Schreiben nichts bei.
        if (($insurance['contract_number'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        $sparteLabel = ($insurance['sparte'] ?? 'strom') === 'gas' ? 'Gas' : 'Strom';
        $isBestaetigung = mb_stripos($text, 'bestätigen wir Ihren Vertrag') !== false
            || mb_stripos($text, 'bestaetigen wir Ihren Vertrag') !== false;

        return [
            'type' => 'energieauftrag',
            'confidence' => 78,
            'summary' => 'LichtBlick '.$sparteLabel
                .($isBestaetigung ? '-Vertragsbestaetigung' : '-Abschlagsuebersicht')
                .($name !== '' ? ' - '.$name : '')
                .' - Vertrag '.$insurance['contract_number']
                .(isset($energie['customer_number']) ? ' - Kundennr. '.$energie['customer_number'] : '')
                .(isset($energie['tariff']) ? ' - '.$energie['tariff'] : '')
                .(isset($insurance['start_date']) ? ' - Lieferbeginn '.$this->displayDate($insurance['start_date']) : '')
                .(isset($insurance['premium_amount'])
                    ? ' - Abschlag '.number_format($insurance['premium_amount'], 2, ',', '.').' EUR/Monat' : '')
                .' - Felder gratis aus dem Schreiben gelesen (ohne KI).',
            'title' => 'LichtBlick '.($isBestaetigung ? 'Vertragsbestaetigung' : 'Abschlagsuebersicht')
                .($name !== '' ? ' '.$name : ''),
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
     * Kunde aus der Anrede ("Hallo Mashhour Altahan,") - sie steht in beiden
     * Schreiben und ist im Handyfoto-OCR zuverlaessiger als die Adressbloecke.
     * Anschrift: Strasse+Hausnummer mit "PLZ Ort" in der Folgezeile, aber NUR
     * direkt unter dem Kundennamen bzw. der "Lieferstelle:"-Zeile - so kann
     * die Anschrift der LichtBlick selbst (Fusszeile "Klostertor 1,
     * 20097 Hamburg") nie in die Kundenakte geraten.
     *
     * @return array<string,mixed>
     */
    private function parsePerson(string $text): array
    {
        $raw = [];

        $name = null;
        if (preg_match('/Hallo\s+([\p{L}][\p{L} .\-]{2,60}?)\s*,/u', $text, $m)) {
            $name = trim($m[1]);
        } elseif (preg_match('/Lieferstelle:\s*([\p{L}][\p{L} .\-]{2,60})/u', $text, $m)) {
            $name = trim($m[1]);
        }
        if ($name !== null && preg_match('/^\p{L}/u', $name)) {
            $parts = preg_split('/\s+/', $name) ?: [];
            if (count($parts) >= 2) {
                $raw['last_name'] = array_pop($parts);
                $raw['first_name'] = implode(' ', $parts);
            }
        }

        foreach ($this->lines as $i => $line) {
            $prev = trim($this->lines[$i - 1] ?? '');
            $anchored = ($name !== null && $prev !== '' && mb_stripos($prev, $name) !== false)
                || mb_stripos($prev, 'Lieferstelle') !== false;
            if (! $anchored) {
                continue;
            }
            if (preg_match('/^\s*(.{2,60}?\p{L}\.?)\s+(\d+\s*[a-zA-Z]?)\s*$/u', $line, $s)
                && preg_match('/\p{L}{3,}/u', $s[1])
                && preg_match('/^\s*(\d{5})\s+([\p{L}][\p{L}.\- ]+?)\s*$/u', (string) ($this->lines[$i + 1] ?? ''), $z)) {
                $raw['street'] = trim($s[1]);
                $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
                $raw['zip'] = $z[1];
                $raw['city'] = trim($z[2]);
                break;
            }
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseEnergy(string $text): array
    {
        $raw = [];

        if (preg_match('/Kundennummer:\s*(\d{5,12})/iu', $text, $m)) {
            $raw['customer_number'] = $m[1];
        }
        if (preg_match('/Z(?:ä|ae)hlernummer:\s*([A-Z0-9]{5,20})\b/iu', $text, $m)) {
            $raw['meter_number'] = strtoupper($m[1]);
        }
        if (preg_match('/Marktlokations-?ID:\s*(\d{11})\b/iu', $text, $m)) {
            $raw['malo_id'] = $m[1];
        }
        // Tarif aus dem Kopf ("Tarif: ÖkoStrom 24") - bis zum Zeilenende bzw.
        // zum naechsten grossen Abstand (OCR haengt sonst Nachbarspalten an).
        if (preg_match('/Tarif:\s*([^\r\n]{2,60})/u', $text, $m)) {
            $tarif = trim((string) preg_replace('/\s{2,}.*$/u', '', $m[1]));
            if (preg_match('/\p{L}{3,}/u', $tarif)) {
                $raw['tariff'] = $tarif;
            }
        }
        // Jahresverbrauch ("basierend auf ... Jahresverbrauch von 1.800,00 kWh").
        if (preg_match('/Jahresverbrauch von\s*([\d.]+)(?:,\d+)?\s*kWh/iu', $text, $m)) {
            $raw['consumption_kwh'] = (int) str_replace('.', '', $m[1]);
        }

        return $this->validatedEnergy(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * @param array<string,mixed> $energie
     * @return array<string,mixed>
     */
    private function parseInsurance(string $text, string $upper, array $energie): array
    {
        $raw = [
            'insurer' => 'LichtBlick',
            'sparte' => str_contains($upper, 'ÖKOGAS') || str_contains($upper, 'OEKOGAS') ? 'gas' : 'strom',
            // Bestaetigung = der Vertrag ist zustande gekommen.
            'document_stage' => Contract::STAGE_VERTRAG,
            'tariff' => $energie['tariff'] ?? null,
        ];

        if (preg_match('/Vertragsnummer:\s*(\d{5,15})/iu', $text, $m)) {
            $raw['contract_number'] = $m[1];
        }

        // Lieferbeginn: "Ab dem 15.08.2026 werden Sie ... versorgt" bzw.
        // "gültig ab dem 15.08.2026".
        if (preg_match('/\bab dem\s*(\d{2})\.(\d{2})\.(\d{4})/iu', $text, $m)) {
            $raw['start_date'] = $m[3].'-'.$m[2].'-'.$m[1];
        }

        // Monatlicher BRUTTO-Abschlag: "Ihr kuenftiger monatlicher Abschlag
        // betraegt 65,00 EUR" (Bestaetigung) bzw. die Bruttospalte der
        // Abschlagszahlung (Uebersicht: netto / MwSt / BRUTTO - letzter Wert).
        $abschlag = null;
        if (preg_match('/Abschlag betr(?:ä|ae)gt\s*([\d.]+,\d{2})\s*€/iu', $text, $m)) {
            $abschlag = $m[1];
        } else {
            foreach ($this->lines as $line) {
                // Nur die TABELLENZEILE ("Abschlagszahlung 54,62 € ... 65,00 €")
                // - nicht der Fliesstext "Uebersicht Ihrer Abschlagszahlungen".
                if (! preg_match('/^\s*Abschlagszahlung\b/u', $line)) {
                    continue;
                }
                if (preg_match_all('/(\d{1,4}(?:\.\d{3})*,\d{2})\s*€/u', $line, $mm) && $mm[1] !== []) {
                    $abschlag = end($mm[1]);
                    break;
                }
            }
        }
        if ($abschlag !== null) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $abschlag);
            $raw['premium_interval'] = 'monthly';
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    private function displayDate(string $iso): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) ? $m[3].'.'.$m[2].'.'.$m[1] : $iso;
    }
}
