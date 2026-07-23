<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer die Kfz-Beitragsrechnung/Beitragsinformation der
 * AdmiralDirekt (Marke der Itzehoer Versicherung). Wie die ADAC-Beitrags-
 * information ist dieses Schreiben immer gleich aufgebaut und traegt die
 * Kern-Vertragsdaten eines bestehenden Kfz-Vertrags: Versicherer, Versiche-
 * rungs-Nummer, Kennzeichen, Deckung/Selbstbeteiligung, SF-Klasse (Haft-
 * pflicht), Jahresbeitrag und Abrechnungszeitraum.
 *
 * Ergebnis-Typ ist "kfz_vertrag" (Neugeschaeft-Gate): das Dokument bleibt im
 * Dokumenten-Eingang, damit der Mitarbeiter es der Kundenakte/dem Vertrag
 * zuordnet (kein stilles Auto-Zuordnen). Alle Werte durchlaufen die harte
 * Feldvalidierung; unsichere Felder bleiben leer statt falsch. Die (maskierte)
 * Kunden-IBAN und die Bankverbindung des Versicherers werden bewusst NICHT
 * uebernommen.
 */
class AdmiralDirektKfzParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    private const INSURER = 'AdmiralDirekt';

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);

        $upper = mb_strtoupper($text);
        // Nur die AdmiralDirekt-Schreiben selbst - NICHT ein CHECK24-
        // Beratungsprotokoll (Angebot), das AdmiralDirekt nur als Tarif nennt.
        if (str_contains($upper, 'CHECK24') || str_contains($upper, 'BERATUNGSPROTOKOLL')) {
            return null;
        }
        if (!str_contains($upper, 'ADMIRALDIREKT')
            || (!str_contains($upper, 'BEITRAGSRECHNUNG') && !str_contains($upper, 'BEITRAGSINFORMATION'))) {
            return null;
        }

        $this->lines = preg_split('/\R/', $text) ?: [];
        // Fliesstext-Kopie: Trennstrich-Umbrueche zusammenfuehren
        // ("Kfz-Haft-\npflicht" -> "Kfz-Haftpflicht") und Zeilen zu einer
        // Zeile machen - fuer Prosa-Anker (SF-Klasse, Zeitraum, Beitrag).
        $flat = (string) preg_replace('/-\s*\R\s*/u', '', $text);
        $flat = (string) preg_replace('/\s+/u', ' ', $flat);

        $person = $this->parsePerson();
        $vehicle = $this->parseVehicle($text, $flat);
        $insurance = $this->parseInsurance($text, $flat);

        if (($insurance['contract_number'] ?? null) === null && ($vehicle['license_plate'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $plate = $vehicle['license_plate'] ?? null;

        return [
            'type' => 'kfz_vertrag',
            'confidence' => 76,
            'summary' => 'AdmiralDirekt Kfz-Beitragsrechnung (Kfz-Vertrag)'
                . ($name !== '' ? ' - ' . $name : '')
                . ($plate !== null ? ' - ' . $plate : '')
                . (isset($insurance['contract_number']) ? ' - Vertrag ' . $insurance['contract_number'] : '')
                . (isset($vehicle['sf_liability_class']) ? ' - SF ' . $vehicle['sf_liability_class'] . ' (Haftpflicht)' : '')
                . (isset($vehicle['has_teilkasko']) || isset($vehicle['has_vollkasko'])
                    ? ' - Deckung: ' . $this->coverageSummary($vehicle) : '')
                . (isset($insurance['premium_amount']) ? ' - Jahresbeitrag ' . number_format($insurance['premium_amount'], 2, ',', '.') . ' EUR' : '')
                . ' - Felder gratis aus dem Schreiben gelesen (ohne KI).',
            'title' => 'AdmiralDirekt Kfz-Versicherung' . ($name !== '' ? ' ' . $name : ''),
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
     * Empfaenger-Anschrift aus dem Kopf. Das Layout ist zweispaltig: links der
     * Empfaenger (Anrede -> Name -> Strasse -> PLZ Ort), rechts die
     * Absenderdaten von AdmiralDirekt. Wir ankern auf die Anrede in der LINKEN
     * Spalte und lesen jeweils die linke Spalte der Folgezeilen.
     *
     * @return array<string,mixed>
     */
    private function parsePerson(): array
    {
        $raw = [];
        foreach ($this->lines as $i => $line) {
            $cols = $this->columns($line);
            if ($cols === [] || !preg_match('/^(Herrn|Herr|Frau)$/u', $cols[0])) {
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
            }
            break;
        }
        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * @param string $text Originaltext (Spaltenlayout)
     * @param string $flat Fliesstext-Kopie (Trennstriche zusammengefuehrt)
     * @return array<string,mixed>
     */
    private function parseVehicle(string $text, string $flat): array
    {
        $raw = [];

        // Kennzeichen: "Fahrzeug   RE XY 435 (Pkw)".
        if (preg_match('/Fahrzeug\s{2,}([A-ZÄÖÜ]{1,3}[ \-][A-Z]{1,2}[ \-]?\d{1,4}[A-Z]?)/u', $text, $m)) {
            $raw['license_plate'] = trim($m[1]);
        }

        // Deckung + Selbstbeteiligung: "Teilkaskoversicherung mit 150 €
        // Selbstbeteiligung". Vollkasko nur bei ausdruecklicher Nennung.
        $hasTeil = (bool) preg_match('/Teilkasko/u', $flat);
        $hasVoll = (bool) preg_match('/Vollkasko/u', $flat);
        if ($hasTeil || $hasVoll) {
            $raw['has_teilkasko'] = $hasTeil || $hasVoll; // Vollkasko schliesst Teilkasko ein
            $raw['has_vollkasko'] = $hasVoll;
        }
        if (preg_match('/Teilkasko\w*\s+mit\s+([\d.]+)\s*€\s*Selbstbeteiligung/u', $flat, $m)) {
            $raw['teilkasko_deductible'] = (int) str_replace('.', '', $m[1]);
        }
        if (preg_match('/Vollkasko\w*\s+mit\s+([\d.]+)\s*€\s*Selbstbeteiligung/u', $flat, $m)) {
            $raw['vollkasko_deductible'] = (int) str_replace('.', '', $m[1]);
        }

        // Werkstattbonus/-bindung (Schluessel aus ContractVehicleDetail::EXTRAS).
        if (preg_match('/mit Werkstatt(bonus|bindung|service)/u', $flat)) {
            $raw['extras'] = ['werkstattbindung'];
        }

        // SF-Klasse Haftpflicht: die tatsaechliche (uebertragbare) Klasse steht
        // eindeutig im Sondereinstufungs-Hinweis ("bestaetigen wir nur die
        // tatsaechliche SF-Klasse ... (Kfz-Haftpflicht M)").
        if (preg_match('/tats[äa]chliche SF-Klasse[^.()]*\(Kfz-Haftpflicht\s+(M|S|\d{1,2}(?:\/\d)?)\)/u', $flat, $m)) {
            $raw['sf_liability_class'] = strtoupper($m[1]);
        }

        return $this->validatedVehicle($raw);
    }

    /**
     * @param string $text Originaltext (Spaltenlayout)
     * @param string $flat Fliesstext-Kopie
     * @return array<string,mixed>
     */
    private function parseInsurance(string $text, string $flat): array
    {
        $raw = [
            'sparte' => 'kfz',
            'insurer' => self::INSURER,
        ];

        // Versicherungs-Nr. (z.B. "27393863-001").
        if (preg_match('/Versicherungs-Nr\.?\s+(\d{6,}-\d{3})/u', $text, $m)) {
            $raw['contract_number'] = $m[1];
        }

        // Tarifbezeichnung ("Basis Tarif").
        if (preg_match('/([A-Za-zÄÖÜäöüß]+)\s+Tarif\s+mit:/u', $flat, $m)) {
            $raw['tariff'] = trim($m[1]) . ' Tarif';
        }

        // Abrechnungszeitraum: "Zeitraum 13.08.2026 bis 12.08.2027".
        if (preg_match('/Zeitraum\s+(\d{2})\.(\d{2})\.(\d{4})\s+bis\s+(\d{2})\.(\d{2})\.(\d{4})/u', $flat, $m)) {
            $raw['start_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
            $raw['end_date'] = $m[6] . '-' . $m[5] . '-' . $m[4];
        }

        // Jahresbeitrag (Gesamtbeitrag inkl. Versicherungssteuer). Eindeutiger
        // Anker: "Den Betrag in Hoehe von 1.035,17 €".
        if (preg_match('/Betrag in H[öo]he von\s+([\d.]+,\d{2})\s*€/u', $flat, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
        } elseif (preg_match('/Gesamtbeitrag[^\n]*?([\d.]+,\d{2})\s*€\s*$/um', $text, $m)) {
            // Fallback: der LETZTE Betrag der Gesamtbeitrag-Zeile (nicht der
            // eingeklammerte Steuerbetrag).
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
        }
        if (isset($raw['premium_amount'])) {
            // Beitragsrechnung fuer das Versicherungsjahr -> jaehrliche Zahlweise.
            $raw['premium_interval'] = 'yearly';
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

    /** Zerlegt eine -layout-Zeile an Spaltengrenzen (>= 2 Leerzeichen). @return list<string> */
    private function columns(string $line): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\s{2,}/', trim($line)) ?: []),
            fn ($c) => $c !== ''
        ));
    }
}
