<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer den Kfz-Versicherungsschein (Police) der Allianz
 * (Allianz Versicherungs-AG), inkl. der Nutz-/Flottenfahrzeug-Variante
 * (AKB-NF). Wie die DA-Direkt-Police ist dies der ECHTE Vertrag mit gueltiger
 * Versicherungsschein-Nummer (AS-...) - die Kern-Vertragsdaten lassen sich per
 * fester Regel gratis aus der Textebene lesen (kein KI-Aufruf): Versicherer,
 * Vertragsnummer, Kennzeichen, Fahrzeugdaten (FIN/HSN/Hersteller), Halter,
 * Deckung, SF-Klasse (Haftpflicht), Zusatzleistungen (Schutzbrief), Beitrag,
 * Beginn und Ablauf.
 *
 * Ergebnis-Typ ist "kfz_vertrag" (Neugeschaeft): das Dokument bleibt im
 * Dokumenten-Eingang, damit der Mitarbeiter den Vertrag mit der echten VSNR
 * anlegt (kein stilles Auto-Zuordnen). Alle Werte durchlaufen die harte
 * Feldvalidierung; unsichere Felder bleiben leer statt falsch. Die (maskierte)
 * Kunden-IBAN wird bewusst NICHT uebernommen.
 */
class AllianzKfzPoliceParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    private const INSURER = 'Allianz';

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        // Weiche Trennzeichen normalisieren (umbrochene Woerter zusammenfuehren).
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);

        $upper = mb_strtoupper($text);
        // Nur die echte Allianz-Kfz-Police - NICHT ein CHECK24-Beratungsprotokoll
        // (Angebot), das die Allianz nur als moeglichen Tarif nennt.
        if (str_contains($upper, 'CHECK24') || str_contains($upper, 'BERATUNGSPROTOKOLL')) {
            return null;
        }
        if (!str_contains($upper, 'ALLIANZ')
            || !str_contains($upper, 'VERSICHERUNGSSCHEIN')
            || !str_contains($upper, 'KFZ-VERSICHERUNG')) {
            return null;
        }

        $this->lines = preg_split('/\R/', $text) ?: [];

        $person = $this->parsePerson($text);
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
            'summary' => 'Allianz Kfz-Versicherungsschein (Kfz-Vertrag)'
                . ($name !== '' ? ' - ' . $name : '')
                . ($plate !== null ? ' - ' . $plate : '')
                . (isset($insurance['contract_number']) ? ' - Vertrag ' . $insurance['contract_number'] : '')
                . (isset($vehicle['sf_liability_class']) ? ' - SF ' . $vehicle['sf_liability_class'] . ' (Haftpflicht)' : '')
                . (isset($vehicle['has_teilkasko']) || isset($vehicle['has_vollkasko'])
                    ? ' - Deckung: ' . $this->coverageSummary($vehicle) : '')
                . (in_array('schutzbrief', $vehicle['extras'] ?? [], true) ? ' - mit Schutzbrief' : '')
                . ' - Felder gratis aus dem Versicherungsschein gelesen (ohne KI).',
            'title' => 'Allianz Kfz-Versicherung' . ($name !== '' ? ' ' . $name : ''),
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
     * Anschriftenblock: nach "Versicherungsnehmer:in" stehen Name, Strasse und
     * "PLZ Ort" in den naechsten nicht-leeren Zeilen. Geschlecht aus der Anrede
     * der Bankzeile ("lautend auf Herrn/Frau ...").
     *
     * @return array<string,mixed>
     */
    private function parsePerson(string $text): array
    {
        $raw = [];
        foreach ($this->lines as $i => $line) {
            if (!preg_match('/^\s*Versicherungsnehmer(:in)?\s*$/u', trim($line))) {
                continue;
            }
            $block = [];
            for ($j = $i + 1; $j < count($this->lines) && count($block) < 3; $j++) {
                $val = trim($this->lines[$j]);
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
                break;
            }
        }

        // Geschlecht aus "lautend auf Herrn/Frau ...".
        if (preg_match('/lautend auf\s+(Herrn|Herr|Frau)\b/u', $text, $m)) {
            $raw['gender'] = mb_strtolower($m[1]) === 'frau' ? 'female' : 'male';
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseVehicle(string $text): array
    {
        $raw = [];

        if (($v = $this->labelValue('Amtliches Kennzeichen')) !== null) {
            $raw['license_plate'] = $v;
        }
        if (($v = $this->labelValue('Hersteller')) !== null) {
            $raw['manufacturer'] = $v;
        }
        if (($v = $this->labelValue('Hersteller-Schlüssel-Nr.')) !== null && preg_match('/\b(\d{4})\b/', $v, $m)) {
            $raw['hsn'] = $m[1];
        }
        if (($v = $this->labelValue('Fahrzeug-Identifizierungs-Nr.')) !== null
            && preg_match('/\b([A-HJ-NPR-Z0-9]{11,17})\b/', $v, $m)) {
            $raw['vin'] = $m[1];
        }

        // Halter: "Das Fahrzeug ist zugelassen auf den Versicherungsnehmer."
        if (preg_match('/zugelassen auf den Versicherungsnehmer/u', $text)) {
            $raw['holder_type'] = 'versicherungsnehmer';
        }

        // Deckung. Weist die Police die Kasko ausdruecklich als NICHT bestehend
        // aus ("Eine Kaskoversicherung besteht durch diesen Vertrag nicht"),
        // sind Teil- und Vollkasko sicher false; ansonsten aus den vorhandenen
        // Bausteinen ableiten.
        $hasVoll = (bool) preg_match('/\bVollkasko/u', $text) && stripos($text, 'Kaskoversicherung besteht durch diesen Vertrag nicht') === false;
        $hasTeil = ((bool) preg_match('/\bTeilkasko/u', $text) || $hasVoll)
            && stripos($text, 'Kaskoversicherung besteht durch diesen Vertrag nicht') === false;
        if (stripos($text, 'Kaskoversicherung besteht durch diesen Vertrag nicht') !== false
            || stripos($text, 'Kaskoversicherung') !== false) {
            $raw['has_teilkasko'] = $hasTeil;
            $raw['has_vollkasko'] = $hasVoll;
        }

        // Zusatzleistung Schutzbrief (Schluessel aus ContractVehicleDetail::EXTRAS).
        if (stripos($text, 'Schutzbrief') !== false) {
            $raw['extras'] = ['schutzbrief'];
        }

        // SF-Klasse Haftpflicht ("Kfz-Haftpflichtversicherung ... Klasse 0
        // (Beitragssatz 110 %)").
        if (preg_match('/Klasse\s+(\d{1,2}(?:\/\d)?|[MS])\s*\(Beitragssatz/u', $text, $m)) {
            $raw['sf_liability_class'] = strtoupper($m[1]);
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

        if (preg_match('/Versicherungsschein-Nummer:\s*([A-Z]{2}-\d{6,})/u', $text, $m)) {
            $raw['contract_number'] = $m[1];
        }
        if (preg_match('/Versicherungsbeginn:\s+(\d{2})\.(\d{2})\.(\d{4})/u', $text, $m)) {
            $raw['start_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        if (preg_match('/Versicherungsablauf:\s+(\d{2})\.(\d{2})\.(\d{4})/u', $text, $m)) {
            $raw['end_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        // Gesamtbeitrag (= Beitrag gemaess Zahlungsperiode, hier vierteljaehrlich)
        // - NICHT der Nettobeitrag oder der Steuerbetrag.
        if (preg_match('/Gesamtbeitrag\s+([\d.]+,\d{2})/u', $text, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = $this->paymentInterval($text);
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** Zahlungsperiode aus dem Fliesstext ("vierteljaehrliche Zahlungsperiode"). */
    private function paymentInterval(string $text): ?string
    {
        return match (true) {
            (bool) preg_match('/monatlich/ui', $text) => 'monthly',
            (bool) preg_match('/viertelj[äa]hrlich/ui', $text) => 'quarterly',
            (bool) preg_match('/halbj[äa]hrlich/ui', $text) => 'semiannual',
            (bool) preg_match('/j[äa]hrlich/ui', $text) => 'yearly',
            default => null,
        };
    }

    /** Kurztext der Deckung fuer die Zusammenfassung (Haftpflicht immer dabei). */
    private function coverageSummary(array $kfz): string
    {
        $parts = ['Haftpflicht'];
        if (!empty($kfz['has_teilkasko'])) {
            $parts[] = 'Teilkasko';
        }
        if (!empty($kfz['has_vollkasko'])) {
            $parts[] = 'Vollkasko';
        }
        if (empty($kfz['has_teilkasko']) && empty($kfz['has_vollkasko'])) {
            $parts[] = 'keine Kasko';
        }
        return implode(', ', $parts);
    }

    /**
     * Wert nach "Label:" (Doppelpunkt optional) bis zum Zeilenende, im
     * Spaltenlayout (Label links, Wert nach mehreren Leerzeichen).
     */
    private function labelValue(string $label): ?string
    {
        foreach ($this->lines as $line) {
            if (preg_match('/^\s*' . preg_quote($label, '/') . '\s*:?\s{2,}([^\n]+?)\s*$/u', $line, $m)) {
                return trim($m[1]);
            }
        }
        return null;
    }
}
