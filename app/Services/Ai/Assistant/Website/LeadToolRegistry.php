<?php
namespace App\Services\Ai\Assistant\Website;

/**
 * Whitelist des Website-Assistenten (Abschnitt 19).
 *
 * Sehr kurz und das mit Absicht: ein nicht angemeldeter Besucher darf
 * Angaben hinterlassen, in der Wissensbasis nachschlagen lassen und nach
 * einem Mitarbeiter verlangen. Mehr nicht - insbesondere KEIN Zugriff auf
 * Kundenakten, Vertraege, Vorgaenge oder Dokumente.
 */
class LeadToolRegistry
{
    /** @var array<string,LeadTool> */
    private array $tools = [];

    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    public function names(): array
    {
        return array_keys($this->tools);
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /** Schemata fuer den Anbieter. */
    public function schemas(): array
    {
        return array_values(array_map(fn (LeadTool $t) => [
            'name' => $t->name(),
            'description' => $t->description(),
            'parameters' => $t->parameters(),
        ], $this->tools));
    }

    /**
     * Aufruf ausfuehren. Ein unbekannter Name ist kein Absturz, sondern
     * eine Antwort an das Modell - sonst reisst ein Tippfehler des
     * Modells das ganze Gespraech ab.
     */
    public function execute(string $name, array $arguments, LeadContext $context): array
    {
        if (!$this->has($name)) {
            return ['fehler' => 'Unbekannte Funktion.'];
        }

        try {
            return $this->tools[$name]->run($arguments, $context);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Website-Assistent: Werkzeug fehlgeschlagen', [
                'werkzeug' => $name,
                'fehler' => $e->getMessage(),
            ]);

            return ['fehler' => 'Die Funktion konnte nicht ausgefuehrt werden.'];
        }
    }
}
