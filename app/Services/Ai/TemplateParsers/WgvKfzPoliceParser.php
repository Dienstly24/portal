<?php
namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\ReadsDocumentPages;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer den Kfz-Versicherungsschein der WGV-Versicherung AG
 * ("Versicherungsschein zur Kraftfahrtversicherung"). Das ist der ECHTE
 * Vertrag mit gueltiger Versicherungsscheinnummer - er traegt praktisch alle
 * Angaben, die die Kundenakte fuer einen Kfz-Vertrag braucht:
 *
 *   Vertrag  : Versicherungsscheinnummer, Kunden-/Mitgliedsnummer, Tarif,
 *              Beginn, Ablauf, Beitrag (monatlicher Folgebeitrag bzw.
 *              Jahresbeitrag), Ausfertigungsgrund
 *   Fahrzeug : Kennzeichen, Fahrzeugart, FIN, Hersteller, Leistung,
 *              Erstzulassung, Zulassung auf den Halter, HSN/TSN,
 *              Schadenfreiheitsklasse, Jahresfahrleistung, Kilometerstand
 *   Person   : Anschriftenblock, Geburtsdatum des Versicherungsnehmers
 *
 * Die Schreiben kommen als HANDYFOTO. Die OCR eines Fotos kennt kein
 * Spaltenraster: zwischen Beschriftung und Wert steht oft nur EIN Leerzeichen,
 * manchmal bricht der Wert in die naechste Zeile um. Die Feldsuche laesst
 * daher Doppelpunkt, Spaltenabstand UND einfaches Leerzeichen zu und schaut
 * notfalls in die Folgezeile.
 *
 * Bewusst NICHT uebernommen:
 * - Die Kunden-IBAN ist im Schreiben MASKIERT ("DE78 XXX ... 86 39"); die
 *   vollstaendige IBAN im Fussbereich gehoert der WGV selbst (LBBW). Es
 *   werden daher gar keine Bankdaten gelesen.
 * - Anschrift und Kontaktdaten des Versicherers (Servicezentrum,
 *   Hauptverwaltung, Postanschrift) - der Kunde steht im Anschriftenfeld.
 */
class WgvKfzPoliceParser implements DocumentTemplateParser
{
    use ReadsDocumentPages;
    use ValidatesExtractedFields;

    private const INSURER = 'WGV Versicherung AG';

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $upper = mb_strtoupper($text);

        if ($this->looksLikeComparisonProtocol($text)) {
            return null;
        }
        if (!str_contains($upper, 'WGV')
            || (!str_contains($upper, 'KRAFTFAHRTVERSICHERUNG') && !str_contains($upper, 'KFZ-VERSICHERUNG'))) {
            return null;
        }

        $this->lines = array_map('rtrim', preg_split('/\R/', $text) ?: []);

        $person = $this->parsePerson();
        $vehicle = $this->parseVehicle();
        $insurance = $this->parseInsurance();

        // Ohne belastbaren Kern (Scheinnummer oder Kennzeichen) der normalen
        // Analyse ueberlassen.
        if (($insurance['contract_number'] ?? null) === null && ($vehicle['license_plate'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $kundennummer = $this->labelValue('Mitglieds-/Kundennummer') ?? $this->labelValue('Kundennummer');

        return [
            'type' => 'kfz_vertrag',
            'confidence' => 78,
            'summary' => 'WGV Kfz-Versicherungsschein'
                . ($name !== '' ? ' - ' . $name : '')
                . (isset($vehicle['license_plate']) ? ' - ' . $vehicle['license_plate'] : '')
                . (isset($insurance['contract_number']) ? ' - Schein ' . $insurance['contract_number'] : '')
                . ($kundennummer !== null ? ' - Kundennr. ' . $kundennummer : '')
                . (isset($vehicle['sf_liability_class']) ? ' - SF ' . $vehicle['sf_liability_class'] : '')
                . ' - Deckung: ' . $this->coverageSummary($vehicle)
                . (isset($insurance['premium_amount'])
                    ? ' - Beitrag ' . number_format($insurance['premium_amount'], 2, ',', '.') . ' EUR'
                        . ($insurance['premium_interval'] === 'monthly' ? '/Monat' : '')
                    : '')
                . (isset($vehicle['initial_mileage'])
                    ? ' - Kilometerstand ' . number_format($vehicle['initial_mileage'], 0, ',', '.') . ' km' : '')
                . ' - Felder gratis aus dem Versicherungsschein gelesen (ohne KI).',
            'title' => 'WGV Kfz-Versicherung' . ($name !== '' ? ' ' . $name : ''),
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
     * Anschriftenblock des Kunden: nach der Anrede-Zeile ("Herrn"/"Frau")
     * folgen Name, Strasse und "PLZ Ort". Die Anschriften der WGV stehen in
     * der rechten Spalte unter eigenen Ueberschriften und werden dadurch nie
     * als Kunde gelesen.
     *
     * @return array<string,mixed>
     */
    private function parsePerson(): array
    {
        $raw = [];

        foreach ($this->lines as $i => $line) {
            if (!preg_match('/^\s*(Herrn|Herr|Frau)\s*$/u', trim($line), $g)) {
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
                && preg_match('/^\p{Lu}[\p{L}\-\']+(?:\s+\p{Lu}?[\p{L}\-\']+)+$/u', $block[0])
                && preg_match('/^(\d{5})\s+(.+)$/', $block[2], $z)) {
                $raw['gender'] = mb_strtolower($g[1]) === 'frau' ? 'female' : 'male';
                $parts = preg_split('/\s+/', $block[0]) ?: [];
                $raw['first_name'] = array_shift($parts);
                $raw['last_name'] = implode(' ', $parts) ?: null;
                if (preg_match('/^(.*\p{L}\.?)\s+(\d+\s*[a-zA-Z]?)\s*$/u', $block[1], $s)) {
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

        // Geburtsdatum steht bei den Beitragsmerkmalen.
        $birth = $this->labelValue('Geburtsdatum des Versicherungsnehmers');
        if ($birth !== null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $birth, $m)) {
            $raw['birth_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        // Berufsgruppe ("Angestellte (m/w/d)") als Beruf - so steht es im Schein.
        $beruf = $this->labelValue('Berufsgruppe');
        if ($beruf !== null && preg_match('/^\p{L}[\p{L} .\/()-]{2,60}$/u', $beruf)) {
            $raw['occupation'] = trim((string) preg_replace('/\s*\(m\/w\/d\)\s*/iu', '', $beruf));
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseVehicle(): array
    {
        $raw = [];

        // Kennzeichen: "fuer Fahrzeug: RV-CF 642" bzw. "Versichertes Fahrzeug".
        $plate = $this->labelValue('für Fahrzeug') ?? $this->labelValue('Versichertes Fahrzeug');
        if ($plate !== null && preg_match('/^[A-ZÄÖÜ]{1,3}[- ]?[A-ZÄÖÜ]{1,2}\s?\d{1,4}[EH]?$/u', trim($plate))) {
            $raw['license_plate'] = trim($plate);
        }
        if (($v = $this->labelValue('Fahrzeugart')) !== null && preg_match('/^\p{L}[\p{L} .\-]{3,40}$/u', $v)) {
            $raw['vehicle_type'] = $this->mapVehicleType($v);
        }
        // FIN: "Fahrzg.-Ident-Nr." bzw. "Fahrzeug-Ident-Nr.".
        foreach (['Fahrzg.-Ident-Nr.', 'Fahrzeug-Ident-Nr.', 'Fahrzeug-Identifizierungs-Nr.'] as $label) {
            $v = $this->labelValue($label);
            if ($v !== null && preg_match('/\b([A-HJ-NPR-Z0-9]{11,17})\b/', strtoupper($v), $m)) {
                $raw['vin'] = $m[1];
                break;
            }
        }
        // Hersteller: der Schein nennt die HSN-Gruppe ("FIAT (I) INKL. ALFA,
        // LANCIA,") - uebernommen wird nur die Marke davor.
        if (($v = $this->labelValue('Hersteller')) !== null && !str_contains($v, '/')) {
            $marke = trim((string) preg_split('/[(,]/u', $v)[0]);
            if (preg_match('/^\p{L}[\p{L} .\-]{1,40}$/u', $marke)) {
                $raw['manufacturer'] = $marke;
            }
        }
        if (($v = $this->labelValue('Stärke')) !== null && preg_match('/^([\d.]+)(?:,\d+)?\s*KW/iu', trim($v), $m)) {
            $raw['power_kw'] = (int) str_replace('.', '', $m[1]);
        }
        if (($v = $this->labelValue('Erstzulassung')) !== null && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', trim($v), $m)) {
            $raw['first_registration'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        if (($v = $this->labelValue('Erstzulassung auf VN')) !== null && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', trim($v), $m)) {
            $raw['acquisition_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        // "Herst.Nr./Typ Nr.   4136 / 668".
        foreach (['Herst.Nr./Typ Nr.', 'Herst.-Nr./Typ-Nr.', 'Hersteller-/Typ-Nummer'] as $label) {
            $v = $this->labelValue($label);
            if ($v !== null && preg_match('#^(\d{4})\s*/\s*([A-Z0-9]{3})#i', trim($v), $m)) {
                $raw['hsn'] = $m[1];
                $raw['tsn'] = strtoupper($m[2]);
                break;
            }
        }
        if (($v = $this->labelValue('Schadensfreiheitsklasse')) !== null
            || ($v = $this->labelValue('Schadenfreiheitsklasse')) !== null) {
            $raw['sf_liability_class'] = trim($v);
        }
        if (($v = $this->labelValue('Jährliche Fahrleistung')) !== null
            && preg_match('/([\d.]+)\s*km/iu', $v, $m)) {
            $raw['annual_mileage'] = (int) str_replace('.', '', $m[1]);
        }
        // "Aktueller Kilometerstand am 26.07.2026:   135.752 km".
        foreach ($this->lines as $line) {
            if (preg_match('/Aktueller Kilometerstand[^:]*:?\s*.*?([\d.]{3,})\s*km/iu', $line, $m)) {
                $raw['initial_mileage'] = (int) str_replace('.', '', $m[1]);
                break;
            }
        }

        // Deckung: der Versicherungsumfang nennt die enthaltenen Bausteine.
        // Der Rechtstext auf der Beitragsseite erwaehnt "Kaskoversicherung"
        // nur beispielhaft - er darf nicht als Deckung zaehlen.
        $umfang = $this->sectionText('Versicherungsumfang', ['Beitragsrechnung', 'Jahresbeitrag für Vertrag', 'WICHTIGER HINWEIS']);
        if ($umfang !== null) {
            $raw['has_vollkasko'] = (bool) preg_match('/Vollkasko|Fahrzeugvollversicherung/iu', $umfang);
            $raw['has_teilkasko'] = $raw['has_vollkasko']
                || (bool) preg_match('/Teilkasko|Fahrzeugteilversicherung/iu', $umfang);
        }

        return $this->validatedVehicle($raw);
    }

    /** @return array<string,mixed> */
    private function parseInsurance(): array
    {
        $raw = [
            'sparte' => 'kfz',
            'insurer' => self::INSURER,
            // Der Versicherungsschein ist der bestaetigte Vertrag.
            'document_stage' => Contract::STAGE_VERTRAG,
        ];

        // Versicherungsscheinnummer ("V 90 115 823/426").
        $number = $this->labelValue('Versicherungsscheinnummer') ?? $this->labelValue('Versicherungsschein-Nr.');
        if ($number !== null && preg_match('#^[A-Z]?\s?[\d /-]{6,30}$#u', trim($number))) {
            $raw['contract_number'] = trim((string) preg_replace('/\s+/', ' ', $number));
        }

        // Tarif ("Kraftfahrtversicherung OPTIMAL-Tarif").
        if (preg_match('/Kraftfahrtversicherung\s+([\p{Lu}][\p{L}]{2,30})\s*-?\s*Tarif/u', $this->text(), $m)) {
            $raw['tariff'] = trim($m[1]);
        }

        if (($v = $this->labelValue('Versicherungsbeginn')) !== null
            && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $v, $m)) {
            $raw['start_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        if (($v = $this->labelValue('Versicherungsablauf')) !== null
            && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $v, $m)) {
            $raw['end_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        // Beitrag: der WIEDERKEHRENDE Folgebeitrag ist das, was der Kunde
        // zahlt. Fehlt die Beitragsseite, gilt der Jahresbeitrag.
        [$amount, $interval] = $this->premium();
        if ($amount !== null) {
            $raw['premium_amount'] = $amount;
            $raw['premium_interval'] = $interval;
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Folgebeitrag (mit Zahlungsperiode) oder ersatzweise der Jahresbeitrag.
     *
     * @return array{0: ?float, 1: ?string}
     */
    private function premium(): array
    {
        foreach ($this->lines as $i => $line) {
            if (!preg_match('/^\s*Folgebeitrag\b/u', $line)) {
                continue;
            }
            foreach ([$line, $this->lines[$i + 1] ?? ''] as $cand) {
                if (preg_match('/([\d.]+,\d{2})\s*EUR/u', $cand, $m)) {
                    return [(float) str_replace(['.', ','], ['', '.'], $m[1]), $this->interval()];
                }
            }
            break;
        }
        foreach ($this->lines as $i => $line) {
            if (!preg_match('/Jahresbeitrag für Vertrag/u', $line)) {
                continue;
            }
            foreach ([$line, $this->lines[$i - 1] ?? '', $this->lines[$i + 1] ?? ''] as $cand) {
                if (preg_match('/([\d.]+,\d{2})\s*EUR/u', $cand, $m)) {
                    return [(float) str_replace(['.', ','], ['', '.'], $m[1]), 'yearly'];
                }
            }
            break;
        }

        return [null, null];
    }

    /** Zahlungsperiode aus dem Kopf ("monatlich"/"jaehrlich"). */
    private function interval(): string
    {
        $text = $this->text();

        return match (true) {
            (bool) preg_match('/\bmonatlich\b/iu', $text)                      => 'monthly',
            (bool) preg_match('/viertelj[äa]hrlich/iu', $text)                 => 'quarterly',
            (bool) preg_match('/halbj[äa]hrlich/iu', $text)                    => 'semiannual',
            default                                                            => 'yearly',
        };
    }

    /** Fahrzeugart des Scheins auf die Werteliste des Vertrags abbilden. */
    private function mapVehicleType(string $value): ?string
    {
        $v = mb_strtolower($value);

        return match (true) {
            str_contains($v, 'personenkraftwagen') || str_contains($v, 'pkw') => 'pkw',
            str_contains($v, 'krad') || str_contains($v, 'motorrad')          => 'motorrad',
            str_contains($v, 'lkw') || str_contains($v, 'lastkraft')          => 'lkw',
            str_contains($v, 'anhänger') || str_contains($v, 'anhaenger')     => 'anhaenger',
            str_contains($v, 'wohnmobil')                                     => 'wohnmobil',
            default                                                           => null,
        };
    }

    /** Kurztext der Deckung fuer die Zusammenfassung. */
    private function coverageSummary(array $kfz): string
    {
        $parts = ['Haftpflicht'];
        if (!empty($kfz['has_vollkasko'])) {
            $parts[] = 'Vollkasko';
        } elseif (!empty($kfz['has_teilkasko'])) {
            $parts[] = 'Teilkasko';
        } elseif (isset($kfz['has_teilkasko'])) {
            $parts[] = 'keine Kasko';
        }

        return implode(', ', $parts);
    }

    /**
     * Text eines Abschnitts zwischen einer Ueberschrift und der naechsten -
     * so zaehlt nur, was WIRKLICH im Versicherungsumfang steht.
     *
     * @param list<string> $until
     */
    private function sectionText(string $heading, array $until): ?string
    {
        $from = null;
        foreach ($this->lines as $i => $line) {
            if ($from === null && preg_match('/^\s*' . preg_quote($heading, '/') . '\s*:?\s*$/u', $line)) {
                $from = $i;
                continue;
            }
            if ($from !== null) {
                foreach ($until as $stop) {
                    if (mb_stripos($line, $stop) !== false) {
                        return implode("\n", array_slice($this->lines, $from, $i - $from));
                    }
                }
            }
        }

        return $from !== null ? implode("\n", array_slice($this->lines, $from)) : null;
    }

    /**
     * Wert zu einer Beschriftung - mit Doppelpunkt, im Spaltenlayout ODER mit
     * nur EINEM Leerzeichen (Foto-OCR). Steht die Zeile allein, gilt die
     * naechste nicht-leere Zeile.
     */
    private function labelValue(string $label): ?string
    {
        $re = '/^\s*' . preg_quote($label, '/') . '\s*:?/u';
        foreach ($this->lines as $i => $line) {
            if (!preg_match($re, $line, $m)) {
                continue;
            }
            $rest = trim(mb_substr($line, mb_strlen($m[0])));
            if ($rest !== '') {
                return $rest;
            }
            for ($j = $i + 1; $j < count($this->lines); $j++) {
                $next = trim($this->lines[$j]);
                if ($next !== '') {
                    return $next;
                }
            }
            return null;
        }

        return null;
    }

    private function text(): string
    {
        return implode("\n", $this->lines);
    }
}
