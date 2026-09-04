<?php

namespace App\Console\Commands;

use App\Console\Concerns\ProcessesRecordsSafely;
use App\Models\Ticket;
use App\Services\Notifications\NotificationService;
use App\Support\Facades\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Schliesst geloeste Tickets automatisch, wenn der Kunde nach Ablauf der
 * Bestaetigungsfrist (Standard: 7 Tage) nicht reagiert hat. Antwortet der
 * Kunde vorher, wird das Ticket ohnehin wieder geoeffnet (PortalController).
 */
class AutoCloseResolvedTickets extends Command
{
    use ProcessesRecordsSafely;

    protected $signature = 'tickets:auto-close {--days=7 : Tage seit Loesung ohne Kundenreaktion} {--dry-run : Nur anzeigen, nichts schliessen}';

    protected $description = 'Geloeste Tickets ohne Kundenreaktion automatisch schliessen';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $tickets = Ticket::where('status', 'resolved')
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<=', now()->subDays($days))
            ->get();

        // Je Vorgang abgesichert: ein Ticket mit kaputtem Bezug (geloeschter
        // Kunde, fehlender Portal-Nutzer) darf nicht verhindern, dass alle
        // uebrigen geloesten Vorgaenge geschlossen werden.
        $verarbeitet = $this->verarbeiteEinzeln($tickets, function (Ticket $ticket) use ($days) {
            if ($this->option('dry-run')) {
                $this->line('Wuerde schliessen: '.$ticket->ticket_number.' – '.$ticket->subject);

                return;
            }
            $ticket->transitionTo('closed', null, 'auto_closed');
            // Portal-Glocke: Kunde weiss, dass der Vorgang abgeschlossen ist
            if ($ticket->customer?->user_id) {
                Notify::push($ticket->customer->user_id, [
                    'type' => NotificationService::TYPE_TICKET,
                    'title' => 'Anfrage geschlossen',
                    'body' => 'Ihre gelöste Anfrage „'.Str::limit($ticket->subject, 60).'" wurde nach '.$days.' Tagen ohne Rückmeldung automatisch geschlossen.',
                    'link' => route('portal.tickets.show', $ticket->id),
                    'dedup_key' => 'ticket-autoclosed-'.$ticket->id,
                ]);
            }
        }, 'Vorgang');

        $this->info($verarbeitet.' geloeste Tickets verarbeitet.');

        return $this->ergebnisMitUebersprungenen(self::SUCCESS);
    }
}
