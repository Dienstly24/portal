<?php

namespace Tests\Feature\Workflow;

use App\Models\AiActionLog;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use App\Services\Workflow\Contracts\StepHandlerInterface;
use App\Services\Workflow\StepHandlerRegistry;
use App\Services\Workflow\Support\StepResult;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Kern der generischen Workflow-Engine: Anlegen, Vorantreiben,
 * Confidence-Gate, Anhalten (needs_review / waiting_customer / failed),
 * Human Override und Verschluesselung des Gedaechtnisses/Ergebnisses.
 */
class WorkflowEngineTest extends TestCase
{
    use RefreshDatabase;

    private function engine(): WorkflowEngine
    {
        return app(WorkflowEngine::class);
    }

    /** Test-Handler registrieren (Typ -> Verhalten). */
    private function registerHandler(string $type, callable $behavior): void
    {
        app(StepHandlerRegistry::class)->register(new class($type, $behavior) implements StepHandlerInterface {
            public function __construct(private string $t, private $behavior) {}
            public function type(): string { return $this->t; }
            public function handle(WorkflowStepRun $step, WorkflowRun $run): StepResult {
                return ($this->behavior)($step, $run);
            }
        });
    }

    private function definition(array $steps, array $attr = []): WorkflowDefinition
    {
        return WorkflowDefinition::create(array_merge([
            'branch' => 'krankenversicherung',
            'service_key' => 'test_service',
            'version' => 1,
            'active' => true,
            'title' => 'Testdienstleistung',
            'steps' => $steps,
        ], $attr));
    }

    public function test_start_creates_run_with_step_runs_in_order(): void
    {
        $this->registerHandler('auto', fn () => StepResult::completed());
        $def = $this->definition([
            ['key' => 'erster', 'type' => 'auto'],
            ['key' => 'zweiter', 'type' => 'review'],
        ]);

        $run = $this->engine()->start($def, ['started_by' => 7]);

        $this->assertSame(WorkflowRun::STATUS_RUNNING, $run->status);
        $this->assertSame('test_service', $run->definition_key);
        $this->assertSame(1, $run->version);
        $this->assertSame(7, $run->started_by);
        $this->assertCount(2, $run->stepRuns);
        $ordered = $run->stepRuns()->orderBy('sort_order')->pluck('step_key')->all();
        $this->assertSame(['erster', 'zweiter'], $ordered);
        $this->assertTrue($run->stepRuns->every(fn ($s) => $s->status === WorkflowStepRun::STATUS_PENDING));
        $this->assertDatabaseHas('ai_action_logs', ['workflow_run_id' => $run->id, 'action' => 'run_started']);
    }

    public function test_advance_completes_run_when_all_steps_pass(): void
    {
        $this->registerHandler('auto', fn () => StepResult::completed(['ok' => true], 100));
        $def = $this->definition([
            ['key' => 'a', 'type' => 'auto'],
            ['key' => 'b', 'type' => 'auto'],
        ]);

        $run = $this->engine()->advance($this->engine()->start($def));

        $this->assertSame(WorkflowRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(100, $run->confidence);
        $this->assertNotNull($run->completed_at);
        $this->assertTrue($run->stepRuns->every(fn ($s) => $s->status === WorkflowStepRun::STATUS_COMPLETED));
        $this->assertDatabaseHas('ai_action_logs', ['workflow_run_id' => $run->id, 'action' => 'run_completed']);
    }

    public function test_confidence_gate_downgrades_low_confidence_to_needs_review(): void
    {
        // Handler meldet completed, aber mit Konfidenz unter der Schwelle (90).
        $this->registerHandler('shaky', fn () => StepResult::completed(['guess' => 'x'], 50));
        $def = $this->definition([['key' => 'unsicher', 'type' => 'shaky']]);

        $run = $this->engine()->advance($this->engine()->start($def));

        $this->assertSame(WorkflowRun::STATUS_NEEDS_REVIEW, $run->status);
        $this->assertSame('unsicher', $run->current_step_key);
        $step = $run->stepRuns()->first();
        $this->assertSame(WorkflowStepRun::STATUS_NEEDS_REVIEW, $step->status);
        $this->assertSame(50, $step->confidence);
    }

    public function test_custom_threshold_allows_lower_confidence(): void
    {
        $this->registerHandler('shaky', fn () => StepResult::completed(['guess' => 'x'], 50));
        $def = $this->definition([['key' => 'unsicher', 'type' => 'shaky']], ['confidence_threshold' => 40]);

        $run = $this->engine()->advance($this->engine()->start($def));

        $this->assertSame(WorkflowRun::STATUS_COMPLETED, $run->status);
    }

    public function test_review_step_halts_run(): void
    {
        $def = $this->definition([['key' => 'freigabe', 'type' => 'review', 'config' => ['note' => 'Bitte pruefen']]]);

        $run = $this->engine()->advance($this->engine()->start($def));

        $this->assertSame(WorkflowRun::STATUS_NEEDS_REVIEW, $run->status);
        $this->assertSame(WorkflowStepRun::STATUS_NEEDS_REVIEW, $run->stepRuns()->first()->status);
    }

    public function test_waiting_customer_halts_run(): void
    {
        $this->registerHandler('frage', fn () => StepResult::waitingCustomer(message: 'Wie lautet Ihre neue IBAN?'));
        $def = $this->definition([['key' => 'rueckfrage', 'type' => 'frage']]);

        $run = $this->engine()->advance($this->engine()->start($def));

        $this->assertSame(WorkflowRun::STATUS_WAITING_CUSTOMER, $run->status);
        $this->assertSame('rueckfrage', $run->current_step_key);
    }

    public function test_handler_exception_marks_step_and_run_failed(): void
    {
        $this->registerHandler('boom', fn () => throw new \RuntimeException('Kaputt'));
        $def = $this->definition([['key' => 'fehler', 'type' => 'boom']]);

        $run = $this->engine()->advance($this->engine()->start($def));

        $this->assertSame(WorkflowRun::STATUS_FAILED, $run->status);
        $step = $run->stepRuns()->first();
        $this->assertSame(WorkflowStepRun::STATUS_FAILED, $step->status);
        $this->assertSame('Kaputt', $step->error);
    }

    public function test_missing_handler_fails_gracefully(): void
    {
        $def = $this->definition([['key' => 'unbekannt', 'type' => 'gibt_es_nicht']]);

        $run = $this->engine()->advance($this->engine()->start($def));

        $this->assertSame(WorkflowRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('gibt_es_nicht', (string) $run->stepRuns()->first()->error);
    }

    public function test_human_override_complete_resumes_and_finishes(): void
    {
        $this->registerHandler('auto', fn () => StepResult::completed());
        $def = $this->definition([
            ['key' => 'freigabe', 'type' => 'review'],
            ['key' => 'danach', 'type' => 'auto'],
        ]);
        $run = $this->engine()->advance($this->engine()->start($def));
        $this->assertSame(WorkflowRun::STATUS_NEEDS_REVIEW, $run->status);

        $reviewStep = $run->stepRuns()->where('step_key', 'freigabe')->first();
        $this->engine()->override($reviewStep, 'complete', ['freigegeben' => true], actorId: 3);

        $run = $this->engine()->advance($run->refresh());

        $this->assertSame(WorkflowRun::STATUS_COMPLETED, $run->status);
        $reviewStep->refresh();
        $this->assertSame(WorkflowStepRun::STATUS_COMPLETED, $reviewStep->status);
        $this->assertSame(3, $reviewStep->decided_by);
        $this->assertNotNull($reviewStep->decided_at);
        $this->assertDatabaseHas('ai_action_logs', ['workflow_step_run_id' => $reviewStep->id, 'action' => 'override_complete']);
    }

    public function test_human_override_skip_resumes(): void
    {
        $this->registerHandler('auto', fn () => StepResult::completed());
        $def = $this->definition([
            ['key' => 'freigabe', 'type' => 'review'],
            ['key' => 'danach', 'type' => 'auto'],
        ]);
        $run = $this->engine()->advance($this->engine()->start($def));

        $reviewStep = $run->stepRuns()->where('step_key', 'freigabe')->first();
        $this->engine()->override($reviewStep, 'skip', actorId: 5);
        $run = $this->engine()->advance($run->refresh());

        $this->assertSame(WorkflowRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(WorkflowStepRun::STATUS_SKIPPED, $reviewStep->refresh()->status);
    }

    public function test_human_override_rerun_reexecutes_step(): void
    {
        // Handler: beim ersten Aufruf needs_review, beim zweiten completed.
        $state = new class { public int $calls = 0; };
        $this->registerHandler('flaky', function () use ($state) {
            $state->calls++;
            return $state->calls === 1
                ? StepResult::needsReview(message: 'Erstversuch unsicher')
                : StepResult::completed(['fixed' => true], 100);
        });
        $def = $this->definition([['key' => 'wiederhole', 'type' => 'flaky']]);

        $run = $this->engine()->advance($this->engine()->start($def));
        $this->assertSame(WorkflowRun::STATUS_NEEDS_REVIEW, $run->status);

        $step = $run->stepRuns()->first();
        $this->engine()->override($step, 'rerun', actorId: 9);
        $run = $this->engine()->advance($run->refresh());

        $this->assertSame(2, $state->calls);
        $this->assertSame(WorkflowRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(WorkflowStepRun::STATUS_COMPLETED, $step->refresh()->status);
    }

    public function test_advance_on_terminal_run_is_noop(): void
    {
        $this->registerHandler('auto', fn () => StepResult::completed());
        $def = $this->definition([['key' => 'a', 'type' => 'auto']]);
        $run = $this->engine()->advance($this->engine()->start($def));
        $this->assertSame(WorkflowRun::STATUS_COMPLETED, $run->status);

        $again = $this->engine()->advance($run);
        $this->assertSame(WorkflowRun::STATUS_COMPLETED, $again->status);
    }

    public function test_cancel_stops_run(): void
    {
        $def = $this->definition([['key' => 'freigabe', 'type' => 'review']]);
        $run = $this->engine()->advance($this->engine()->start($def));

        $run = $this->engine()->cancel($run, actorId: 2);

        $this->assertSame(WorkflowRun::STATUS_CANCELLED, $run->status);
        $this->assertDatabaseHas('ai_action_logs', ['workflow_run_id' => $run->id, 'action' => 'run_cancelled']);
    }

    public function test_memory_and_output_are_encrypted_at_rest(): void
    {
        $secret = 'DE89370400440532013000';
        $this->registerHandler('extract', fn () => StepResult::completed(['iban' => $secret], 100));
        $def = $this->definition([['key' => 'extraktion', 'type' => 'extract']]);

        $run = $this->engine()->advance($this->engine()->start($def));

        // Ausgelesen ueber das Model: Klartext vorhanden.
        $this->assertSame($secret, $run->stepRuns()->first()->output['iban']);
        $this->assertSame($secret, $run->memory['steps']['extraktion']['output']['iban']);

        // Roh in der DB: NICHT im Klartext (Verschluesselung greift).
        $rawOutput = DB::table('workflow_step_runs')->where('workflow_run_id', $run->id)->value('output');
        $rawMemory = DB::table('workflow_runs')->where('id', $run->id)->value('memory');
        $this->assertStringNotContainsString($secret, (string) $rawOutput);
        $this->assertStringNotContainsString($secret, (string) $rawMemory);
    }
}
