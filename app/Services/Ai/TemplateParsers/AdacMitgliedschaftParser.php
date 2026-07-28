<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer die ADAC-Mitgliedschaft (Screenshot "Meine Mitgliedschaft"
 * aus dem ADAC-Portal oder Mitgliedsbestaetigung). Liest Tarif, Jahresbeitrag,
 * Mitgliedsnummer und Mitgliedsnamen per fester Regel aus der OCR-Textebene
 * (kein KI-Aufruf).
 *
 * Die Mitgliedschafts-STUFE wird intelligent bestimmt (Betreiber-Vorgabe):
 * steht sie im Tarifnamen ("Plus"/"Premium"), gilt der Name; sonst verraet der
 * Jahresbeitrag die Stufe (54 EUR = Basis, 94 EUR = Plus, 139 EUR = Premium).
 * Andere Betraege werden NICHT geraten - die Stufe bleibt dann leer.
 *
 * Ergebnis: Sparte "schutzbrief" (Schutzbrief/Mobilclub), Stufe als subtype,
 * Mitgliedsnummer als Vertragsnummer (contract_number - die richtige Spalte
 * am Vertrag). Typ "versicherungsvertrag" (Neugeschaeft): das Dokument bleibt
 * im Dokumenten-Eingang, damit der Mitarbeiter den Vertrag anlegt. Alle Werte
 * durchlaufen die harte Feldvalidierung.
 */
class AdacMitgliedschaftParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /** Jahresbeitrag (EUR) -> Mitgliedschafts-Stufe (Betreiber-Vorgabe). */
    private const TIER_BY_AMOUNT = [
        54 => 'basis',
        94 => 'plus',
        139 => 'premium',
    ];

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $upper = mb_strtoupper($text);
        // Nur die ADAC-Mitgliedschaft selbst - NICHT die ADAC-Autoversicherung
        // (eigener Parser) und kein CHECK24-Protokoll.
        if (str_contains($upper, 'CHECK24') || str_contains($upper, 'BERATUNGSPROTOKOLL')
            || str_contains($upper, 'AUTOVERSICHERUNG')) {
            return null;
        }
        if (!str_contains($upper, 'ADAC') || !str_contains($upper, 'MITGLIED')) {
            return null;
        }

        $this->lines = preg_split('/\R/', $text) ?: [];

        // Mitgliedsnummer (7-10 Ziffern) - der Kern des Dokuments.
        $memberNo = null;
        if (($v = $this->labelValue('Mitgliedsnummer')) !== null && preg_match('/\d{6,10}/', $v, $m)) {
            $memberNo = $m[0];
        }
        if ($memberNo === null) {
            return null; // ohne Mitgliedsnummer der normalen Analyse ueberlassen
        }

        // Tarifname ("ADAC Mitgliedschaft" / "ADAC Plus-Mitgliedschaft" ...).
        $tariff = $this->labelValue('Tarif');
        if ($tariff === null && preg_match('/(ADAC[^\r\n]*Mitgliedschaft)/u', $text, $m)) {
            $tariff = trim($m[1]);
        }

        // Jahresbeitrag ("54,00 €" / "54 €").
        $amount = null;
        if (($v = $this->labelValue('Jahresbeitrag')) !== null
            && preg_match('/(\d{1,4}(?:,\d{2})?)\s*€?/u', $v, $m)) {
            $amount = (float) str_replace(',', '.', $m[1]);
        }

        // Stufe: Tarifname zuerst (eindeutig), sonst ueber den Jahresbeitrag.
        $subtype = $this->tier($tariff, $amount);

        // Mitglied (Name) - fuer die Kunden-Zuordnung.
        $person = [];
        $member = $this->labelValue('Mitglied');
        if ($member !== null && preg_match('/^[A-ZÄÖÜ][\p{L}\-]+(?:\s+[A-ZÄÖÜ][\p{L}\-]+)+$/u', $member)) {
            $parts = preg_split('/\s+/', $member) ?: [];
            $person['last_name'] = array_pop($parts);
            $person['first_name'] = implode(' ', $parts) ?: null;
        }
        $person = $this->validatedPerson(array_filter($person, fn ($v) => $v !== null && $v !== ''));

        $insurance = $this->validatedInsurance(array_filter([
            'insurer' => 'ADAC',
            'sparte' => 'schutzbrief',
            'subtype' => $subtype,
            'contract_number' => $memberNo,
            'tariff' => $tariff,
            'premium_amount' => $amount,
            'premium_interval' => $amount !== null ? 'yearly' : null,
        ], fn ($v) => $v !== null && $v !== ''));

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $tierLabel = $subtype !== null
            ? (\App\Models\Contract::SUBTYPES['schutzbrief'][$subtype] ?? $subtype)
            : null;
        return [
            'type' => 'versicherungsvertrag',
            'confidence' => 74,
            'summary' => 'ADAC-Mitgliedschaft'
                . ($tierLabel !== null ? ' (' . $tierLabel . ')' : '')
                . ($name !== '' ? ' - ' . $name : '')
                . ' - Mitgliedsnummer ' . $memberNo
                . ($amount !== null ? ' - Jahresbeitrag ' . number_format($amount, 2, ',', '.') . ' EUR' : '')
                . ' - Felder gratis gelesen (ohne KI).',
            'title' => 'ADAC Mitgliedschaft' . ($name !== '' ? ' ' . $name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $insurance,
                'kfz' => [],
                'gesundheit' => [],
                'bank' => [],
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /**
     * Mitgliedschafts-Stufe: aus dem Tarifnamen (Plus/Premium eindeutig),
     * sonst ueber den bekannten Jahresbeitrag (54/94/139 EUR). Unbekannte
     * Betraege ergeben KEINE Stufe (nicht raten - Preise koennen sich aendern).
     */
    private function tier(?string $tariff, ?float $amount): ?string
    {
        $t = mb_strtolower((string) $tariff);
        if (str_contains($t, 'premium')) {
            return 'premium';
        }
        if (str_contains($t, 'plus')) {
            return 'plus';
        }
        if ($amount !== null && (float) (int) $amount === $amount
            && isset(self::TIER_BY_AMOUNT[(int) $amount])) {
            return self::TIER_BY_AMOUNT[(int) $amount];
        }
        // Klarer "Basis"-Namenszusatz (selten ausgeschrieben).
        if (str_contains($t, 'basis')) {
            return 'basis';
        }
        return null;
    }

    /** Wert neben einem Label (Spaltenlayout "Label   Wert" oder "Label: Wert"). */
    private function labelValue(string $label): ?string
    {
        foreach ($this->lines as $line) {
            if (preg_match('/^\s*' . preg_quote($label, '/') . '\s*:?\s{1,}(\S.*?)\s*$/u', $line, $m)) {
                return trim($m[1]);
            }
        }
        return null;
    }
}
