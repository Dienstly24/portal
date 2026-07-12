<?php

namespace App\Console\Commands;

use App\Models\InternalNotification;
use App\Models\Ticket;
use Illuminate\Console\Command;

/**
 * Schliesst geloeste Tickets automatisch, wenn der Kunde nach Ablauf der
 * Bestaetigungsfrist (Standard: 7 Tage) nicht reagiert hat. Antwortet der
 * Kunde vorher, wird das Ticket ohnehin wieder geoeffnet (PortalController).
 */
class AutoCloseResolvedTickets extends Command
{
    protected $signature = 'tickets:auto-close {--days=7 : Tage seit Loesung ohne Kundenreaktion} {--dry-run : Nur anzeigen, nichts schliessen}';

    protected $description = 'Geloeste Tickets ohne Kundenreaktion automatisch schliessen';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $tickets = Ticket::where('status', 'resolved')
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<=', now()->subDays($days))
            ->get();

        foreach ($tickets as $ticket) {
            if ($this->option('dry-run')) {
                $this->line('Wuerde schliessen: ' . $ticket->ticket_number . ' – ' . $ticket->subject);
                continue;
            }
            $ticket->transitionTo('closed', null, 'auto_closed');
            // Portal-Glocke: Kunde weiss, dass der Vorgang abgeschlossen ist
            if ($ticket->customer?->user_id) {
                InternalNotification::create([
                    'user_id' => $ticket->customer->user_id,
                    'title' => 'Anfrage geschlossen',
                    'body' => 'Ihre gelöste Anfrage „' . \Illuminate\Support\Str::limit($ticket->subject, 60) . '" wurde nach ' . $days . ' Tagen ohne Rückmeldung automatisch geschlossen.',
                    'link' => route('portal.tickets.show', $ticket->id),
                ]);
            }
        }

        $this->info($tickets->count() . ' geloeste Tickets verarbeitet.');
        return self::SUCCESS;
    }
}
