<?php
namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\ReadsDocumentPages;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer den Strom-AUFTRAG der PLAN-B NET ZERO ENERGY GmbH
 * (Versorgerwechsel). Das Formular steht vollstaendig auf der ERSTEN Seite;
 * die Folgeseiten sind SEPA-Mandat, Beratungsbestaetigung, AGB und
 * Datenschutzhinweise (Betreiber-Vorgabe: nur die Formularseite zaehlt).
 *
 * Anders als beim LichtBlick-Auftrag stehen Beschriftung und Wert NEBEN-
 * einander, oft zweimal je Zeile ("Zaehlernummer*  1ISK...  Zaehlerstand").
 * Die Zeile wird daher in Spalten zerlegt; der Wert ist die Zelle NACH der
 * Beschriftung - ist die naechste Zelle selbst eine Beschriftung, war das Feld
 * leer und bleibt leer.
 *
 * Gelesen werden Kundendaten (Anrede, Name, Geburtsdatum, Telefon, E-Mail),
 * Verbrauchsstelle (Anschrift, Zaehlernummer, MaLo-ID, Jahresverbrauch),
 * bisheriger Lieferant samt Vertragsnummer dort, Tarif und die BRUTTO-Preise
 * (Arbeits-/Grundpreis) sowie - aus dem SEPA-Mandat - die Kunden-IBAN.
 *
 * Bewusst zurueckhaltend:
 * - Die Auftragsnummer wird NICHT als Vertragsnummer gefuehrt: ein Auftrag hat
 *   noch keine. Sie steht in der Zusammenfassung; die spaetere
 *   Vertragsbestaetigung bringt die echte Nummer und findet den Vertrag ueber
 *   MaLo-ID/Zaehlernummer.
 * - Die IBAN wird nur uebernommen, wenn der im Mandat genannte KONTOINHABER
 *   der Antragsteller ist - so landet weder ein fremdes Konto noch das des
 *   Versorgers in der Kundenakte.
 * - Ohne genanntes Lieferdatum wird KEINES geschaetzt (die 14-Tage-Regel gilt
 *   nur fuer Stadtwerke-Wechsel).
 */
class PlanBNetZeroAuftragParser implements DocumentTemplateParser
{
    use ReadsDocumentPages;
    use ValidatesExtractedFields;

    private const INSURER = 'PLAN-B NET ZERO ENERGY GmbH';

    /** @var list<string> Zeilen der Formularseite (Seite 1). */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $full = $text;
        // Nur das Auftragsformular selbst lesen (Seite 1).
        $text = $this->firstPage($text);
        $upper = mb_strtoupper($text);

        if ($this->looksLikeComparisonProtocol($text)) {
            return null;
        }
        if (!str_contains($upper, 'PLAN-B NET ZERO')
            || !preg_match('/AUFTRAG\s+(STROM|GAS)LIEFERUNG/u', $upper)) {
            return null;
        }

        $this->lines = preg_split('/\R/', $text) ?: [];

        $person = $this->parsePerson();
        $energie = $this->parseEnergy();
        $insurance = $this->parseInsurance($upper, $energie);
        $bank = $this->parseBank($full, $person);

        // Ohne belastbaren Kern der normalen Analyse/KI ueberlassen.
        if (($person['last_name'] ?? null) === null
            && ($energie['meter_number'] ?? null) === null && ($energie['malo_id'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $sparteLabel = ($insurance['sparte'] ?? 'strom') === 'gas' ? 'Gas' : 'Strom';
        $order = $this->orderNumber();

        return [
            'type' => 'energieauftrag',
            'confidence' => 76,
            'summary' => 'PLAN-B ' . $sparteLabel . '-Auftrag (Versorgerwechsel)'
                . ($name !== '' ? ' - ' . $name : '')
                . (isset($energie['previous_provider']) ? ' - Wechsel von ' . $energie['previous_provider'] : '')
                . (isset($energie['consumption_kwh'])
                    ? ' - ' . number_format($energie['consumption_kwh'], 0, ',', '.') . ' kWh/Jahr' : '')
                . ($order !== null ? ' - Auftragsnummer ' . $order : '')
                . ' - Lieferbeginn: zum naechstmoeglichen Termin (kein Datum im Auftrag)'
                . ' - Felder gratis aus dem Auftrag gelesen (ohne KI).',
            'title' => 'PLAN-B ' . $sparteLabel . '-Auftrag' . ($name !== '' ? ' ' . $name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $insurance,
                'kfz' => [],
                'gesundheit' => [],
                'bank' => $bank,
                'personen' => [],
                'energie' => $energie,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function parsePerson(): array
    {
        $raw = [];

        // Anrede-Ankreuzzeile ("4 Frau ☐ Herr ☐ Divers"): die Marke steht
        // direkt VOR der gewaehlten Option.
        foreach ($this->lines as $line) {
            if (preg_match('/\bFrau\b/u', $line) && preg_match('/\bHerr\b/u', $line) && preg_match('/\bDivers\b/u', $line)) {
                if (preg_match('/[4Xx]\s+Frau\b/u', $line)) {
                    $raw['gender'] = 'female';
                } elseif (preg_match('/[4Xx]\s+Herr\b/u', $line)) {
                    $raw['gender'] = 'male';
                }
                break;
            }
        }

        // "Vorname / Firma*" traegt bei Privatkunden den Vornamen, bei
        // Firmenkunden den Firmennamen (dann ist "Firma" angekreuzt).
        $vorname = $this->fieldValue('Vorname / Firma');
        $nachname = $this->fieldValue('Nachname / Ansprechpartner') ?? $this->fieldValue('Nachname / Firma');
        if ($vorname !== null && $this->looksLikeName($vorname)) {
            $raw['first_name'] = $vorname;
        }
        if ($nachname !== null && $this->looksLikeName($nachname)) {
            $raw['last_name'] = $nachname;
        }

        if (($v = $this->fieldValue('Geburtsdatum')) !== null
            && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $v, $m)) {
            $raw['birth_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        if (($v = $this->fieldValue('Telefon')) !== null) {
            $digits = (string) preg_replace('/[^\d]/', '', $v);
            if (preg_match('/^0\d{9,14}$/', $digits)) {
                $raw['phone'] = $digits;
            }
        }
        // E-Mail des Kunden - die Adresse des Versorgers steht ohne
        // Beschriftung im Briefkopf und wird dadurch nie erfasst.
        if (($v = $this->fieldValue('E-Mail')) !== null
            && preg_match('/^[\w.+\-]+@[\w.\-]+\.\w{2,}$/u', $v)
            && !str_contains(mb_strtolower($v), 'planbnetzero')) {
            $raw['email'] = mb_strtolower($v);
        }

        // Verbrauchsstelle = Anschrift des Kunden.
        if (($v = $this->fieldValue('Straße / Hausnummer')) !== null
            && preg_match('/^(.*\p{L}\.?)\s+(\d+\s*[a-zA-Z]?)$/u', $v, $m)) {
            $raw['street'] = trim($m[1]);
            $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $m[2]));
        }
        if (($v = $this->fieldValue('PLZ / Ort')) !== null
            && preg_match('/^(\d{5})\s+(.+)$/u', $v, $m)) {
            $raw['zip'] = $m[1];
            $raw['city'] = trim($m[2]);
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseEnergy(): array
    {
        $raw = [];

        if (($v = $this->fieldValue('Zählernummer')) !== null && preg_match('/^[A-Z0-9]{6,20}$/i', $v)) {
            $raw['meter_number'] = strtoupper($v);
        }
        if (($v = $this->fieldValue('Marktlokation')) !== null && preg_match('/\b(\d{11})\b/', $v, $m)) {
            $raw['malo_id'] = $m[1];
        }
        if (($v = $this->fieldValue('Jahresverbrauch in kWh')) !== null
            && preg_match('/^([\d.]+)$/', $v, $m)) {
            $raw['consumption_kwh'] = (int) str_replace('.', '', $m[1]);
        }
        // Zaehlerstand nur, wenn der Kunde ihn eingetragen hat.
        if (($v = $this->fieldValue('Zählerstand')) !== null && preg_match('/^[\d.]+(?:,\d+)?$/', $v)) {
            $raw['meter_reading'] = (float) str_replace(['.', ','], ['', '.'], $v);
        }

        // Bisheriger Lieferant + die dortige Vertragsnummer (im System die
        // "Kundennummer beim Vorversorger" - sie identifiziert den Altvertrag).
        if (($v = $this->fieldValue('derzeitiger Lieferant')) !== null
            && preg_match('/\p{L}{3,}/u', $v) && mb_strlen($v) <= 100) {
            $raw['previous_provider'] = $v;
        }
        if (($v = $this->fieldValue('derzeitige Vertragsnummer')) !== null
            && preg_match('/^[A-Z0-9\-\/]{4,}$/i', $v)) {
            $raw['previous_customer_number'] = $v;
        }

        // Tarifzeile: "<Tarif>  netto-AP  brutto-AP  netto-GP  brutto-GP".
        // Uebernommen werden die BRUTTO-Preise (so zahlt der Kunde).
        foreach ($this->lines as $line) {
            $cells = $this->cells($line);
            if (count($cells) < 5 || !$this->looksLikeName($cells[0])) {
                continue;
            }
            $nums = array_slice($cells, 1, 4);
            if (count(array_filter($nums, fn ($c) => (bool) preg_match('/^\d{1,4},\d{2}$/', $c))) !== 4) {
                continue;
            }
            $raw['tariff'] = $cells[0];
            $raw['working_price'] = (float) str_replace(',', '.', $nums[1]);
            $raw['base_price'] = (float) str_replace(',', '.', $nums[3]);
            break;
        }

        return $this->validatedEnergy(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * @param array<string,mixed> $energie
     * @return array<string,mixed>
     */
    private function parseInsurance(string $upper, array $energie): array
    {
        $raw = [
            'insurer' => self::INSURER,
            'sparte' => str_contains($upper, 'AUFTRAG GASLIEFERUNG') ? 'gas' : 'strom',
            // Auftrag = Beauftragung des Kunden, noch keine Bestaetigung.
            'document_stage' => Contract::STAGE_ANTRAG,
            'tariff' => $energie['tariff'] ?? null,
        ];

        // Lieferbeginn nur, wenn der Kunde ein Datum eingetragen hat. Das
        // Formular bietet alternativ "zum naechstmoeglichen Termin" - dann
        // bleibt der Beginn offen (kein erfundenes Datum, keine Schaetzung:
        // die 14-Tage-Regel gilt nur fuer Stadtwerke-Wechsel).
        if (($v = $this->fieldValue('Datum')) !== null
            && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $v, $m)) {
            $raw['start_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Kunden-IBAN aus dem SEPA-Lastschriftmandat - aber NUR, wenn der dort
     * genannte Kontoinhaber der Antragsteller ist. Damit kann weder das Konto
     * des Versorgers (Zahlungsempfaenger) noch ein fremdes Konto in die
     * Kundenakte geraten.
     *
     * @param array<string,mixed> $person
     * @return array<string,mixed>
     */
    private function parseBank(string $full, array $person): array
    {
        $last = $person['last_name'] ?? null;
        if ($last === null || !preg_match('/SEPA-Lastschriftmandat(.*)$/su', $full, $block)) {
            return [];
        }

        $holder = null;
        foreach (preg_split('/\R/', $block[1]) ?: [] as $line) {
            $cells = $this->cells($line);
            if (count($cells) >= 2 && preg_match('/^Kontoinhaber\b/u', $cells[0])) {
                $holder = $cells[1];
                break;
            }
        }
        if ($holder === null || mb_stripos($holder, $last) === false) {
            return [];
        }

        $raw = ['account_holder' => $holder];
        if (preg_match('/\bDE\d{2}(?:[ ]?\d){18}\b/', $block[1], $m)) {
            $raw['iban'] = strtoupper((string) preg_replace('/\s+/', '', $m[0]));
        }

        return $this->validatedBank($raw);
    }

    /**
     * Auftragsnummer aus dem Kopf ("1660174   10009798   02.08.2026"): die
     * erste Zelle. Sie ist KEINE Vertragsnummer (ein Auftrag hat noch keine)
     * und dient nur der Zusammenfassung.
     */
    private function orderNumber(): ?string
    {
        foreach (array_slice($this->lines, 0, 5) as $line) {
            $cells = $this->cells($line);
            if (count($cells) >= 2 && preg_match('/^\d{6,10}$/', $cells[0])) {
                return $cells[0];
            }
        }
        return null;
    }

    /**
     * Wert NEBEN einer Beschriftung. Die Zeile wird in Spalten zerlegt; der
     * Wert ist die Zelle nach der Beschriftung. Ist diese Zelle selbst eine
     * Beschriftung (Pflichtfeld-Stern oder bekannter Feldname), war das Feld
     * leer - dann null statt eines fremden Wertes.
     */
    private function fieldValue(string $label): ?string
    {
        foreach ($this->lines as $line) {
            $cells = $this->cells($line);
            foreach ($cells as $i => $cell) {
                if ($this->normalizeLabel($cell) !== mb_strtolower($label)) {
                    continue;
                }
                $value = $cells[$i + 1] ?? null;
                if ($value === null || $this->looksLikeLabel($value)) {
                    return null;
                }
                return $value;
            }
        }
        return null;
    }

    /**
     * Beschriftung auf ihren Kern bringen: Pflichtfeld-Stern und erlaeuternde
     * Klammer entfallen ("Marktlokation (MaLo-ID)" -> "marktlokation").
     */
    private function normalizeLabel(string $cell): string
    {
        $cell = rtrim(trim($cell), '*');
        $cell = (string) preg_replace('/\s*\(.*$/u', '', $cell);
        return mb_strtolower(trim($cell));
    }

    /** Zeile in Spalten zerlegen (zwei oder mehr Leerzeichen trennen). */
    private function cells(string $line): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\s{2,}/', trim($line)) ?: []),
            fn ($c) => $c !== ''
        ));
    }

    /**
     * Ist die Zelle eine Beschriftung statt eines Wertes? Pflichtfelder tragen
     * einen Stern; leere Felder lassen sonst die naechste Beschriftung oder
     * ein Ankreuzkaestchen folgen.
     */
    private function looksLikeLabel(string $cell): bool
    {
        return str_ends_with($cell, '*')
            || preg_match('/^[☐☑✕✓]/u', $cell) === 1
            || preg_match('/^(Zählerstand|Ablesedatum|Zählernummer|Marktlokation|PLZ \/ Ort|Straße \/|Nachname|Vorname|Telefon|E-Mail|Geburtsdatum|Unterschrift|Datum|Ort)\b/u', $cell) === 1;
    }

    /** Grobe Plausibilitaet fuer Namen/Bezeichnungen (kein Datum, keine Zahl). */
    private function looksLikeName(string $value): bool
    {
        return (bool) preg_match('/\p{L}{2,}/u', $value)
            && !preg_match('/^\d/', $value)
            && mb_strlen($value) <= 80;
    }
}
