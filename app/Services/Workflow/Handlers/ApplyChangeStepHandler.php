<?php
namespace App\Services\Workflow\Handlers;

use App\Models\Customer;
use App\Models\CustomerChangeRequest;
use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use App\Services\ChangeRequestService;
use App\Services\Workflow\Contracts\StepHandlerInterface;
use App\Services\Workflow\Support\StepResult;

/**
 * Schritt `apply_change`: uebernimmt die zuvor extrahierten Felder NICHT
 * direkt, sondern legt einen regulaeren Aenderungsantrag
 * (CustomerChangeRequest, Status `pending`) ueber den bestehenden
 * ChangeRequestService an. Damit gilt weiter das Vier-Augen-Prinzip - ein
 * Mitarbeiter genehmigt die Aenderung im vorhandenen Admin-Review, die KI
 * schlaegt nur vor (Blueprint Saeule 5, DSGVO-Leitplanke).
 *
 * Konfidenz 100, weil der Schritt selbst nichts an echten Kundendaten
 * aendert - er reicht einen pruefbaren Vorschlag ein.
 */
class ApplyChangeStepHandler implements StepHandlerInterface
{
    public function __construct(private readonly ChangeRequestService $changes)
    {
    }

    public function type(): string
    {
        return 'apply_change';
    }

    public function handle(WorkflowStepRun $step, WorkflowRun $run): StepResult
    {
        if (!$run->customer_id) {
            return StepResult::needsReview(message: 'Kein Kunde zugeordnet - bitte zuerst die Kundenakte verknuepfen.');
        }

        $config = is_array($step->config) ? $step->config : [];
        $type = $config['change_type'] ?? null;
        if (!in_array($type, CustomerChangeRequest::TYPES, true)) {
            return StepResult::failed('Ungueltiger change_type: ' . var_export($type, true));
        }

        $extracted = $run->latestOutputOfType('extract_data');
        $allowed = (array) ($config['fields'] ?? array_keys($extracted));
        $newData = array_intersect_key($extracted, array_flip($allowed));
        $newData = array_filter($newData, fn ($v) => is_scalar($v) && (string) $v !== '');

        if ($newData === []) {
            return StepResult::needsReview(message: 'Keine uebernehmbaren Daten vorhanden - bitte manuell pruefen.');
        }

        $customer = Customer::find($run->customer_id);
        if ($customer === null) {
            return StepResult::needsReview(message: 'Zugeordnete Kundenakte nicht gefunden.');
        }

        $changeRequest = $this->changes->submit(
            $customer,
            $type,
            null,
            $newData,
            'Automatischer Workflow: ' . $run->definition_key,
            $run->started_by,
        );

        return StepResult::completed([
            'change_request_id' => (string) $changeRequest->id,
            'type' => $type,
            'fields' => array_keys($newData),
        ], 100);
    }
}
