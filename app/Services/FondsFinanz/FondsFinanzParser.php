<?php
namespace App\Services\FondsFinanz;

/**
 * Deterministischer Parser für strukturierte Fonds-Finanz-Mitteilungen
 * (Architekturplan Abschnitt 8: bewusst ein Parser für das bekannte
 * Format mit "Label: Wert"-Zeilen, KEINE generische KI-Texterkennung -
 * zuverlässiger und leichter zu testen). Unbekannte Layouts liefern
 * schlicht leere Felder; der Import-Service erzeugt dann eine manuelle
 * Prüfaufgabe statt falscher Daten.
 */
class FondsFinanzParser
{
    /** @var array<string, string[]> Feld => akzeptierte Label-Varianten (lowercase) */
    private const LABELS = [
        'customer_name' => ['kunde', 'kundenname', 'versicherungsnehmer'],
        'birth_date' => ['geburtsdatum', 'geb'],
        'company' => ['gesellschaft', 'versicherer'],
        'line' => ['sparte'],
        'product' => ['produkt', 'tarif'],
        'contract_number' => ['vertragsnummer', 'vertrags-nr', 'versicherungsscheinnummer', 'versicherungsschein-nr', 'vsnr'],
        'document_number' => ['dokumentnummer', 'dokument-nr'],
        'fonds_finanz_number' => ['fonds-finanz-nr', 'fonds finanz nummer', 'ff-nr', 'vermittlernummer', 'vorgangsnummer'],
    ];

    public function parse(string $text): FondsFinanzData
    {
        $fields = [];

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
                if (!isset($fields[$field]) && in_array($label, $variants, true)) {
                    $fields[$field] = $value;
                    break;
                }
            }
        }

        return new FondsFinanzData(
            customerName: $fields['customer_name'] ?? null,
            birthDate: isset($fields['birth_date']) ? $this->normalizeDate($fields['birth_date']) : null,
            company: $fields['company'] ?? null,
            line: $fields['line'] ?? null,
            product: $fields['product'] ?? null,
            contractNumber: $fields['contract_number'] ?? null,
            documentNumber: $fields['document_number'] ?? null,
            fondsFinanzNumber: $fields['fonds_finanz_number'] ?? null,
        );
    }

    /** "12.04.1988" -> "1988-04-12"; ISO-Daten bleiben; alles andere wird verworfen. */
    private function normalizeDate(string $value): ?string
    {
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        return null;
    }
}
