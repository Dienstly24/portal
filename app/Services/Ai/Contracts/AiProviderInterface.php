<?php
namespace App\Services\Ai\Contracts;

use App\Services\Ai\Support\AiRequest;
use App\Services\Ai\Support\AiResponse;

/**
 * Provider-unabhaengige LLM-Schnittstelle (Saeule 8 des AI-Workflow-Engine-
 * Blueprints). Die Workflow-Engine und ihre hoeheren Dienste
 * (IntentDetector, FieldExtractor, ReplyGenerator) sprechen NUR gegen dieses
 * Interface - ein weiterer Anbieter (OpenAI, Gemini, Azure OpenAI) braucht
 * nur eine neue Implementierung + einen Eintrag in AppServiceProvider,
 * KEINE Aenderung an der Engine.
 *
 * Auswahl per Config `services.ai_text_provider` (env AI_TEXT_PROVIDER).
 */
interface AiProviderInterface
{
    /** Konfiguriert und einsatzbereit? (z.B. API-Key gesetzt) */
    public function isEnabled(): bool;

    /** Kurzname des Anbieters, z.B. 'claude', 'openai'. */
    public function name(): string;

    /** Aktuell verwendetes Modell. */
    public function model(): string;

    /**
     * Fuehrt eine Vervollstaendigung aus und liefert die normalisierte
     * Antwort.
     *
     * @throws \RuntimeException wenn nicht konfiguriert oder der Dienst
     *         mit einem Fehler antwortet (der Aufrufer entscheidet ueber
     *         Retry/Fehlerstatus).
     */
    public function complete(AiRequest $request): AiResponse;
}
