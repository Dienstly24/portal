<?php
namespace App\Services\Workflow;

use App\Services\Workflow\Contracts\StepHandlerInterface;

/**
 * Registry der Step-Handler (Typ -> Handler). In AppServiceProvider als
 * Singleton befuellt; ein neuer Schritt-Typ = ein neuer Handler + ein
 * Registry-Eintrag, KEINE Aenderung am Engine-Kern.
 */
class StepHandlerRegistry
{
    /** @var array<string, StepHandlerInterface> */
    private array $handlers = [];

    public function register(StepHandlerInterface $handler): self
    {
        $this->handlers[$handler->type()] = $handler;
        return $this;
    }

    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /**
     * @throws \RuntimeException wenn kein Handler fuer den Typ registriert ist
     */
    public function resolve(string $type): StepHandlerInterface
    {
        if (!isset($this->handlers[$type])) {
            throw new \RuntimeException("Kein Step-Handler fuer Typ '{$type}' registriert.");
        }
        return $this->handlers[$type];
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->handlers);
    }
}
