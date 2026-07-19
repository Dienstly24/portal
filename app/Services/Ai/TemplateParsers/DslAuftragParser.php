<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Parser fuer die Auftragsbestaetigung eines DSL-/Internet-Anschlusses (z.B.
 * die CHECK24-Uebersicht "Ihr DSL Anschluss"). Der Auftrag traegt bereits alle
 * Kern-Daten, auf die sich der Betrieb stuetzt:
 *
 *   Kundendaten : Name, Anschrift, Handynummer, E-Mail, Geburtsdatum
 *   Tarif       : Anbieter, Tarif, Download/Upload, Mindestlaufzeit,
 *                 Kuendigungsfrist, Durchschnittspreis pro Monat
 *   Auftrag     : Auftragsnummer
 *
 * Ergebnis: Typ 'internetvertrag' (Sparte internet). Der spaeter zugestellte
 * Provider-Vertrag mit der finalen Vertragsnummer laesst sich ergaenzend
 * hochladen. Die IBAN ist im Auftrag ueblicherweise maskiert (DE46****2425)
 * und wird bewusst NICHT als Bankverbindung uebernommen.
 */
class DslAuftragParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    public function parse(string $text): ?array
    {
        $upper = mb_strtoupper($text);
        // DSL-/Internet-Auftrag: Anbieter + Mindestlaufzeit + ein klarer
        // Internet-Marker (MBit/DSL/Anschluss). Grenzt gegen Versicherungs-/
        // Energie-Dokumente ab.
        $hasInternetMarker = str_contains($upper, 'MBIT') || str_contains($upper, 'DSL')
            || str_contains($upper, 'ANSCHLUSS') || str_contains($upper, 'MAGENTA')
            || str_contains($upper, 'INTERNET');
        if (!str_contains($upper, 'ANBIETER') || !str_contains($upper, 'MINDESTLAUFZEIT') || !$hasInternetMarker) {
            return null;
        }

        $lines = array_map('rtrim', preg_split('/\R/', $text) ?: []);

        $person = $this->parsePerson($text, $lines);
        $contract = $this->parseContract($text);

        // Ohne belastbaren Kern (Anbieter/Tarif oder Name) der KI ueberlassen.
        if ($contract === [] && $person === []) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        return [
            'type' => 'internetvertrag',
            'confidence' => 70,
            'summary' => 'Internet-/DSL-Auftrag'
                . (isset($contract['insurer']) ? ' - ' . $contract['insurer'] : '')
                . (isset($contract['tariff']) ? ' ' . $contract['tariff'] : '')
                . ($name !== '' ? ' - ' . $name : '')
                . ' - Felder gratis aus dem Auftrag gelesen (ohne KI).',
            'title' => 'Internet-/DSL-Auftrag' . ($name !== '' ? ' ' . $name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $contract,
                'kfz' => [],
                'gesundheit' => [],
                'bank' => [],
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /**
     * @param list<string> $lines
     * @return array<string,mixed>
     */
    private function parsePerson(string $text, array $lines): array
    {
        $raw = [];

        if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text, $m)) {
            $raw['email'] = strtolower($m[0]);
        }
        // Geburtsdatum: nach dem Label.
        if (preg_match('/Geburtsdatum\D*(\d{2})\.(\d{2})\.(\d{4})/u', $text, $m)) {
            $raw['birth_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        // Handynummer/Telefon fuer Rueckfragen.
        if (preg_match('/(?:Handynummer|Telefon)[^\d]*(0[\d\s\/()+-]{8,20})/u', $text, $m)) {
            $digits = preg_replace('/[\s\/()+-]/', '', $m[1]);
            if (preg_match('/^0\d{7,14}$/', (string) $digits)) {
                $raw['phone'] = $digits;
            }
        }

        // Anschrift: Zeile "Adresse" gefolgt von Name / Strasse / PLZ Ort, oder
        // - falls das Label fehlt - ueber die "PLZ Ort"-Zeile und die Zeilen
        // darueber (Name, Strasse).
        $zip = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/(\d{5})\s+([A-ZÄÖÜ][\p{L}\-. ]{2,})$/u', trim($line), $m)
                && !preg_match('/(MBit|Monat|Euro|€|Tarif)/ui', $line)) {
                $zip = [$i, $m[1], trim($m[2])];
                break;
            }
        }
        if ($zip !== null) {
            $raw['zip'] = $zip[1];
            $raw['city'] = $zip[2];
            // Name + Strasse in den beiden nicht-leeren Zeilen ueber der PLZ.
            // Der Name steht oft rechts neben dem Label "Adresse" - das Label
            // wird abgeschnitten.
            $above = [];
            for ($j = $zip[0] - 1; $j >= 0 && count($above) < 2; $j--) {
                $v = trim((string) preg_replace('/^Adresse\s*:?\s*/iu', '', trim($lines[$j])));
                if ($v !== '') {
                    $above[] = $v;
                }
            }
            // above[0] = Strasse (naeher an PLZ), above[1] = Name.
            if (isset($above[0]) && preg_match('/^([A-ZÄÖÜ].*\D)\s*(\d+(?:\s*[a-zA-Z])?)$/u', $above[0], $s) && preg_match('/\p{L}{3,}/u', $s[1])) {
                $raw['street'] = trim($s[1]);
                $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
            }
            if (isset($above[1]) && preg_match('/^[A-ZÄÖÜ][\p{L}\-]+(?:\s+[A-ZÄÖÜ][\p{L}\-]+)+$/u', $above[1])) {
                $parts = preg_split('/\s+/', $above[1]) ?: [];
                $raw['first_name'] = array_shift($parts);
                $raw['last_name'] = implode(' ', $parts) ?: null;
            }
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseContract(string $text): array
    {
        $raw = ['sparte' => 'internet'];

        // Anbieter (z.B. Telekom, Vodafone, 1&1, o2).
        if (preg_match('/Anbieter\s*:?\s*([^\r\n]+?)(?:\s{2,}|$)/mu', $text, $m)) {
            $raw['insurer'] = trim($m[1]);
        }
        // Tarif (z.B. "Magenta Zuhause L").
        if (preg_match('/\bTarif\s*:?\s*([^\r\n]+?)(?:\s{2,}|$)/mu', $text, $m)) {
            $raw['tariff'] = trim($m[1]);
        }
        // Auftragsnummer als Vertrags-/Auftragsnummer (bis der finale
        // Provider-Vertrag mit eigener Nummer nachgereicht wird).
        if (preg_match('/Auftragsnummer\s*:?\s*([A-Z0-9\-]{4,})/u', $text, $m)) {
            $raw['contract_number'] = trim($m[1]);
        }
        // Durchschnittspreis pro Monat -> Monatsbeitrag.
        if (preg_match('/Durchschnitt pro Monat[^\d]*(\d{1,3}(?:\.\d{3})*,\d{2})/u', $text, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = 'monthly';
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }
}
