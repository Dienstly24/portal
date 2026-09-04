<?php

namespace App\Support\Commission;

use Carbon\CarbonInterface;

/**
 * ARCH-3: eine Provisionsbuchung, so wie ein BERICHT sie sehen muss.
 *
 * Bewusst ein eigenes, schmales Lese-Objekt und KEIN gemeinsames Modell:
 * die drei Quellen bleiben getrennte fachliche Wahrheiten (siehe
 * CommissionReadService). Was ein Bericht von ihnen braucht, ist aber
 * ueberall dasselbe - Datum, Betrag, Richtung, Zustand, Bezug.
 *
 * WICHTIG - RICHTUNG: `direction` ist kein Schmuckfeld. `provisions` ist
 * GELD, DAS RAUSGEHT (an eigene Mitarbeiter und Partner), die beiden
 * anderen Quellen sind GELD, DAS REINKOMMT. Wer beides zu einer Summe
 * addiert, rechnet Einnahmen und Ausgaben zusammen und bekommt eine Zahl,
 * die nichts bedeutet. Deshalb traegt jede Zeile ihre Richtung mit, und
 * die Summen des Lesedienstes sind je Richtung getrennt.
 */
final readonly class CommissionEntry
{
    public const EINGANG = 'eingang';

    public const AUSGANG = 'ausgang';

    public function __construct(
        /** Schluessel der Quelle, z. B. 'contract_commissions'. */
        public string $source,
        /** Anzeigename der Quelle fuer Berichte. */
        public string $sourceLabel,
        public string $direction,
        public string $id,
        public float $amount,
        public string $currency,
        public ?CarbonInterface $bookedAt,
        /** Zustand in der SPRACHE DER QUELLE - bewusst nicht vereinheitlicht. */
        public ?string $status,
        public ?string $contractId = null,
        public ?string $customerId = null,
        /** Wer zahlt bzw. wer bekommt: Pool, Vermittler, Mitarbeiter, Partner. */
        public ?string $counterparty = null,
        /** Kennung im System der Quelle (Vermittler-Id, Referenz-Nr. ...). */
        public ?string $reference = null,
    ) {}

    public function isIncoming(): bool
    {
        return $this->direction === self::EINGANG;
    }
}
