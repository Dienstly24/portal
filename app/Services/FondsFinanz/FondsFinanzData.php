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
     * Mindestanforderung für einen automatischen Import: Ohne
     * Vertragsnummer UND Kundennamen wird nichts angelegt, sondern eine
     * manuelle Prüfaufgabe erzeugt (defensives Scheitern, Abschnitt 20.5).
     */
    public function isImportable(): bool
    {
        return $this->contractNumber !== null && $this->customerName !== null;
    }
}
