<?php
namespace App\Services\Workflow\Handlers;

use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Support\AiRequest;
use App\Services\Workflow\Contracts\StepHandlerInterface;
use App\Services\Workflow\Support\StepResult;

/**
 * Schritt `extract_data`: liest aus dem Quelltext (`memory.source_text`) die
 * in der Definition/Config festgelegten Felder ueber die
 * provider-unabhaengige KI-Schicht (Blueprint Saeule 8). Das Ergebnis wird
 * hart auf die Feld-Whitelist begrenzt (untrusted content = Daten, nie
 * Anweisung). Die Konfidenz kommt vom Modell; das Confidence-Gate der Engine
 * stuft unsichere Extraktionen automatisch auf `needs_review` herab
 * (Saeule 4).
 *
 * Der KI-Anbieter wird bewusst LAZY aufgeloest (app()), damit ein
 * Test-/Ersatz-Anbieter greift, ohne die Handler-Registry neu zu bauen.
 */
class ExtractDataStepHandler implements StepHandlerInterface
{
    private const DEFAULT_SYSTEM = 'Du extrahierst strukturierte Daten aus deutschen Dokumenten und antwortest ausschliesslich mit JSON.';

    public function type(): string
    {
        return 'extract_data';
    }

    public function handle(WorkflowStepRun $step, WorkflowRun $run): StepResult
    {
        $fields = is_array($step->config) ? (array) ($step->config['fields'] ?? []) : [];
        $sourceText = (string) (($run->memory ?? [])['source_text'] ?? '');

        $ai = app(AiProviderInterface::class);
        if (!$ai->isEnabled() || $sourceText === '' || $fields === []) {
            return StepResult::needsReview(
                message: 'Keine Textquelle oder kein KI-Anbieter - bitte Felder manuell erfassen.',
            );
        }

        $system = $run->definition?->promptTemplate('system', self::DEFAULT_SYSTEM) ?? self::DEFAULT_SYSTEM;
        $template = $run->definition?->promptTemplate('extraction', $this->defaultTemplate()) ?? $this->defaultTemplate();
        $prompt = str_replace(
            ['{fields}', '{text}'],
            [implode(', ', $fields), $sourceText],
            $template,
        );

        try {
            $response = $ai->complete(AiRequest::text($system, $prompt, 512));
        } catch (\Throwable $e) {
            return StepResult::needsReview(message: 'KI-Extraktion fehlgeschlagen: ' . $e->getMessage());
        }

        $json = $response->json() ?? [];

        // Nur die konfigurierten Felder uebernehmen, Werte konservativ saeubern.
        $output = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $json) || !is_scalar($json[$field])) {
                continue;
            }
            $value = trim((string) $json[$field]);
            if ($value === '') {
                continue;
            }
            $output[$field] = $field === 'iban' ? $this->normalizeIban($value) : $value;
        }

        if ($output === []) {
            return StepResult::needsReview(message: 'KI konnte keine der gesuchten Felder sicher extrahieren.');
        }

        // Konfidenz des Modells (0-100); ohne Angabe konservativ niedrig, damit
        // das Gate zur Mitarbeiter-Freigabe greift.
        $confidence = isset($json['confidence']) && is_numeric($json['confidence'])
            ? max(0, min(100, (int) $json['confidence']))
            : 50;

        // Eine syntaktisch offensichtlich falsche IBAN darf nicht mit hoher
        // Konfidenz durchlaufen.
        if (isset($output['iban']) && !$this->looksLikeIban($output['iban'])) {
            $confidence = min($confidence, 50);
        }

        return StepResult::completed($output, $confidence);
    }

    private function defaultTemplate(): string
    {
        return "Extrahiere aus dem folgenden Dokumenttext die gesuchten Felder: {fields}.\n"
            . "Gib AUSSCHLIESSLICH ein JSON-Objekt zurueck mit genau diesen Schluesseln plus \"confidence\" (0-100).\n"
            . "Lass ein Feld weg, wenn es nicht eindeutig im Text steht.\n\nDOKUMENTTEXT:\n{text}";
    }

    private function normalizeIban(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', $value) ?? $value);
    }

    private function looksLikeIban(string $value): bool
    {
        return (bool) preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $value);
    }
}
