<?php

namespace Tests\Feature\Workflow;

use App\Models\Customer;
use App\Models\CustomerChangeRequest;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Support\AiRequest;
use App\Services\Ai\Support\AiResponse;
use App\Services\Workflow\WorkflowDefinitionInstaller;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Erste echte Workflow-Definition end-to-end (P3): "Bankverbindung aendern".
 * Nachweis anfordern -> IBAN extrahieren -> Aenderungsantrag (Vier-Augen) ->
 * Antwort-Entwurf zur Freigabe. Deckt Confidence-Gate, Human Override und die
 * DSGVO-Leitplanke (KI schlaegt nur vor) ab. Die KI ist ein Fake-Anbieter.
 */
class BankverbindungWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $ai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ai = new FakeAiProvider();
        $this->app->instance(AiProviderInterface::class, $this->ai);
        app(WorkflowDefinitionInstaller::class)->installAll();
    }

    private function engine(): WorkflowEngine
    {
        return app(WorkflowEngine::class);
    }

    private function definition(): WorkflowDefinition
    {
        return WorkflowDefinition::activeFor('bankverbindung_aendern');
    }

    private function customer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(Str::random(6)),
        ]);
    }

    public function test_installer_creates_definition_with_steps_and_prompts(): void
    {
        $def = $this->definition();
        $this->assertNotNull($def);
        $this->assertSame('bankverbindung_aendern', $def->service_key);
        $this->assertCount(4, $def->steps);
        $this->assertNotNull($def->promptTemplate('extraction'));
        $this->assertNotNull($def->promptTemplate('reply'));
    }

    public function test_full_flow_creates_pending_change_request_and_halts_on_draft(): void
    {
        $customer = $this->customer();
        $staff = User::factory()->create(['role' => 'admin']);

        $run = $this->engine()->start($this->definition(), [
            'customer_id' => $customer->id,
            'started_by' => $staff->id,
            'memory' => ['source_text' => 'Neue Bankverbindung IBAN DE89 3704 0044 0532 0130 00, Kontoinhaber Max Mustermann.'],
        ]);
        $run = $this->engine()->advance($run);

        // Haelt am Antwort-Entwurf zur Freigabe an.
        $this->assertSame(WorkflowRun::STATUS_NEEDS_REVIEW, $run->status);
        $this->assertSame('antwort_entwerfen', $run->current_step_key);

        // request_document + extract_data + apply_change sind erledigt.
        $extract = $run->stepRuns()->where('step_key', 'daten_extrahieren')->first();
        $this->assertSame(WorkflowStepRun::STATUS_COMPLETED, $extract->status);
        $this->assertSame('DE89370400440532013000', $extract->output['iban']);

        // Der Aenderungsantrag ist als pending angelegt (Vier-Augen-Prinzip),
        // die echten Kundendaten sind NICHT direkt geaendert.
        $cr = CustomerChangeRequest::where('customer_id', $customer->id)->first();
        $this->assertNotNull($cr);
        $this->assertSame('bank', $cr->type);
        $this->assertSame('pending', $cr->status);
        $this->assertSame('DE89370400440532013000', $cr->new_data['iban']);
        $this->assertSame('Max Mustermann', $cr->new_data['account_holder']);
        $this->assertSame($customer->fresh()->iban, $customer->iban); // unveraendert

        // Der Entwurf liegt vor und wartet auf Freigabe.
        $draft = $run->stepRuns()->where('step_key', 'antwort_entwerfen')->first();
        $this->assertSame(WorkflowStepRun::STATUS_NEEDS_REVIEW, $draft->status);
        $this->assertNotEmpty($draft->output['draft']);
    }

    public function test_staff_approves_draft_and_run_completes(): void
    {
        $customer = $this->customer();
        $run = $this->engine()->start($this->definition(), [
            'customer_id' => $customer->id,
            'memory' => ['source_text' => 'IBAN DE89 3704 0044 0532 0130 00, Inhaber Max Mustermann.'],
        ]);
        $run = $this->engine()->advance($run);
        $draft = $run->stepRuns()->where('step_key', 'antwort_entwerfen')->first();

        $this->engine()->override($draft, 'complete', ['draft' => 'Freigegebener Text'], actorId: 1);
        $run = $this->engine()->advance($run->refresh());

        $this->assertSame(WorkflowRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('Freigegebener Text', $draft->refresh()->output['draft']);
    }

    public function test_low_confidence_extraction_halts_before_apply_change(): void
    {
        $this->ai->extractionJson = '{"iban":"DE89 3704 0044 0532 0130 00","account_holder":"Max Mustermann","confidence":40}';
        $customer = $this->customer();

        $run = $this->engine()->advance($this->engine()->start($this->definition(), [
            'customer_id' => $customer->id,
            'memory' => ['source_text' => 'Undeutlicher Text mit IBAN DE89 3704 0044 0532 0130 00.'],
        ]));

        $this->assertSame(WorkflowRun::STATUS_NEEDS_REVIEW, $run->status);
        $this->assertSame('daten_extrahieren', $run->current_step_key);
        // Kein Aenderungsantrag, weil die Extraktion nicht freigegeben ist.
        $this->assertSame(0, CustomerChangeRequest::count());
    }

    public function test_request_document_waits_for_customer_without_source(): void
    {
        $customer = $this->customer();

        $run = $this->engine()->advance($this->engine()->start($this->definition(), [
            'customer_id' => $customer->id,
        ]));

        $this->assertSame(WorkflowRun::STATUS_WAITING_CUSTOMER, $run->status);
        $this->assertSame('dokument_anfordern', $run->current_step_key);
    }

    public function test_apply_change_needs_review_without_customer(): void
    {
        $run = $this->engine()->advance($this->engine()->start($this->definition(), [
            'memory' => ['source_text' => 'IBAN DE89 3704 0044 0532 0130 00, Inhaber Max Mustermann.'],
        ]));

        $this->assertSame(WorkflowRun::STATUS_NEEDS_REVIEW, $run->status);
        $this->assertSame('bankdaten_uebernehmen', $run->current_step_key);
        $this->assertSame(0, CustomerChangeRequest::count());
    }

    public function test_without_ai_provider_extraction_needs_manual_entry(): void
    {
        $this->ai->enabled = false;
        $customer = $this->customer();

        $run = $this->engine()->advance($this->engine()->start($this->definition(), [
            'customer_id' => $customer->id,
            'memory' => ['source_text' => 'IBAN DE89 3704 0044 0532 0130 00.'],
        ]));

        $this->assertSame(WorkflowRun::STATUS_NEEDS_REVIEW, $run->status);
        $this->assertSame('daten_extrahieren', $run->current_step_key);
    }

    public function test_install_command_is_idempotent(): void
    {
        $this->artisan('workflow:install')->assertSuccessful();
        $this->artisan('workflow:install')->assertSuccessful();

        $this->assertSame(1, WorkflowDefinition::where('service_key', 'bankverbindung_aendern')->count());
    }
}

/**
 * Fake-KI-Anbieter fuer die Workflow-Tests: liefert je nach Prompt eine
 * Extraktions-JSON oder einen Antworttext. Unterscheidung ueber den Marker
 * "DOKUMENTTEXT" (nur im Extraktions-Prompt).
 */
class FakeAiProvider implements AiProviderInterface
{
    public bool $enabled = true;
    public string $extractionJson = '{"iban":"DE89 3704 0044 0532 0130 00","account_holder":"Max Mustermann","confidence":95}';
    public string $replyText = 'Vielen Dank, wir haben Ihre neue Bankverbindung erhalten und pruefen sie zeitnah.';

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-1';
    }

    public function complete(AiRequest $request): AiResponse
    {
        $prompt = $request->parts[0]['text'] ?? '';
        $isExtraction = str_contains($prompt, 'DOKUMENTTEXT');

        return new AiResponse(
            text: $isExtraction ? $this->extractionJson : $this->replyText,
            provider: 'fake',
            model: 'fake-1',
        );
    }
}
