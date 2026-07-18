<?php
namespace App\Services\Workflow;

use App\Models\AiActionLog;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use App\Services\Workflow\Support\StepResult;
use Illuminate\Support\Facades\DB;

/**
 * Kern der generischen Workflow-Engine (Blueprint
 * docs/KONZEPT_AI_WORKFLOW_ENGINE.md). Kennt NUR Definitionen und
 * Schritt-Typen - keine sparten-spezifische Logik. Die eigentliche Arbeit je
 * Schritt leisten Handler (StepHandlerInterface), die KI ist nur das Gehirn.
 *
 * Verantwortung:
 * - `start()`     legt einen Run + seine Step-Runs aus der Definition an.
 * - `advance()`   fuehrt Schritte der Reihe nach aus, bis einer die Engine
 *                 anhaelt (needs_review / waiting_customer / failed) oder alle
 *                 fertig sind (completed).
 * - Confidence-Gate: ein `completed`-Schritt unter der Schwelle der Definition
 *   wird auf `needs_review` herabgestuft (Blueprint Saeule 4).
 * - `override()`  Human Override je Schritt: editieren / neu ausfuehren /
 *                 ueberspringen / manuell erledigen (Saeule 5).
 * - Gedaechtnis (`run.memory`) und Chronik (`ai_action_logs`) werden bei jedem
 *   Schritt fortgeschrieben (Saeulen 6 + 10).
 */
class WorkflowEngine
{
    public function __construct(private readonly StepHandlerRegistry $registry)
    {
    }

    /**
     * Neuen Run aus einer Definition anlegen (noch nicht ausfuehren).
     *
     * @param array{ticket_id?:string,customer_id?:string,started_by?:int,memory?:array<string,mixed>} $context
     */
    public function start(WorkflowDefinition $definition, array $context = []): WorkflowRun
    {
        return DB::transaction(function () use ($definition, $context) {
            $run = WorkflowRun::create([
                'workflow_definition_id' => $definition->id,
                'definition_key' => $definition->service_key,
                'version' => $definition->version,
                'ticket_id' => $context['ticket_id'] ?? null,
                'customer_id' => $context['customer_id'] ?? null,
                'status' => WorkflowRun::STATUS_RUNNING,
                'confidence' => null,
                'memory' => $context['memory'] ?? [],
                'started_by' => $context['started_by'] ?? null,
            ]);

            $order = 0;
            foreach ($definition->steps ?? [] as $step) {
                // Eine Definition darf einen Schritt als String (nur Typ) oder
                // als Array ({key,type,config}) angeben - beides wird normalisiert.
                $type = is_array($step) ? ($step['type'] ?? null) : $step;
                if (!is_string($type) || $type === '') {
                    continue;
                }
                $key = is_array($step) ? ($step['key'] ?? $type) : $type;
                $config = is_array($step) ? ($step['config'] ?? []) : [];

                $run->stepRuns()->create([
                    'step_key' => (string) $key,
                    'type' => $type,
                    'status' => WorkflowStepRun::STATUS_PENDING,
                    'config' => $config,
                    'sort_order' => $order++,
                ]);
            }

            AiActionLog::record($run, null, AiActionLog::ACTOR_SYSTEM, 'run_started', [
                'definition' => $definition->service_key,
                'version' => $definition->version,
                'steps' => $order,
            ], null, $context['started_by'] ?? null);

            return $run->refresh();
        });
    }

    /**
     * Run vorantreiben: erledigte Schritte ueberspringen, den naechsten
     * offenen Schritt ausfuehren, bei einem anhaltenden Ausgang stoppen. Ist
     * kein Schritt mehr offen, wird der Run abgeschlossen.
     */
    public function advance(WorkflowRun $run): WorkflowRun
    {
        if ($run->isTerminal()) {
            return $run;
        }

        while (true) {
            $step = $run->stepRuns()
                ->whereNotIn('status', WorkflowStepRun::DONE)
                ->orderBy('sort_order')
                ->first();

            if ($step === null) {
                return $this->finish($run);
            }

            // Ein bereits anhaltender Schritt (Mensch/Kunde am Zug) stoppt hier.
            if ($step->isHalting()) {
                $this->haltRun($run, $step);
                return $run->refresh();
            }

            $result = $this->executeStep($run, $step);
            if ($result->halts()) {
                $this->haltRun($run, $step->refresh());
                return $run->refresh();
            }
            // completed/skipped -> naechster Schritt (Schleife laeuft weiter).
        }
    }

    /**
     * Human Override auf einen Schritt (Blueprint Saeule 5). Nach dem Override
     * wird der Run - falls er auf diesem Schritt angehalten war - wieder auf
     * `running` gesetzt; der Aufrufer ruft danach `advance()`.
     *
     * @param 'edit'|'complete'|'skip'|'rerun' $action
     * @param array<string,mixed> $data
     */
    public function override(WorkflowStepRun $step, string $action, array $data = [], ?int $actorId = null): WorkflowStepRun
    {
        $run = $step->run;

        switch ($action) {
            case 'edit':
            case 'complete':
                $confidence = isset($data['confidence']) ? (int) $data['confidence'] : 100;
                $output = $data;
                unset($output['confidence']);
                $step->update([
                    'status' => WorkflowStepRun::STATUS_COMPLETED,
                    'output' => $output !== [] ? $output : ($step->output ?? []),
                    'confidence' => $confidence,
                    'error' => null,
                    'decided_by' => $actorId,
                    'decided_at' => now(),
                ]);
                break;

            case 'skip':
                $step->update([
                    'status' => WorkflowStepRun::STATUS_SKIPPED,
                    'decided_by' => $actorId,
                    'decided_at' => now(),
                ]);
                break;

            case 'rerun':
                $step->update([
                    'status' => WorkflowStepRun::STATUS_PENDING,
                    'confidence' => null,
                    'output' => null,
                    'error' => null,
                    'decided_by' => $actorId,
                    'decided_at' => now(),
                ]);
                break;

            default:
                throw new \InvalidArgumentException("Unbekannte Override-Aktion: {$action}");
        }

        AiActionLog::record($run, $step, AiActionLog::ACTOR_STAFF, 'override_' . $action, [
            'step' => $step->step_key,
            'type' => $step->type,
        ], null, $actorId);

        // War der Run auf diesem Schritt blockiert, wieder freigeben.
        if (in_array($run->status, [WorkflowRun::STATUS_NEEDS_REVIEW, WorkflowRun::STATUS_WAITING_CUSTOMER], true)) {
            $run->update(['status' => WorkflowRun::STATUS_RUNNING]);
        }

        return $step->refresh();
    }

    /** Run abbrechen (z.B. Mitarbeiter verwirft den Vorgang). */
    public function cancel(WorkflowRun $run, ?int $actorId = null): WorkflowRun
    {
        if (!$run->isTerminal()) {
            $run->update(['status' => WorkflowRun::STATUS_CANCELLED]);
            AiActionLog::record($run, null, AiActionLog::ACTOR_STAFF, 'run_cancelled', [], null, $actorId);
        }
        return $run->refresh();
    }

    /**
     * Einen offenen Schritt ausfuehren: Handler aufrufen, Confidence-Gate
     * anwenden, Ergebnis + Gedaechtnis + Chronik fortschreiben.
     */
    private function executeStep(WorkflowRun $run, WorkflowStepRun $step): StepResult
    {
        $step->update(['status' => WorkflowStepRun::STATUS_RUNNING]);

        try {
            $result = $this->registry->resolve($step->type)->handle($step, $run);
        } catch (\Throwable $e) {
            $result = StepResult::failed($e->getMessage());
        }

        // Confidence-Gate: erfolgreicher Schritt unter der Schwelle ->
        // needs_review (Mitarbeiter-Freigabe), damit keine unsichere KI-Aktion
        // ohne Mensch durchlaeuft.
        $threshold = $run->definition?->confidence_threshold ?? 90;
        $status = $result->status;
        if ($status === WorkflowStepRun::STATUS_COMPLETED
            && $result->confidence !== null
            && $result->confidence < $threshold) {
            $status = WorkflowStepRun::STATUS_NEEDS_REVIEW;
        }

        $step->update([
            'status' => $status,
            'confidence' => $result->confidence,
            'output' => $result->output,
            'error' => $status === WorkflowStepRun::STATUS_FAILED ? $result->message : null,
        ]);

        $this->remember($run, $step, $result);

        AiActionLog::record($run, $step, AiActionLog::ACTOR_AI, 'step_' . $status, [
            'step' => $step->step_key,
            'type' => $step->type,
            'message' => $result->message,
        ], $result->confidence);

        // Ergebnis mit ggf. herabgestuftem Status zurueckgeben, damit der
        // advance()-Loop das Anhalten erkennt.
        return new StepResult($status, $result->confidence, $result->output, $result->message);
    }

    /** KI-Gedaechtnis um das Schritt-Ergebnis erweitern (Blueprint Saeule 6). */
    private function remember(WorkflowRun $run, WorkflowStepRun $step, StepResult $result): void
    {
        $memory = $run->memory ?? [];
        $memory['steps'][$step->step_key] = [
            'type' => $step->type,
            'status' => $step->status,
            'confidence' => $result->confidence,
            'output' => $result->output,
        ];
        $memory['last_step'] = $step->step_key;
        $run->update(['memory' => $memory]);
    }

    /** Run auf den Status des anhaltenden Schrittes setzen. */
    private function haltRun(WorkflowRun $run, WorkflowStepRun $step): void
    {
        $run->update([
            'status' => $step->status,
            'current_step_key' => $step->step_key,
        ]);
        AiActionLog::record($run, $step, AiActionLog::ACTOR_SYSTEM, 'run_halted', [
            'step' => $step->step_key,
            'status' => $step->status,
        ], $step->confidence);
    }

    /** Alle Schritte erledigt: Run abschliessen (Gesamt-Konfidenz = Minimum). */
    private function finish(WorkflowRun $run): WorkflowRun
    {
        $confidences = $run->stepRuns()->whereNotNull('confidence')->pluck('confidence');
        $overall = $confidences->isEmpty() ? null : (int) $confidences->min();

        $run->update([
            'status' => WorkflowRun::STATUS_COMPLETED,
            'confidence' => $overall,
            'current_step_key' => null,
            'completed_at' => now(),
        ]);

        AiActionLog::record($run, null, AiActionLog::ACTOR_SYSTEM, 'run_completed', [
            'confidence' => $overall,
        ], $overall);

        return $run->refresh();
    }
}
