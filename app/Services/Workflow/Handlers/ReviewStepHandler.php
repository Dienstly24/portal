<?php
namespace App\Services\Workflow\Handlers;

use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use App\Services\Workflow\Contracts\StepHandlerInterface;
use App\Services\Workflow\Support\StepResult;

/**
 * Generischer Freigabe-Schritt (`review`): haelt den Workflow bewusst an und
 * verlangt eine Mitarbeiter-Freigabe (needs_review). Sparten-unabhaengig -
 * jede Definition kann ihn als Kontroll-Halt einbauen (z.B. vor
 * `apply_change`). Der Mitarbeiter loest ihn per Human Override (complete /
 * skip) wieder.
 */
class ReviewStepHandler implements StepHandlerInterface
{
    public function type(): string
    {
        return 'review';
    }

    public function handle(WorkflowStepRun $step, WorkflowRun $run): StepResult
    {
        $note = is_array($step->config) ? ($step->config['note'] ?? null) : null;

        return StepResult::needsReview(
            message: $note ?: 'Manuelle Freigabe erforderlich.',
        );
    }
}
