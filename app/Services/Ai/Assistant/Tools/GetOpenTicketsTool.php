<?php
namespace App\Services\Ai\Assistant\Tools;

use App\Models\CustomerChangeRequest;
use App\Models\Ticket;

/**
 * Offene Vorgaenge/Tickets des angemeldeten Kunden (Spezifikation
 * Abschnitt 6: getOpenTickets + getOpenProcesses).
 *
 * In Dienstly24 ist ein Vorgang ein Ticket - deshalb EIN Tool statt zwei
 * mit identischem Ergebnis (siehe Integrationsplan 1.2). Zusaetzlich
 * gemeldet werden offene Aenderungsantraege (CustomerChangeRequest), denn
 * aus Kundensicht ist "meine Adressaenderung wird geprueft" ebenfalls ein
 * laufender Vorgang.
 */
class GetOpenTicketsTool implements AssistantTool
{
    public function name(): string
    {
        return 'getOpenTickets';
    }

    public function description(): string
    {
        return 'Offene Vorgaenge und Tickets des angemeldeten Kunden (Nummer, Thema, Art, '
            . 'Status, erstellt am) sowie offene Aenderungsantraege. Nutze das IMMER, bevor '
            . 'du einen neuen Vorgang anlegst - ein bestehender Vorgang zum selben Thema '
            . 'wird weiterverwendet, nie dupliziert.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    public function isWriting(): bool
    {
        return false;
    }

    public function run(array $arguments, AssistantToolContext $context): array
    {
        $tickets = Ticket::where('customer_id', $context->customer->id)
            ->active()
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $changeRequests = CustomerChangeRequest::where('customer_id', $context->customer->id)
            ->pending()
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return [
            'anzahl_offene_vorgaenge' => $tickets->count(),
            'vorgaenge' => $tickets->map(fn (Ticket $t) => [
                'nummer' => $t->ticket_number,
                'thema' => $t->subject,
                'art' => $t->typeLabel(),
                'status' => $t->portalStatusLabel(),
                'erstellt' => $t->created_at?->format('d.m.Y'),
            ])->values()->all(),
            'aenderungsantraege' => $changeRequests->map(fn (CustomerChangeRequest $r) => [
                'art' => $r->typeLabel(),
                'status' => 'In Pruefung',
                'eingereicht' => $r->created_at?->format('d.m.Y'),
            ])->values()->all(),
        ];
    }
}
