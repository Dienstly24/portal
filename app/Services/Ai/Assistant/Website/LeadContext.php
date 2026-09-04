<?php

namespace App\Services\Ai\Assistant\Website;

use App\Models\AiLead;

/**
 * Kontext des WEBSITE-Assistenten (Spezifikation Abschnitte 19 und 20).
 *
 * Bewusst eine EIGENE Klasse und nicht der Kunden-Kontext: ein
 * Website-Besucher ist nicht angemeldet. Wuerde er denselben Kontext
 * benutzen, waere die Frage "welcher Kunde ist das?" ueberhaupt erst
 * stellbar - und jede Antwort darauf waere eine Vermutung.
 *
 * So gibt es die Frage nicht: der Website-Assistent kennt strukturell
 * KEINEN Kunden, sondern nur diesen einen Interessenten-Datensatz.
 */
class LeadContext
{
    /** @var list<array<string,mixed>> */
    private array $actions = [];

    public bool $wantsHuman = false;

    public function __construct(
        public readonly AiLead $lead,
        public readonly string $language = 'de',
    ) {
    }

    public function recordAction(string $action, array $detail = []): void
    {
        $this->actions[] = ['action' => $action] + $detail;
    }

    /** @return list<array<string,mixed>> */
    public function actions(): array
    {
        return $this->actions;
    }
}
