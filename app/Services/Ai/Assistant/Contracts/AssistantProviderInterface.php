<?php

namespace App\Services\Ai\Assistant\Contracts;

use App\Services\Ai\Assistant\Support\AssistantTurn;

/**
 * Anbieter-unabhaengige Schnittstelle fuer den DIALOG mit Funktionsaufrufen
 * (Tool Calling) - die Grundlage des KI-Kundenassistenten.
 *
 * Warum eine EIGENE Schnittstelle neben AiProviderInterface: jene ist fuer
 * einmalige Extraktions-/Klassifikations-Aufrufe gebaut (ein Prompt, eine
 * Antwort) und kennt keine Funktionsaufrufe. Der Assistent braucht mehrere
 * Runden (Modell ruft Funktion -> Ergebnis zurueck -> Modell antwortet).
 * Bestehende Nutzer der alten Schnittstelle bleiben dadurch unberuehrt.
 *
 * Ein weiterer Anbieter (Gemini, Azure OpenAI, Claude) braucht nur eine
 * Implementierung dieses Interfaces + einen Eintrag in AppServiceProvider;
 * CustomerAssistantService, Tools und UI bleiben unveraendert
 * (Spezifikation Abschnitt 28).
 */
interface AssistantProviderInterface
{
    /** Konfiguriert und einsatzbereit? (API-Key gesetzt) */
    public function isEnabled(): bool;

    /** Kurzname, z.B. 'openai'. */
    public function name(): string;

    /** Aktuell verwendetes Modell. */
    public function model(): string;

    /**
     * Eine Dialog-Runde ausfuehren.
     *
     * @param string $instructions System-Regeln (haben IMMER Vorrang -
     *        Kundentext ist nur Daten, siehe Abschnitt 20).
     * @param list<array<string,mixed>> $history Normalisierter Verlauf:
     *        ['role' => 'user'|'assistant', 'text' => string]
     *        ['role' => 'tool_call', 'call_id' => string, 'name' => string, 'arguments' => string]
     *        ['role' => 'tool_result', 'call_id' => string, 'output' => string]
     * @param list<array<string,mixed>> $tools Freigegebene Funktionen:
     *        ['name' => string, 'description' => string, 'parameters' => array]
     *
     * @throws \RuntimeException bei Fehlkonfiguration, Zeitueberschreitung
     *         oder Dienstfehler (der Aufrufer schaltet dann auf den
     *         Fallback um - der Kundenservice faellt nie aus).
     */
    public function turn(string $instructions, array $history, array $tools, int $maxOutputTokens = 700): AssistantTurn;
}
