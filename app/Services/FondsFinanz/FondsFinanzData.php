<?php
namespace App\Services\FondsFinanz;

/**
 * Strukturiertes Ergebnis des FondsFinanzParser (Architekturplan
 * Abschnitt 8): Kunde, Gesellschaft, Sparte, Produkt, Vertragsnummer,
 * Dokumentnummer, Fonds-Finanz-Nummer. Alle Felder nullable - der
 * Parser scheitert defensiv (fehlende Felder statt geratener Werte).
 */
final class FondsFinanzData
{
    public function __construct(
        public readonly ?string $customerName = null,
        public readonly ?string $birthDate = null,
        public readonly ?string $company = null,
        public readonly ?string $line = null,
        public readonly ?string $product = null,
        public readonly ?string $contractNumber = null,
        public readonly ?string $documentNumber = null,
        public readonly ?string $fondsFinanzNumber = null,
    ) {
    }

    /**
     * Mindestanforderung für einen automatischen VERTRAGS-Import: Ohne
     * Vertragsnummer UND Kundennamen wird kein Vertrag angelegt
     * (defensives Scheitern, Abschnitt 20.5).
     */
    public function isImportable(): bool
    {
        return $this->contractNumber !== null && $this->customerName !== null;
    }

    /**
     * Reicht fuer eine KUNDEN-Zuordnung (auch ohne Vertragsnummer): Viele
     * reale Fonds-Finanz-Mails ("Neues Dokument zum Kunden ...") nennen
     * nur den Kunden. Statt "konnte nicht gelesen werden" wird dann der
     * Kunde gematcht/angelegt und das Dokument seiner Akte zugeordnet.
     */
    public function hasCustomer(): bool
    {
        return $this->customerName !== null && trim($this->customerName) !== '';
    }

    public function hasContract(): bool
    {
        return $this->contractNumber !== null && trim($this->contractNumber) !== '';
    }

    /**
     * Fuellt leere Felder aus einer weiteren Quelle auf (Prioritaet:
     * $this vor $fallback). So werden Betreff-, Body- und PDF-Ergebnisse
     * zu EINEM Datensatz zusammengefuehrt, ohne bereits erkannte Werte zu
     * ueberschreiben.
     */
    public function mergeMissing(FondsFinanzData $fallback): FondsFinanzData
    {
        return new FondsFinanzData(
            customerName: $this->customerName ?? $fallback->customerName,
            birthDate: $this->birthDate ?? $fallback->birthDate,
            company: $this->company ?? $fallback->company,
            line: $this->line ?? $fallback->line,
            product: $this->product ?? $fallback->product,
            contractNumber: $this->contractNumber ?? $fallback->contractNumber,
            documentNumber: $this->documentNumber ?? $fallback->documentNumber,
            fondsFinanzNumber: $this->fondsFinanzNumber ?? $fallback->fondsFinanzNumber,
        );
    }
}
