<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Models\Ticket;

/**
 * Status EINES Vorgangs/Tickets (Spezifikation Abschnitt 6:
 * getProcessStatus).
 *
 * SICHERHEIT: der Vorgang wird immer ZUSAETZLICH auf den angemeldeten
 * Kunden eingeschraenkt. Nennt der Kunde (oder das Modell) eine fremde
 * Ticketnummer, lautet die Antwort "nicht gefunden" - niemals Fremddaten
 * (Abschnitt 5, Testfall 12).
 */
class GetProcessStatusTool implements AssistantTool
{
    public function name(): string
    {
        return 'getProcessStatus';
    }

    public function description(): string
    {
        return 'Status eines einzelnen Vorgangs/Tickets des angemeldeten Kunden anhand der '
            .'Vorgangsnummer (Format T-JJ#####), inklusive letzter Rueckmeldung des Teams. '
            .'Nutze das, wenn der Kunde nach dem Stand eines bestimmten Vorgangs fragt.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'vorgangsnummer' => [
                    'type' => 'string',
                    'description' => 'Vorgangs-/Ticketnummer, z.B. T-2600123.',
                ],
            ],
            'required' => ['vorgangsnummer'],
        ];
    }

    public function isWriting(): bool
    {
        return false;
    }

    public function run(array $arguments, AssistantToolContext $context): array
    {
        $number = trim((string) ($arguments['vorgangsnummer'] ?? ''));
        if ($number === '') {
            return ['gefunden' => false, 'hinweis' => 'Keine Vorgangsnummer angegeben.'];
        }

        // Immer BEIDE Bedingungen: Nummer UND eigener Kunde.
        $ticket = Ticket::where('customer_id', $context->customer->id)
            ->where('ticket_number', $number)
            ->with(['messages' => fn ($q) => $q->where('is_internal', false)->latest()->limit(1)])
            ->first();

        if (! $ticket) {
            return [
                'gefunden' => false,
                'hinweis' => 'Zu dieser Nummer gibt es keinen Vorgang dieses Kunden. '
                    .'Frage nach der richtigen Nummer oder nutze getOpenTickets.',
            ];
        }

        $lastMessage = $ticket->messages->first();

        return [
            'gefunden' => true,
            'nummer' => $ticket->ticket_number,
            'thema' => $ticket->subject,
            'art' => $ticket->typeLabel(),
            'status' => $ticket->portalStatusLabel(),
            'erstellt' => $ticket->created_at?->lokal()->format('d.m.Y'),
            'letzte_rueckmeldung' => $lastMessage
                ? $lastMessage->created_at?->lokal()->format('d.m.Y')
                : null,
            'abgeschlossen' => in_array($ticket->status, ['resolved', 'closed'], true),
        ];
    }
}
