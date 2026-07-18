<?php
namespace App\Services\Workflow\Contracts;

use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use App\Services\Workflow\Support\StepResult;

/**
 * Ein Handler fuehrt genau EINEN generischen Schritt-Typ aus
 * (detect_intent, request_document, ask_customer, extract_data,
 * match_customer, compare_data, apply_change, draft_reply,
 * draft_authority_message, generate_report, review ...). Neue Typen sind
 * additiv - der Engine-Kern bleibt unveraendert (Blueprint Saeule 1).
 *
 * Der Handler liest seinen Kontext aus `$step->config` (Definitions-Snapshot)
 * und `$run->memory` (KI-Gedaechtnis) und meldet das Ergebnis als StepResult
 * zurueck. Er aendert selbst KEINE Kundendaten ausserhalb der bestehenden
 * Freigabe-Logik - die KI schlaegt nur vor.
 */
interface StepHandlerInterface
{
    /** Schritt-Typ, den dieser Handler bedient (z.B. 'review'). */
    public function type(): string;

    /** Fuehrt den Schritt aus und liefert das normalisierte Ergebnis. */
    public function handle(WorkflowStepRun $step, WorkflowRun $run): StepResult;
}
