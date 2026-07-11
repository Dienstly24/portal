<?php
namespace App\Services\Commission;

/**
 * Deterministischer Parser für Provisionsgutschriften im Text
 * (Architekturplan Abschnitt 10: Gutschrift-Nummer, Betrag, Datum).
 * Gleiches Prinzip wie der FondsFinanzParser: "Label: Wert"-Zeilen,
 * defensives Scheitern bei unbekanntem Layout. PDF-Anhänge werden in
 * einer Ausbaustufe über Textextraktion an denselben Parser gegeben.
 */
class CommissionStatementParser
{
    /** @var array<string, string[]> */
    private const LABELS = [
        'credit_note_number' => ['gutschrift-nr', 'gutschriftnummer', 'gutschrift nr', 'abrechnungsnummer', 'abrechnungs-nr', 'belegnummer', 'beleg-nr'],
        'amount' => ['betrag', 'gutschriftbetrag', 'auszahlungsbetrag', 'summe', 'gesamtbetrag'],
        'date' => ['datum', 'abrechnungsdatum', 'gutschriftdatum', 'belegdatum'],
    ];

    /** @return array{credit_note_number: ?string, amount: ?float, date: ?string} */
    public function parse(string $text): array
    {
        $fields = ['credit_note_number' => null, 'amount' => null, 'date' => null];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $rawLine) {
            if (!preg_match('/^\s*([^:]{2,40}?)\s*:\s*(.+?)\s*$/u', $rawLine, $m)) {
                continue;
            }
            $label = mb_strtolower(trim($m[1], " \t.*-"));
            $value = trim($m[2]);
            if ($value === '') {
                continue;
            }

            foreach (self::LABELS as $field => $variants) {
                if ($fields[$field] === null && in_array($label, $variants, true)) {
                    $fields[$field] = match ($field) {
                        'amount' => $this->parseAmount($value),
                        'date' => $this->parseDate($value),
                        default => $value,
                    };
                    break;
                }
            }
        }

        return $fields;
    }

    /** "1.234,56 €" / "1234.56 EUR" -> 1234.56; Unlesbares wird verworfen statt geraten. */
    private function parseAmount(string $value): ?float
    {
        $value = preg_replace('/[^\d.,\-]/', '', $value) ?? '';
        if ($value === '') {
            return null;
        }

        // Deutsches Format: Punkt = Tausender, Komma = Dezimal
        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function parseDate(string $value): ?string
    {
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/\d{4}-\d{2}-\d{2}/', $value, $m)) {
            return $m[0];
        }
        return null;
    }
}
