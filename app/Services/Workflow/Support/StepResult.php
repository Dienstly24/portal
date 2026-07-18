<?php
namespace App\Services\Workflow\Support;

use App\Models\WorkflowStepRun;

/**
 * Ergebnis eines Step-Handlers. Normalisiert, was ein Schritt der Engine
 * zurueckmeldet: Zielstatus, Konfidenz, Ergebnis (`output`) und eine
 * optionale Nachricht (z.B. die an den Kunden gepostete Rueckfrage oder ein
 * Fehlertext).
 *
 * Die Engine wendet danach das Confidence-Gate an: ein `completed`-Ergebnis
 * unter der Schwelle der Definition wird auf `needs_review` herabgestuft.
 */
final class StepResult
{
    /** @param array<string,mixed> $output */
    public function __construct(
        public readonly string $status,
        public readonly ?int $confidence = null,
        public readonly array $output = [],
        public readonly ?string $message = null,
    ) {
    }

    /** @param array<string,mixed> $output */
    public static function completed(array $output = [], ?int $confidence = 100): self
    {
        return new self(WorkflowStepRun::STATUS_COMPLETED, $confidence, $output);
    }

    /** @param array<string,mixed> $output */
    public static function needsReview(array $output = [], ?int $confidence = null, ?string $message = null): self
    {
        return new self(WorkflowStepRun::STATUS_NEEDS_REVIEW, $confidence, $output, $message);
    }

    /** @param array<string,mixed> $output */
    public static function waitingCustomer(array $output = [], ?string $message = null): self
    {
        return new self(WorkflowStepRun::STATUS_WAITING_CUSTOMER, null, $output, $message);
    }

    /** @param array<string,mixed> $output */
    public static function failed(string $message, array $output = []): self
    {
        return new self(WorkflowStepRun::STATUS_FAILED, null, $output, $message);
    }

    /** Haelt dieser Ausgang die Engine an (Mensch/Kunde muss handeln)? */
    public function halts(): bool
    {
        return in_array($this->status, WorkflowStepRun::HALTING, true);
    }
}
