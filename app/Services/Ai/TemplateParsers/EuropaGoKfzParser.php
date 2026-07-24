<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer die Kfz-Tarifaenderungsinformation der EUROPA-go (Marke
 * der EUROPA Versicherung AG). Das Schreiben ist immer gleich aufgebaut und
 * traegt die Kern-Vertragsdaten eines bestehenden Kfz-Vertrags in einem
 * beschrifteten Datenblock: Versicherungsnummer, Kennzeichen, Hersteller,
 * Fahrleistung, Halter, SF-Klasse (Haftpflicht), Tarif und Monatsbeitrag.
 *
 * Besonderheit: der Nachname wird zusaetzlich aus der Anrede gelesen ("Sehr
 * geehrter Herr Abo Al-Kheir") - so werden mehrteilige Nachnamen ("Abo
 * Al-Kheir") korrekt vom Vornamen getrennt, statt nur das letzte Wort als
 * Nachnamen zu nehmen.
 *
 * Ergebnis-Typ ist "kfz_vertrag": das Dokument bleibt im Dokumenten-Eingang,
 * damit der Mitarbeiter es der Kundenakte/dem Vertrag zuordnet (kein stilles
 * Auto-Zuordnen). Alle Werte durchlaufen die harte Feldvalidierung; unsichere
 * Felder bleiben leer statt falsch. Die (maskierte) Kunden-IBAN und die
 * Bankverbindung des Versicherers werden bewusst NICHT uebernommen.
 */
class EuropaGoKfzParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    private const INSURER = 'EUROPA-go';

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);

        $upper = mb_strtoupper($text);
        if (str_contains($upper, 'CHECK24') || str_contains($upper, 'BERATUNGSPROTOKOLL')) {
            return null;
        }
        if (!str_contains($upper, 'EUROPA-GO')
            || !preg_match('/TARIF[ÄA]NDERUNG/u', $upper)
            || (!str_contains($upper, 'KFZ') && !str_contains($upper, 'KRAFTFAHRT'))) {
            return null;
        }

        $this->lines = preg_split('/\R/', $text) ?: [];

        $person = $this->parsePerson($text);
        $vehicle = $this->parseVehicle($text);
        $insurance = $this->parseInsurance($text);

        if (($insurance['contract_number'] ?? null) === null && ($vehicle['license_plate'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $plate = $vehicle['license_plate'] ?? null;

        return [
            'type' => 'kfz_vertrag',
            'confidence' => 76,
            'summary' => 'EUROPA-go Kfz-Tarifaenderung (Kfz-Vertrag)'
                . ($name !== '' ? ' - ' . $name : '')
                . ($plate !== null ? ' - ' . $plate : '')
                . (isset($insurance['contract_number']) ? ' - Vertrag ' . $insurance['contract_number'] : '')
                . (isset($vehicle['sf_liability_class']) ? ' - SF ' . $vehicle['sf_liability_class'] . ' (Haftpflicht)' : '')
                . (isset($vehicle['has_teilkasko']) || isset($vehicle['has_vollkasko'])
                    ? ' - Deckung: ' . $this->coverageSummary($vehicle) : '')
                . (isset($insurance['premium_amount'])
                    ? ' - Monatsbeitrag ' . number_format($insurance['premium_amount'], 2, ',', '.') . ' EUR' : '')
                . ($this->effectiveDate($text) !== null ? ' - Tarifaenderung ab ' . $this->effectiveDate($text) : '')
                . ' - Felder gratis aus dem Schreiben gelesen (ohne KI).',
            'title' => 'EUROPA-go Kfz-Versicherung' . ($name !== '' ? ' ' . $name : ''),
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
     * Empfaenger-Anschrift aus dem zweispaltigen Kopf (Empfaenger links,
     * Portal-/Absenderlinks rechts): Anrede -> Name -> Strasse -> PLZ Ort.
     * Der Nachname wird zusaetzlich aus der Anrede bestimmt, damit mehrteilige
     * Nachnamen ("Abo Al-Kheir") korrekt getrennt werden.
     *
     * @return array<string,mixed>
     */
    private function parsePerson(string $text): array
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
                [$first, $last] = $this->splitName($block[0], $text);
                $raw['first_name'] = $first;
                $raw['last_name'] = $last;
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
     * Vor-/Nachname trennen. Bevorzugt den Nachnamen aus der Anrede ("Sehr
     * geehrter Herr Abo Al-Kheir") - so bleiben mehrteilige Nachnamen intakt.
     * Faellt die Anrede aus, wird konservativ das letzte Wort als Nachname
     * genommen.
     *
     * @return array{0:?string,1:string} [Vorname, Nachname]
     */
    private function splitName(string $fullName, string $text): array
    {
        $fullName = trim((string) preg_replace('/\s+/', ' ', $fullName));
        if (preg_match('/Sehr geehrte[rs]?\s+(?:Herr|Frau)\s+([\p{L}][\p{L}\s\-]+?)\s*[,\r\n]/u', $text, $m)) {
            $surname = trim($m[1]);
            if ($surname !== '' && mb_stripos($fullName . ' ', $surname . ' ') !== false
                && str_ends_with($fullName, $surname)) {
                $first = trim(mb_substr($fullName, 0, mb_strlen($fullName) - mb_strlen($surname)));
                return [$first !== '' ? $first : null, $surname];
            }
        }
        // Fallback: letztes Wort = Nachname.
        $parts = preg_split('/\s+/', $fullName) ?: [];
        $last = array_pop($parts);
        return [implode(' ', $parts) ?: null, (string) $last];
    }

    /** @return array<string,mixed> */
    private function parseVehicle(string $text): array
    {
        $raw = [];

        if (($v = $this->labelValue('Amtl. Kennzeichen')) !== null) {
            $raw['license_plate'] = $v;
        }
        if (($v = $this->labelValue('Hersteller')) !== null) {
            // Laenderzusatz "(CZ)" abtrennen -> reiner Herstellername.
            $raw['manufacturer'] = trim((string) preg_replace('/\s*\(.*\)\s*$/u', '', $v));
        }
        if (($v = $this->labelValue('Jährl. Fahrleistung')) !== null && preg_match('/([\d.]+)/', $v, $m)) {
            $raw['annual_mileage'] = (int) str_replace('.', '', $m[1]);
        }
        if (($v = $this->labelValue('Fahrzeughalter')) !== null) {
            $raw['holder_type'] = stripos($v, 'Versicherungsnehmer') !== false
                ? 'versicherungsnehmer' : 'abweichender_halter';
        }

        // Deckung: das Schreiben weist die Sparten aus (Kfz-Haftpflicht,
        // Kasko-Spalte). Fehlt die Kasko ("---" bzw. keine Kasko-Zeile), ist sie
        // sicher nicht vorhanden.
        $hasVoll = (bool) preg_match('/Vollkasko/u', $text);
        $hasTeil = (bool) preg_match('/Teilkasko/u', $text) || $hasVoll;
        if (preg_match('/Kfz-Haftpflicht/u', $text)) {
            $raw['has_teilkasko'] = $hasTeil;
            $raw['has_vollkasko'] = $hasVoll;
        }

        // SF-Klasse Haftpflicht: die erste "SF X" in der Beitragstabelle gilt
        // (aktueller Vertragsstand ab dem naechsten Aenderungsdatum).
        if (preg_match('/\bSF\s*(\d{1,2}(?:\/\d)?|[MS])\b/u', $text, $m)) {
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

        if (($v = $this->labelValue('Versicherungsnummer')) !== null && preg_match('/\d{6,}/', $v, $m)) {
            $raw['contract_number'] = $m[0];
        }

        // Tarif ("- Basis-Tarif -").
        if (preg_match('/-\s*([A-Za-zÄÖÜäöüß]+-Tarif)\s*-/u', $text, $m)) {
            $raw['tariff'] = $m[1];
        }

        // Gueltig-ab-Datum der Tarifaenderung.
        if (($eff = $this->effectiveDate($text)) !== null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $eff, $m)) {
            $raw['start_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        // Monatlicher Gesamtbeitrag - der ERSTE (= zum naechsten Aenderungsdatum
        // gueltige) Wert, nicht der eines spaeteren Kalenderjahres.
        if (preg_match('/Monatlicher Gesamtbeitrag\s+([\d.]+,\d{2})/u', $text, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = 'monthly';
        } elseif (($v = $this->labelValue('Zahlungsperiode')) !== null) {
            $raw['premium_interval'] = $this->interval($v);
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** Gueltig-ab-Datum der Tarifaenderung ("...aenderung ... ab 01.03.2026"). */
    private function effectiveDate(string $text): ?string
    {
        if (preg_match('/Tarif[äa]nderung\s+ab\s+(\d{2}\.\d{2}\.\d{4})/u', $text, $m)) {
            return $m[1];
        }
        if (preg_match('/Beitr[äa]ge ab\s+(\d{2}\.\d{2}\.\d{4})/u', $text, $m)) {
            return $m[1];
        }
        return null;
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

    /**
     * Wert nach "Label:" bis zur naechsten Spalte (2+ Leerzeichen) oder
     * Zeilenende - funktioniert auch fuer die rechte Spalte eines
     * zweispaltigen "Label: Wert   Label2: Wert2"-Datenblocks.
     */
    private function labelValue(string $label): ?string
    {
        $pattern = '/' . preg_quote($label, '/') . '\s*:\s+([^\n]+?)(?:\s{2,}|$)/mu';
        return preg_match($pattern, implode("\n", $this->lines), $m) ? trim($m[1]) : null;
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
