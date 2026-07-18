<?php
namespace App\Services\Workflow\Handlers;

use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Support\AiRequest;
use App\Services\Workflow\Contracts\StepHandlerInterface;
use App\Services\Workflow\Support\StepResult;

/**
 * Schritt `draft_reply`: entwirft eine Kundenantwort ueber die
 * provider-unabhaengige KI-Schicht. Der Entwurf wird bewusst NICHT
 * automatisch versendet - der Schritt haelt als `needs_review` an, damit ein
 * Mitarbeiter den Text freigibt oder anpasst und dann sendet (Human Override,
 * Blueprint Saeule 5). Ohne KI-Anbieter faellt der Schritt sauber auf die
 * manuelle Bearbeitung zurueck.
 */
class DraftReplyStepHandler implements StepHandlerInterface
{
    private const DEFAULT_SYSTEM = 'Du bist ein hilfsbereiter Kundenservice eines deutschen Versicherungsmaklers. Antworte hoeflich, kurz und auf Deutsch.';
    private const DEFAULT_TEMPLATE = 'Formuliere eine kurze, freundliche Rueckmeldung an den Kunden. Kontext (JSON): {context}';

    public function type(): string
    {
        return 'draft_reply';
    }

    public function handle(WorkflowStepRun $step, WorkflowRun $run): StepResult
    {
        $ai = app(AiProviderInterface::class);
        if (!$ai->isEnabled()) {
            return StepResult::needsReview(message: 'Kein KI-Anbieter - bitte Antwort manuell verfassen.');
        }

        $system = $run->definition?->promptTemplate('system', self::DEFAULT_SYSTEM) ?? self::DEFAULT_SYSTEM;
        $template = $run->definition?->promptTemplate('reply', self::DEFAULT_TEMPLATE) ?? self::DEFAULT_TEMPLATE;

        // Kontext ohne sensible Rohwerte: nur die Feldnamen der Extraktion,
        // damit die IBAN nicht in den Prompt (und spaeter in die Antwort) wandert.
        $context = json_encode([
            'vorgang' => $run->definition_key,
            'felder' => array_keys($run->latestOutputOfType('extract_data')),
        ], JSON_UNESCAPED_UNICODE);
        $prompt = str_replace('{context}', (string) $context, $template);

        try {
            $response = $ai->complete(AiRequest::text($system, $prompt, 700));
        } catch (\Throwable $e) {
            return StepResult::needsReview(message: 'KI-Entwurf fehlgeschlagen: ' . $e->getMessage());
        }

        $draft = trim($response->text);
        if ($draft === '') {
            return StepResult::needsReview(message: 'Leerer Entwurf - bitte manuell verfassen.');
        }

        // Entwurf steht bereit, aber ein Mensch gibt ihn frei (needs_review).
        return StepResult::needsReview(
            output: ['draft' => $draft],
            confidence: 80,
            message: 'Antwort-Entwurf liegt zur Freigabe bereit.',
        );
    }
}
