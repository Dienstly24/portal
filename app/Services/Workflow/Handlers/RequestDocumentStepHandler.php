<?php
namespace App\Services\Workflow\Handlers;

use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use App\Services\Workflow\Contracts\StepHandlerInterface;
use App\Services\Workflow\Support\StepResult;

/**
 * Schritt `request_document`: stellt sicher, dass eine Quelle (Dokumenttext
 * oder verknuepftes Dokument) vorliegt. Fehlt sie, haelt der Workflow als
 * `waiting_customer` an - der Kunde wird um den Upload gebeten. Sobald die
 * Quelle im Gedaechtnis (`memory.source_text` / `memory.document_id`) steht,
 * ist der Schritt erledigt.
 */
class RequestDocumentStepHandler implements StepHandlerInterface
{
    public function type(): string
    {
        return 'request_document';
    }

    public function handle(WorkflowStepRun $step, WorkflowRun $run): StepResult
    {
        $memory = $run->memory ?? [];
        $documentId = $memory['document_id'] ?? null;
        $sourceText = (string) ($memory['source_text'] ?? '');

        if ($documentId || $sourceText !== '') {
            return StepResult::completed([
                'source' => $documentId ? 'document' : 'text',
                'document_id' => $documentId,
            ], 100);
        }

        $message = is_array($step->config) ? ($step->config['message'] ?? null) : null;

        return StepResult::waitingCustomer(
            message: $message ?: 'Bitte laden Sie das benoetigte Dokument hoch.',
        );
    }
}
