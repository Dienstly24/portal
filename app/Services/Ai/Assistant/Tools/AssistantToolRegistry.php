<?php
namespace App\Services\Ai\Assistant\Tools;

use Illuminate\Support\Facades\Log;

/**
 * Die WHITELIST der freigegebenen Backend-Funktionen (Spezifikation
 * Abschnitte 6/7).
 *
 * Nur was hier registriert ist, kann das Modell aufrufen. Ein Aufruf mit
 * unbekanntem Namen wird abgewiesen und protokolliert - nie "irgendwie"
 * interpretiert. Es gibt bewusst KEIN Tool fuer freie Datenbankabfragen,
 * fuer Vertragsaenderungen, Kuendigungen, Zahlungen oder Zugriff auf andere
 * Kunden.
 */
class AssistantToolRegistry
{
    /** @var array<string,AssistantTool> */
    private array $tools = [];

    /** @param iterable<AssistantTool> $tools */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Schema-Liste fuer den Anbieter. `$allowWriting = false` liefert nur
     * die lesenden Funktionen - so kann der Betreiber den Assistenten auf
     * "nur Auskunft" stellen, ohne Code zu aendern.
     *
     * @return list<array<string,mixed>>
     */
    public function schemas(bool $allowWriting = true): array
    {
        $schemas = [];
        foreach ($this->tools as $tool) {
            if (!$allowWriting && $tool->isWriting()) {
                continue;
            }
            $schemas[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->parameters(),
            ];
        }

        return $schemas;
    }

    /**
     * Einen Funktionsaufruf des Modells ausfuehren.
     *
     * Fehler eines einzelnen Tools beenden NICHT das Gespraech: das Modell
     * bekommt den Fehler als Ergebnis und kann sauber uebergeben, statt
     * dass der Kunde ins Leere laeuft.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public function execute(string $name, array $arguments, AssistantToolContext $context): array
    {
        if (!$this->has($name)) {
            Log::warning('KI-Assistent: unbekannte Funktion abgewiesen', ['tool' => $name]);

            return [
                'fehler' => 'Diese Funktion ist nicht freigegeben.',
                'hinweis' => 'Nutze nur die angebotenen Funktionen. Bei Unsicherheit escalateToTeam.',
            ];
        }

        try {
            return $this->tools[$name]->run($arguments, $context);
        } catch (\Throwable $e) {
            // Bewusst ohne Stacktrace/Kundendaten im Log.
            Log::warning('KI-Assistent: Funktion fehlgeschlagen', [
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);

            return [
                'fehler' => 'Diese Auskunft ist derzeit technisch nicht moeglich.',
                'hinweis' => 'Uebergib die Anfrage mit escalateToTeam (Grund: uncertain).',
            ];
        }
    }

    /** Schreibt das Tool Daten? (Fuer das Audit-Protokoll.) */
    public function isWriting(string $name): bool
    {
        return $this->has($name) && $this->tools[$name]->isWriting();
    }
}
