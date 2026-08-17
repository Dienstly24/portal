<?php
namespace App\Services\Ai\Assistant\Tools;

use App\Models\AiConversation;
use App\Models\Customer;

/**
 * Der Kunden-Kontext, in dem ALLE Tools laufen (Spezifikation Abschnitt 5).
 *
 * Kern der Datenisolation: die Kundenakte kommt aus der authentifizierten
 * Sitzung und wird hier festgeschrieben. Kein Tool-Schema enthaelt eine
 * Kunden-ID, und kein Tool kann diese hier veraendern (readonly) - das
 * Modell kann die Kundenzuordnung technisch nicht beeinflussen.
 *
 * `actions` sammelt, was tatsaechlich passiert ist (angelegte Vorgaenge,
 * Dokumentenanforderungen, Uebergaben) - Grundlage fuer Audit-Log
 * (Abschnitt 22) und Mitarbeiter-Zusammenfassung (Abschnitt 14).
 */
class AssistantToolContext
{
    /** @var list<array<string,mixed>> */
    private array $actions = [];

    /** Erkannte Absicht (vom Modell ueber die Tools gesetzt). */
    public ?string $intent = null;

    /** Uebergabe-Wunsch aus einem Tool-Aufruf (escalateToTeam). */
    public ?string $handoverReason = null;
    public ?string $handoverSummary = null;

    public function __construct(
        public readonly Customer $customer,
        public readonly AiConversation $conversation,
        /** Sprache der Kundennachricht (de/ar/en) - nur fuer Texte. */
        public readonly string $language = 'de',
    ) {
    }

    /** @param array<string,mixed> $detail */
    public function recordAction(string $action, array $detail = []): void
    {
        $this->actions[] = ['action' => $action] + $detail;
    }

    /** @return list<array<string,mixed>> */
    public function actions(): array
    {
        return $this->actions;
    }

    public function wantsHandover(): bool
    {
        return $this->handoverReason !== null;
    }
}
