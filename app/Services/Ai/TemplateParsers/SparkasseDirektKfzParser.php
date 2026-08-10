<?php
namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\ReadsDocumentPages;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer das Kfz-ANGEBOT / den ANTRAG der Sparkassen
 * DirektVersicherung AG ("Unser Angebot fuer Sie", Antrag auf Kfz-Versicherung,
 * meist als Tarifumstellung eines bestehenden Vertrags). Der Aufbau ist ueber
 * alle Kunden hinweg gleich: Beschriftung links, Wert in der rechten Spalte.
 *
 * Gelesen werden Antragsteller (Anrede, Name, Anschrift, Geburtsdatum, Telefon,
 * E-Mail), Fahrzeug (Art, HSN/TSN, Hersteller, Typ, Kennzeichen, Leistung,
 * Fahrleistung), Deckung (Haftpflicht-Tarif, Teil-/Vollkasko mit
 * Selbstbeteiligung, Werkstattbindung, Schutzbrief, SF-Klasse) sowie Vertrag
 * (Gesellschaft, Versicherungsnummer, Beginn/Ablauf, Zahlweise, Gesamtbeitrag).
 *
 * Bewusst NICHT uebernommen:
 * - die EMPFEHLUNG "FahrerSchutzPlus" - sie ist ein Vorschlag, kein gewaehlter
 *   Baustein; ihr Preis darf den Gesamtbeitrag nicht verfaelschen.
 * - Erstzulassung und Erwerbsdatum: das Angebot nennt nur Monat/Jahr
 *   ("01.2004"). Ein Tag waere erfunden - die Angabe steht dafuer in der
 *   Zusammenfassung, damit der Mitarbeiter sie sehen und pflegen kann.
 * - Anschrift/E-Mail des Versicherers (Service-Center) und dessen
 *   Glaeubiger-ID; das SEPA-Mandat ist ein LEERES Formularfeld, es gibt keine
 *   Kunden-IBAN im Dokument.
 *
 * Stufe 'antrag': das Angebot ist noch nicht angenommen (Unterschrift fehlt).
 * Die spaetere Nachtragsbestaetigung ersetzt die Angaben feldgenau ueber die
 * Version History; die Versicherungsnummer fuehrt beide zusammen.
 */
class SparkasseDirektKfzParser implements DocumentTemplateParser
{
    use ReadsDocumentPages;
    use ValidatesExtractedFields;

    private const INSURER = 'Sparkassen DirektVersicherung AG';

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);

        $upper = mb_strtoupper($text);
        if ($this->looksLikeComparisonProtocol($text)) {
            return null;
        }
        // Der NAFI-Maklerantrag traegt die Ueberschrift "Antrag
        // Kraftfahrtversicherung" und nennt die Gesellschaft nur als Feld -
        // er darf nicht mit dem hiesigen Layout der Sparkassen
        // DirektVersicherung gelesen werden (eigener NAFI-Parser).
        if (preg_match('/ANTRAG\s+KRAFTFAHRTVERSICHERUNG/u', $upper)) {
            return null;
        }
        // Nur die Unterlagen der Sparkassen DirektVersicherung zur
        // Kfz-Versicherung (Angebot/Antrag).
        if (!str_contains($upper, 'SPARKASSEN DIREKTVERSICHERUNG')
            || !str_contains($upper, 'KFZ-VERSICHERUNG')) {
            return null;
        }

        $this->lines = preg_split('/\R/', $text) ?: [];

        $person = $this->parsePerson();
        $vehicle = $this->parseVehicle();
        $insurance = $this->parseInsurance();

        // Ohne belastbaren Kern (Versicherungsnummer oder Kennzeichen) lieber
        // der normalen Analyse ueberlassen.
        if (($insurance['contract_number'] ?? null) === null && ($vehicle['license_plate'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));

        return [
            'type' => 'kfz_vertrag',
            'confidence' => 76,
            'summary' => 'Sparkassen DirektVersicherung - Kfz-Angebot/Antrag'
                . ($this->labelValue('Antrag auf Kfz-Versicherung') !== null || $this->hasLine('Tarifumstellung')
                    ? ' (Tarifumstellung)' : '')
                . ($name !== '' ? ' - ' . $name : '')
                . (isset($vehicle['license_plate']) ? ' - ' . $vehicle['license_plate'] : '')
                . (isset($insurance['contract_number']) ? ' - Vertrag ' . $insurance['contract_number'] : '')
                . (isset($vehicle['sf_liability_class']) ? ' - SF ' . $vehicle['sf_liability_class'] : '')
                . ' - Deckung: ' . $this->coverageSummary($vehicle)
                . (isset($insurance['premium_amount'])
                    ? ' - Beitrag ' . number_format($insurance['premium_amount'], 2, ',', '.') . ' EUR'
                        . ($insurance['premium_interval'] === 'semiannual' ? ' halbjaehrlich' : '')
                    : '')
                . $this->manualHints()
                . ' - Felder gratis aus dem Angebot gelesen (ohne KI).',
            'title' => 'Sparkassen DirektVersicherung Kfz' . ($name !== '' ? ' ' . $name : ''),
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
     * Antragsteller-Block: die Anrede steht in der Wertspalte, darunter Name,
     * Strasse und "PLZ Ort" - jeweils in derselben Spalte.
     *
     * @return array<string,mixed>
     */
    private function parsePerson(): array
    {
        $raw = [];

        $block = $this->valueBlock('Antragsteller', 5);
        foreach ($block as $value) {
            if ($raw === [] && preg_match('/^(Herrn?|Frau)$/u', $value, $m)) {
                $raw['gender'] = mb_strtolower($m[1]) === 'frau' ? 'female' : 'male';
                continue;
            }
            if (!isset($raw['last_name'])
                && preg_match('/^[A-ZÄÖÜ][\p{L}\-]+(?:\s+[A-ZÄÖÜ][\p{L}\-]+)+$/u', $value)) {
                $parts = preg_split('/\s+/', $value) ?: [];
                $raw['last_name'] = array_pop($parts);
                $raw['first_name'] = implode(' ', $parts) ?: null;
                continue;
            }
            if (!isset($raw['zip']) && preg_match('/^(\d{5})\s+(.+)$/u', $value, $m)) {
                $raw['zip'] = $m[1];
                $raw['city'] = trim($m[2]);
                continue;
            }
            // Strasse + Hausnummer ("Friedhofstr. 2 b" - der Strassenname darf
            // auf einen Punkt enden).
            if (!isset($raw['street'])
                && preg_match('/^(.*\p{L}\.?)\s+(\d+\s*[a-zA-Z]?)$/u', $value, $m)
                && preg_match('/\p{L}{3,}/u', $m[1])) {
                $raw['street'] = trim($m[1]);
                $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $m[2]));
            }
        }

        if (($v = $this->labelValue('Geburtsdatum')) !== null
            && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', trim($v), $m)) {
            $raw['birth_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        if (($v = $this->labelValue('Telefon')) !== null) {
            $digits = (string) preg_replace('/[^\d]/', '', $v);
            if (preg_match('/^0\d{9,14}$/', $digits)) {
                $raw['phone'] = $digits;
            }
        }
        // E-Mail nur aus der Antragsteller-Zeile - die Adressen des
        // Service-Centers (service@/vertrag@sparkassen-direkt.de) stehen im
        // Briefkopf und gehoeren NICHT zum Kunden.
        if (($v = $this->labelValue('E-Mail')) !== null
            && preg_match('/[\w.+\-]+@[\w.\-]+\.\w{2,}/u', $v, $m)
            && !str_contains(mb_strtolower($m[0]), 'sparkassen-direkt.de')) {
            $raw['email'] = mb_strtolower($m[0]);
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseVehicle(): array
    {
        $raw = [];

        if (($v = $this->labelValue('Amtliches Kennzeichen')) !== null) {
            $raw['license_plate'] = $v;
        }
        if (($v = $this->labelValueFirstColumn('Hersteller')) !== null && !str_contains($v, '/')) {
            $raw['manufacturer'] = $v;
        }
        // "Hersteller-/Typ-Nummer   0710 / 916" (Felder 2.1 und 2.2 der
        // Zulassungsbescheinigung).
        if (($v = $this->labelValue('Hersteller-/Typ-Nummer')) !== null
            && preg_match('#^(\d{4})\s*/\s*([A-Z0-9]{3})#i', trim($v), $m)) {
            $raw['hsn'] = $m[1];
            $raw['tsn'] = strtoupper($m[2]);
        }
        if (($v = $this->labelValueFirstColumn('Typ')) !== null && mb_strlen($v) <= 80) {
            $raw['model'] = $v;
        }
        // "Staerke in kW   90,0 kW" - Nachkommastellen entfallen (die Spalte
        // im Vertrag ist ganzzahlig, 90,0 kW = 90 kW).
        if (($v = $this->labelValueFirstColumn('Stärke in kW')) !== null
            && preg_match('/^([\d.]+)(?:,\d+)?\s*kW/iu', $v, $m)) {
            $raw['power_kw'] = (int) str_replace('.', '', $m[1]);
        }
        if (($v = $this->labelValueFirstColumn('Jährliche Fahrleistung')) !== null
            && preg_match('/([\d.]+)\s*km/iu', $v, $m)) {
            $raw['annual_mileage'] = (int) str_replace('.', '', $m[1]);
        }
        if (($v = $this->labelValueFirstColumn('Schadenfreiheitsklasse')) !== null) {
            $raw['sf_liability_class'] = $v;
        }

        // Deckung: Teil-/Vollkasko haben je einen eigenen Abschnitt mit Tarif.
        // "Wuenschen Sie keine Kasko ..." ist Fliesstext und zaehlt nicht.
        $hasVoll = $this->labelValue('Vollkasko') !== null;
        $hasTeil = $hasVoll || $this->labelValue('Teilkasko') !== null;
        $raw['has_vollkasko'] = $hasVoll;
        $raw['has_teilkasko'] = $hasTeil;
        if (($v = $this->labelValue('Vollkasko-Selbstbeteiligung')) !== null
            && preg_match('/([\d.]+)\s*EUR/iu', $v, $m)) {
            $raw['vollkasko_deductible'] = (int) str_replace('.', '', $m[1]);
        }
        if (($v = $this->labelValue('Teilkasko-Selbstbeteiligung')) !== null
            && preg_match('/([\d.]+)\s*EUR/iu', $v, $m)) {
            $raw['teilkasko_deductible'] = (int) str_replace('.', '', $m[1]);
        }

        // Zusatzbausteine: "AutoSchutzbrief / Rabattschutz   Nein / Nein",
        // "Werkstattservice / Rabattschutz   Ja / Nein" - nur was mit "Ja"
        // gewaehlt wurde.
        $extras = [];
        if ($this->pairYes('AutoSchutzbrief / Rabattschutz', 0)) {
            $extras[] = 'schutzbrief';
        }
        if ($this->pairYes('AutoSchutzbrief / Rabattschutz', 1)
            || $this->pairYes('Werkstattservice / Rabattschutz', 1)) {
            $extras[] = 'rabattschutz';
        }
        if ($this->pairYes('Werkstattservice / Rabattschutz', 0)) {
            $extras[] = 'werkstattbindung';
        }
        if ($extras !== []) {
            $raw['extras'] = array_values(array_unique($extras));
        }

        // Halter: der Block "Fahrzeughalter" nennt denselben Namen wie der
        // Antragsteller -> Halter ist der Versicherungsnehmer.
        $halter = $this->valueBlock('Fahrzeughalter', 4);
        $antrag = $this->valueBlock('Antragsteller', 5);
        $clean = static fn (array $b) => array_values(array_filter(
            array_map(static fn ($l) => preg_replace('/^(Herrn?|Frau)$/u', '', $l), $b),
            static fn ($l) => $l !== ''
        ));
        if ($halter !== [] && $clean($halter) === array_slice($clean($antrag), 0, count($clean($halter)))) {
            $raw['holder_type'] = 'versicherungsnehmer';
        }

        return $this->validatedVehicle($raw);
    }

    /** @return array<string,mixed> */
    private function parseInsurance(): array
    {
        $raw = [
            'sparte' => 'kfz',
            'insurer' => self::INSURER,
            // Angebot/Antrag - noch nicht angenommen (Unterschrift fehlt).
            'document_stage' => Contract::STAGE_ANTRAG,
        ];

        // Versicherungsnummer: "Versicherungsnummer  30003380161-4" bzw. aus
        // dem Antragskopf "Antrag auf Kfz-Versicherung 30003380161-4".
        $number = $this->labelValue('Versicherungsnummer');
        if ($number === null) {
            foreach ($this->lines as $line) {
                if (preg_match('/Antrag auf Kfz-Versicherung\s+([\d\-]{6,})/u', $line, $m)) {
                    $number = $m[1];
                    break;
                }
            }
        }
        if ($number !== null && preg_match('/^[\d\-]{6,}$/', trim($number))) {
            $raw['contract_number'] = trim($number);
        }

        // Tarif der Haftpflicht ("Kfz-Haftpflichtversicherung   AutoBasis").
        if (($v = $this->labelValue('Kfz-Haftpflichtversicherung')) !== null
            && preg_match('/^Auto\p{L}+$/u', trim($v))) {
            $raw['tariff'] = trim($v);
        }

        // Beginn ("19.09.2026, 00:00 Uhr").
        if (($v = $this->labelValue('Versicherungsbeginn')) !== null
            && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $v, $m)) {
            $raw['start_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
            // Ablauf steht als Regel statt als Datum ("Versicherungsbeginn plus
            // ein Jahr") - das ist eine Angabe des Dokuments, kein Schaetzwert.
            $ablauf = $this->labelValue('Versicherungsablauf');
            if ($ablauf !== null && preg_match('/plus ein Jahr/ui', $ablauf)) {
                $raw['end_date'] = date('Y-m-d', strtotime($raw['start_date'] . ' +1 year'));
            } elseif ($ablauf !== null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $ablauf, $m2)) {
                $raw['end_date'] = $m2[3] . '-' . $m2[2] . '-' . $m2[1];
            }
        }

        // Gesamtbeitrag gemaess Zahlweise - NICHT die Einzelposten und NICHT
        // die Empfehlung "FahrerSchutzPlus" (nicht gewaehlt).
        if (($v = $this->labelValue('Gesamtbeitrag')) !== null
            && preg_match('/([\d.]+,\d{2})\s*EUR/u', $v, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = $this->interval();
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** Zahlweise ("1/2 jaehrlich" = halbjaehrlich). */
    private function interval(): ?string
    {
        $v = mb_strtolower((string) $this->labelValue('Zahlweise'));
        return match (true) {
            $v === '' => null,
            str_contains($v, 'monat')                                     => 'monthly',
            str_contains($v, '1/4') || str_contains($v, 'viertel')        => 'quarterly',
            str_contains($v, '1/2') || str_contains($v, 'halb')           => 'semiannual',
            str_contains($v, 'jähr') || str_contains($v, 'jaehr')         => 'yearly',
            default                                                       => null,
        };
    }

    /**
     * Monatsgenaue Angaben, die bewusst nicht als Datum gespeichert werden
     * (ein Tag waere erfunden) - sie gehoeren aber in die Zusammenfassung.
     */
    private function manualHints(): string
    {
        $out = '';
        foreach (['Erstzulassung' => 'Erstzulassung', 'Erwerb des Fahrzeugs' => 'Erwerb'] as $label => $text) {
            $v = $this->labelValueFirstColumn($label);
            if ($v !== null && preg_match('/^\d{2}\.\d{4}$/', $v)) {
                $out .= ' - ' . $text . ' ' . $v;
            }
        }
        if (($v = $this->labelValueFirstColumn('Zweitwagen')) !== null && preg_match('/^ja$/iu', $v)) {
            $out .= ' - Zweitwagen';
        }
        return $out;
    }

    /** Kurztext der Deckung fuer die Zusammenfassung. */
    private function coverageSummary(array $kfz): string
    {
        $parts = ['Haftpflicht'];
        if (!empty($kfz['has_vollkasko'])) {
            $parts[] = 'Vollkasko';
        } elseif (!empty($kfz['has_teilkasko'])) {
            $parts[] = 'Teilkasko';
        } else {
            $parts[] = 'keine Kasko';
        }
        return implode(', ', $parts);
    }

    /**
     * Wert einer "Ja / Nein"-Doppelzeile: $index 0 = linke, 1 = rechte Option
     * ("AutoSchutzbrief / Rabattschutz   Nein / Nein").
     */
    private function pairYes(string $label, int $index): bool
    {
        $v = $this->labelValue($label);
        if ($v === null) {
            return false;
        }
        $parts = array_map('trim', explode('/', $v));
        return isset($parts[$index]) && preg_match('/^ja$/iu', $parts[$index]) === 1;
    }

    /**
     * Wert rechts neben der Beschriftung (Spaltenlayout: Label, mind. zwei
     * Leerzeichen, Wert).
     */
    private function labelValue(string $label): ?string
    {
        foreach ($this->lines as $line) {
            if (preg_match('/^\s*' . preg_quote($label, '/') . '\s{2,}([^\n]+?)\s*$/u', $line, $m)) {
                $value = trim($m[1]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return null;
    }

    /**
     * Wie labelValue, aber nur die erste Wertspalte. Manche Zeilen tragen ganz
     * rechts noch einen Verweis auf die Zulassungsbescheinigung
     * ("Erstzulassung   01.2004      Feld B") - der gehoert nicht zum Wert.
     */
    private function labelValueFirstColumn(string $label): ?string
    {
        $value = $this->labelValue($label);
        if ($value === null) {
            return null;
        }
        $first = trim((string) (preg_split('/\s{2,}/', $value)[0] ?? ''));
        return $first !== '' ? $first : null;
    }

    /**
     * Mehrzeiliger Wert-Block rechts neben einer Beschriftung (Anrede, Name,
     * Strasse, PLZ/Ort). Die Folgezeilen tragen kein Label mehr, stehen aber in
     * derselben Spalte - der Block endet an der naechsten beschrifteten Zeile
     * oder einer Leerzeile.
     *
     * @return list<string>
     */
    private function valueBlock(string $label, int $max): array
    {
        foreach ($this->lines as $i => $line) {
            if (!preg_match('/^(\s*)' . preg_quote($label, '/') . '\s{2,}([^\n]+?)\s*$/u', $line, $m)) {
                continue;
            }
            $column = mb_strpos($line, trim($m[2]));
            $block = [trim($m[2])];
            for ($j = $i + 1; $j < count($this->lines) && count($block) < $max; $j++) {
                $next = $this->lines[$j];
                if (trim($next) === '') {
                    break;
                }
                // Nur Zeilen, die in derselben Wertspalte beginnen (die
                // Beschriftungsspalte ist leer).
                $indent = mb_strlen($next) - mb_strlen(ltrim($next));
                if ($column === false || abs($indent - $column) > 2) {
                    break;
                }
                $block[] = trim($next);
            }
            return $block;
        }
        return [];
    }

    private function hasLine(string $needle): bool
    {
        foreach ($this->lines as $line) {
            if (trim($line) === $needle) {
                return true;
            }
        }
        return false;
    }
}
