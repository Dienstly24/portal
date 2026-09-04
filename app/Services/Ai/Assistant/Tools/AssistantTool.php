<?php

namespace App\Services\Ai\Assistant\Tools;

/**
 * Vertrag einer einzelnen freigegebenen Backend-Funktion (Spezifikation
 * Abschnitt 6/7): die KI bekommt NIE Datenbankzugriff, sondern darf nur
 * diese kontrollierten Funktionen aufrufen.
 *
 * Regeln fuer jede Implementierung:
 *  - `parameters()` enthaelt NIE eine Kunden-ID (die kommt aus dem
 *    Kontext, siehe AssistantToolContext).
 *  - `run()` liefert ein Array, das als JSON an das Modell geht: nur die
 *    Felder, die die Frage braucht (Datenminimierung, Abschnitt 21).
 *  - Lesende Tools sind frei; schreibende pruefen selbst die
 *    Betriebsschalter und den Duplikat-Schutz.
 */
interface AssistantTool
{
    /** Funktionsname, den das Modell aufruft (z.B. 'getMissingDocuments'). */
    public function name(): string;

    /** Beschreibung fuer das Modell - WANN diese Funktion zu nutzen ist. */
    public function description(): string;

    /** JSON-Schema der Parameter (ohne Kunden-ID!). */
    public function parameters(): array;

    /** Schreibt dieses Tool Daten? (Steuert Audit + Freigabe-Schalter.) */
    public function isWriting(): bool;

    /**
     * @param array<string,mixed> $arguments vom Modell geliefert - immer
     *        misstrauisch behandeln (validieren, nie direkt weitergeben).
     * @return array<string,mixed> Ergebnis fuer das Modell
     */
    public function run(array $arguments, AssistantToolContext $context): array;
}
