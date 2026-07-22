<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer den Kfz-Versicherungsschein (Police) der DA Direkt
 * (DA Deutsche Allgemeine Versicherung AG). Anders als das CHECK24-
 * Beratungsprotokoll (Angebot/Vergleich) ist dies der ECHTE Vertrag mit der
 * gueltigen Versicherungsscheinnummer (VSE/...). Diese Policen sind ueber alle
 * Kunden hinweg identisch aufgebaut - Versicherer, Vertragsnummer, Kennzeichen,
 * Fahrzeugdaten, Deckung/Selbstbeteiligung, SF-Klasse (Haftpflicht), Beitrag
 * und Beginn lassen sich per fester Regel gratis aus der Textebene lesen.
 *
 * Ergebnis-Typ ist "kfz_vertrag" (Neugeschaeft): das Dokument bleibt im
 * Dokumenten-Eingang, damit der Mitarbeiter den Vertrag mit der echten VSNR
 * anlegt (kein stilles Auto-Zuordnen). Alle Werte durchlaufen dieselbe harte
 * Feldvalidierung wie die KI-Antwort; unsichere Felder bleiben leer statt
 * falsch. Die (maskierte) Kunden-IBAN und die Bankverbindung des Versicherers
 * werden bewusst NICHT uebernommen.
 */
class DaDirektKfzPoliceParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    private const INSURER = 'DA Direkt';

    public function parse(string $text): ?array
    {
        // Weiche Trennzeichen normalisieren (umbrochene Woerter zusammenfuehren).
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);

        $upper = mb_strtoupper($text);
        // Nur der echte DA-Direkt-Kfz-Versicherungsschein - NICHT das
        // CHECK24-Beratungsprotokoll (Angebot), das DA Direkt nur als Tarif nennt.
        if (str_contains($upper, 'CHECK24') || str_contains($upper, 'BERATUNGSPROTOKOLL')) {
            return null;
        }
        if (!str_contains($upper, 'DA DIREKT')
            || !str_contains($upper, 'VERSICHERUNGSSCHEIN')
            || (!str_contains($upper, 'KRAFTFAHRTVERSICHERUNG') && !str_contains($upper, 'KFZ-VERSICHERUNG'))) {
            return null;
        }

        $lines = array_map('rtrim', preg_split('/\R/', $text) ?: []);

        $person = $this->parsePerson($lines, $text);
        $vehicle = $this->parseVehicle($text);
        $insurance = $this->parseInsurance($text);

        // Ohne belastbaren Kern (Vertragsnummer oder Kennzeichen) lieber der
        // normalen Analyse ueberlassen.
        if (($insurance['contract_number'] ?? null) === null && ($vehicle['license_plate'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $plate = $vehicle['license_plate'] ?? null;

        return [
            'type' => 'kfz_vertrag',
            'confidence' => 78,
            'summary' => 'DA-Direkt Kfz-Versicherungsschein (Kfz-Vertrag)'
                . ($name !== '' ? ' - ' . $name : '')
                . ($plate !== null ? ' - ' . $plate : '')
                . (isset($insurance['contract_number']) ? ' - Vertrag ' . $insurance['contract_number'] : '')
                . (isset($vehicle['sf_liability_class']) ? ' - SF ' . $vehicle['sf_liability_class'] . ' (Haftpflicht)' : '')
                . (isset($insurance['tariff']) ? ' - ' . $insurance['tariff'] : '')
                . (isset($vehicle['has_teilkasko']) || isset($vehicle['has_vollkasko'])
                    ? ' - Deckung: ' . $this->coverageSummary($vehicle) : '')
                . ' - Felder gratis aus dem Versicherungsschein gelesen (ohne KI).',
            'title' => 'DA Direkt Kfz-Versicherung' . ($name !== '' ? ' ' . $name : ''),
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
     * Anschriftenblock oben im Brief: "Herrn/Frau" -> Name -> Strasse -> PLZ Ort.
     * Geschlecht aus der Anrede, Geburtsdatum aus dem Tarifmerkmal "Geburtsdatum
     * VN" (falls vorhanden).
     *
     * @param list<string> $lines @return array<string,mixed>
     */
    private function parsePerson(array $lines, string $text): array
    {
        $raw = [];
        foreach ($lines as $i => $line) {
            if (!preg_match('/^\s*(Herrn|Herr|Frau)\s*$/u', trim($line), $anrede)) {
                continue;
            }
            // Folgezeilen (nicht-leer) einsammeln: Name, Strasse, PLZ Ort.
            $block = [];
            for ($j = $i + 1; $j < count($lines) && count($block) < 3; $j++) {
                $val = trim($lines[$j]);
                if ($val !== '') {
                    $block[] = $val;
                }
            }
            if (count($block) >= 3
                && preg_match('/^[A-ZÄÖÜ][\p{L}\-]+(?:\s+[A-ZÄÖÜ][\p{L}\-]+)+$/u', $block[0])
                && preg_match('/^(\d{5})\s+(.+)$/', $block[2], $z)) {
                $nameParts = preg_split('/\s+/', $block[0]) ?: [];
                $raw['last_name'] = array_pop($nameParts);
                $raw['first_name'] = implode(' ', $nameParts) ?: null;
                if (preg_match('/^(.*\D)\s*(\d+(?:\s*[a-zA-Z])?)\s*$/u', $block[1], $s)) {
                    $raw['street'] = trim($s[1]);
                    $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
                } else {
                    $raw['street'] = $block[1];
                }
                $raw['zip'] = $z[1];
                $raw['city'] = trim($z[2]);
                $raw['gender'] = mb_strtolower($anrede[1]) === 'frau' ? 'female' : 'male';
                break;
            }
        }

        // Geburtsdatum des Versicherungsnehmers (Tarifmerkmal "Geburtsdatum VN").
        if (preg_match('/Geburtsdatum\s+VN\s*:?\s*(\d{2})\.(\d{2})\.(\d{4})/u', $text, $m)) {
            $raw['birth_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseVehicle(string $text): array
    {
        $raw = [];

        // Amtliches Kennzeichen (Spaltenlayout: Wert bis zur naechsten Spalte).
        if (preg_match('/Amtliches Kennzeichen:\s+([A-ZÄÖÜ0-9][A-ZÄÖÜ0-9 \-]*?)(?:\s{2,}|$)/mu', $text, $m)) {
            $raw['license_plate'] = trim($m[1]);
        }
        // Hersteller.
        if (preg_match('/Hersteller:\s+([A-ZÄÖÜ][A-Za-zÄÖÜäöüß.\- ]*?)(?:\s{2,}|$)/mu', $text, $m)) {
            $raw['manufacturer'] = trim($m[1]);
        }
        // Fahrzeug-Identnr. (FIN) - die harte Validierung prueft das FIN-Format.
        if (preg_match('/Fahrzeug-Identnr\.?:\s+([A-HJ-NPR-Z0-9]{11,17})/u', $text, $m)) {
            $raw['vin'] = $m[1];
        }
        // Typschluessel (TSN) - eine HSN steht auf der Police nicht.
        if (preg_match('/Typschl[üu]ssel:\s+([A-Z0-9]{3})\b/u', $text, $m)) {
            $raw['tsn'] = strtoupper($m[1]);
        }
        // Erstzulassung.
        if (preg_match('/Erstzulassung:\s+(\d{2})\.(\d{2})\.(\d{4})/u', $text, $m)) {
            $raw['first_registration'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        // Jaehrliche Fahrleistung (Tarifmerkmal).
        if (preg_match('/Jahreskilometerleistung[^:]*:\s+([\d.]+)/u', $text, $m)) {
            $raw['annual_mileage'] = (int) str_replace('.', '', $m[1]);
        }

        // Deckung + Selbstbeteiligung. Teilkasko und Vollkasko werden auf der
        // Police als "Fahrzeug-Teilversicherung"/"Fahrzeug-Vollversicherung mit
        // XXX,XX EUR Selbstbeteiligung" ausgewiesen. Die im Kasko-Hinweis
        // genannte "zusaetzliche Selbstbeteiligung 500 Euro" (Haarwild) ist NICHT
        // die vereinbarte SB und wird bewusst NICHT als Deckungswert gelesen.
        $hasTeil = false;
        $hasVoll = false;
        if (preg_match('/Fahrzeug-Teilversicherung mit\s+([\d.]+),\d{2}\s*EUR Selbstbeteiligung/u', $text, $m)) {
            $hasTeil = true;
            $raw['teilkasko_deductible'] = (int) str_replace('.', '', $m[1]);
        }
        if (preg_match('/Fahrzeug-Vollversicherung mit\s+([\d.]+),\d{2}\s*EUR Selbstbeteiligung/u', $text, $m)) {
            $hasVoll = true;
            // Vollkasko schliesst Teilkasko ein.
            $hasTeil = true;
            $raw['vollkasko_deductible'] = (int) str_replace('.', '', $m[1]);
        }
        // Ist ueberhaupt eine Fahrzeugversicherung (Kasko) ausgewiesen, beide
        // Flags setzen (auch false) - die Police ist eindeutig.
        if ($hasTeil || $hasVoll || stripos($text, 'Fahrzeugversicherung') !== false) {
            $raw['has_teilkasko'] = $hasTeil;
            $raw['has_vollkasko'] = $hasVoll;
        }

        // Werkstattbindung aus dem Tarifnamen ("... mit Werkstattbindung").
        if (stripos($text, 'mit Werkstattbindung') !== false) {
            $raw['extras'] = ['werkstattbindung'];
        }

        // SF-Klasse Haftpflicht ("Beitragsklasse: SF 2").
        if (preg_match('/Beitragsklasse:\s*SF\s*(\d{1,2}(?:\/\d)?|[MS])/u', $text, $m)) {
            $raw['sf_liability_class'] = strtoupper($m[1]);
        }

        // Abweichender Fahrzeughalter (relevant fuer Annahme/Beitrag).
        if (preg_match('/Abweichender Fahrzeughalter:\s*\S/u', $text)) {
            $raw['holder_type'] = 'abweichender_halter';
        }

        return $this->validatedVehicle($raw);
    }

    /** @return array<string,mixed> */
    private function parseInsurance(string $text): array
    {
        $raw = [
            'sparte' => 'kfz',
            'insurer' => self::INSURER,
        ];

        // Vertrags-/Versicherungsscheinnummer: bevorzugt "Kraftfahrtversicherung
        // Nr. 302.544.159", sonst aus "VSE/302.544.159/09".
        if (preg_match('/Kraftfahrtversicherung Nr\.?\s*:?\s*([\d][\d.]+\d)/u', $text, $m)) {
            $raw['contract_number'] = $m[1];
        } elseif (preg_match('/VSE\/([\d][\d.]+\d)/u', $text, $m)) {
            $raw['contract_number'] = $m[1];
        }

        // Tarifname ("Mein Tarif Basis") + Hinweis auf Werkstattbindung.
        if (preg_match('/(Mein Tarif [A-Za-zÄÖÜäöüß]+)/u', $text, $m)) {
            $tariff = trim($m[1]);
            if (stripos($text, 'mit Werkstattbindung') !== false) {
                $tariff .= ' (mit Werkstattbindung)';
            }
            $raw['tariff'] = $tariff;
        }

        // Versicherungsbeginn.
        if (preg_match('/Versicherungsbeginn:\s+(\d{2})\.(\d{2})\.(\d{4})/u', $text, $m)) {
            $raw['start_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        // Gesamtbeitrag gemaess Zahlungsweise (NICHT die Teilbeitraege der
        // einzelnen Sparten). Zahlungsweise -> Intervall.
        if (preg_match('/Beitrag gem[äa][ßss]+ Zahlungsweise:\s*([\d.]+,\d{2})\s*EUR/u', $text, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
        }
        $zw = null;
        if (preg_match('/Zahlungsweise:\s+([A-Za-zäöüÄÖÜß]+)/u', $text, $m)) {
            $zw = $this->interval($m[1]);
        }
        if (isset($raw['premium_amount']) && $zw !== null) {
            $raw['premium_interval'] = $zw;
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** Kurztext der Deckung fuer die Zusammenfassung (Haftpflicht immer dabei). */
    private function coverageSummary(array $kfz): string
    {
        $parts = ['Haftpflicht'];
        if (!empty($kfz['has_teilkasko'])) {
            $parts[] = 'Teilkasko' . (isset($kfz['teilkasko_deductible']) ? ' (' . $kfz['teilkasko_deductible'] . ' EUR SB)' : '');
        }
        if (!empty($kfz['has_vollkasko'])) {
            $parts[] = 'Vollkasko' . (isset($kfz['vollkasko_deductible']) ? ' (' . $kfz['vollkasko_deductible'] . ' EUR SB)' : '');
        }
        return implode(', ', $parts);
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
}
