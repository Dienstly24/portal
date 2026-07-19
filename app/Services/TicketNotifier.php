<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use App\Support\Facades\Notify;
use App\Services\Notifications\NotificationService;

/**
 * Benachrichtigt das zuständige Team über eine neue Kundenanfrage (Ticket).
 *
 * Empfänger:
 *  - der/die Betreuer des Kunden (Zuweisung über employee_customers),
 *  - alle Admins/Manager (sehen ohnehin alle Kunden).
 * Bei Gast-Anfragen (kein Kunde) nur Admins/Manager.
 *
 * Erzeugt je Empfänger eine System-Benachrichtigung (Glocke) mit Angabe,
 * WER die Anfrage gestellt hat und WORUM es geht, plus Link zum Ticket.
 */
class TicketNotifier
{
    public static function notifyNewTicket(Ticket $ticket): void
    {
        $ticket->loadMissing('customer.user');

        // Wer hat die Anfrage gestellt?
        if ($ticket->customer) {
            $wer = $ticket->customer->user?->name
                ?: trim(($ticket->customer->first_name ?? '') . ' ' . ($ticket->customer->last_name ?? ''))
                ?: 'Kunde';
            $wer .= ' (Nr. ' . $ticket->customer->customer_number . ')';
        } else {
            $wer = ($ticket->guest_name ?: 'Gast')
                . ($ticket->guest_email ? ' <' . $ticket->guest_email . '>' : '');
        }

        $body = $wer . ' – ' . \Illuminate\Support\Str::limit($ticket->subject, 70);
        $link = route('admin.ticket', $ticket->id);

        // Empfängerkreis bestimmen
        $recipients = collect();
        if ($ticket->customer) {
            $recipients = $recipients->merge($ticket->customer->betreuer()->pluck('users.id'));
        }
        $recipients = $recipients->merge(
            User::whereIn('role', ['admin', 'manager'])->pluck('id')
        )->unique()->values();

        Notify::pushMany($recipients, [
            'type' => NotificationService::TYPE_TICKET,
            'title' => '🎫 Neue Support-Anfrage',
            'body' => $body,
            'link' => $link,
            'dedup_key' => 'ticket-new-' . $ticket->id,
        ]);
    }

    /**
     * Glocke fuer den zugewiesenen Mitarbeiter bei (Um-)Zuweisung.
     */
    public static function notifyAssigned(Ticket $ticket, User $assignee): void
    {
        if ($assignee->id === auth()->id()) {
            return; // Selbstzuweisung braucht keine Benachrichtigung
        }
        Notify::push($assignee->id, [
            'type' => NotificationService::TYPE_TICKET,
            'title' => '🎫 Ticket zugewiesen',
            'body' => ($ticket->ticket_number ? $ticket->ticket_number . ' – ' : '')
                . \Illuminate\Support\Str::limit($ticket->subject, 70),
            'link' => route('admin.ticket', $ticket->id),
            'dedup_key' => 'ticket-assigned-' . $ticket->id,
        ]);
    }

    /**
     * Portal-Glocke fuer den Kunden bei relevanten Statuswechseln.
     * NUR nach einem echten Wechsel aufrufen (transitionTo() === true),
     * sonst entstehen bei Doppel-Submits doppelte Glocken.
     */
    public static function notifyCustomerStatus(Ticket $ticket, bool $reopened = false): void
    {
        $ticket->loadMissing('customer.user');
        if (!$ticket->customer?->user_id) {
            return;
        }
        $text = match ($ticket->status) {
            'resolved' => 'Ihre Anfrage „:s" wurde als gelöst markiert. Bitte bestätigen Sie im Portal, ob Ihr Anliegen erledigt ist.',
            'closed' => 'Ihre Anfrage „:s" wurde geschlossen.',
            'waiting' => 'Zu Ihrer Anfrage „:s" wird Ihre Rückmeldung benötigt. Bitte antworten Sie im Kundenportal.',
            // "wieder" nur bei echter Wiedereroeffnung - beim ersten
            // Uebernehmen eines neuen Tickets waere das irrefuehrend.
            'open', 'in_progress' => $reopened
                ? 'Ihre Anfrage „:s" wurde wieder geöffnet und ist in Bearbeitung.'
                : 'Ihre Anfrage „:s" ist jetzt in Bearbeitung.',
            default => null,
        };
        if (!$text) {
            return;
        }
        Notify::push($ticket->customer->user_id, [
            'type' => NotificationService::TYPE_TICKET,
            'title' => 'Status Ihrer Anfrage',
            'body' => str_replace(':s', \Illuminate\Support\Str::limit($ticket->subject, 60), $text),
            'link' => route('portal.tickets.show', $ticket->id),
            'dedup_key' => 'ticket-status-' . $ticket->id . '-' . $ticket->status,
        ]);
    }

    /**
     * Glocke fuers Team, wenn der Kunde sein Ticket selbst schliesst
     * oder eine Bewertung abgibt.
     */
    public static function notifyTeam(Ticket $ticket, string $title, string $text): void
    {
        $ticket->loadMissing('customer.user');
        $recipients = collect();
        if ($ticket->assigned_to) {
            $recipients->push($ticket->assigned_to);
        }
        if ($ticket->customer) {
            $recipients = $recipients->merge($ticket->customer->betreuer()->pluck('users.id'));
        }
        $recipients = $recipients->merge(User::whereIn('role', ['admin', 'manager'])->pluck('id'))
            ->unique()->values();
        Notify::pushMany($recipients, [
            'type' => NotificationService::TYPE_TICKET,
            'title' => $title,
            'body' => $text,
            'link' => route('admin.ticket', $ticket->id),
            'dedup_key' => 'ticket-team-' . $ticket->id . '-' . md5($title),
        ]);
    }

    /**
     * Benachrichtigt das Team, wenn ein Kunde auf ein Ticket antwortet.
     */
    public static function notifyCustomerReply(Ticket $ticket): void
    {
        $ticket->loadMissing('customer.user');
        if (!$ticket->customer) {
            return;
        }

        $name = $ticket->customer->user?->name ?: 'Kunde';
        $body = $name . ' hat auf „' . \Illuminate\Support\Str::limit($ticket->subject, 60) . '" geantwortet.';
        $link = route('admin.ticket', $ticket->id);

        // Auch der zugewiesene Bearbeiter muss es erfahren - er ist nicht
        // zwingend Betreuer des Kunden (z. B. Support-Mitarbeiter).
        $recipients = collect($ticket->assigned_to ? [$ticket->assigned_to] : [])
            ->merge($ticket->customer->betreuer()->pluck('users.id'))
            ->merge(User::whereIn('role', ['admin', 'manager'])->pluck('id'))
            ->unique()->values();

        // dedup_key ohne Zeitbezug: mehrere Kundenantworten auf DASSELBE
        // Ticket fallen zu einem ungelesenen Eintrag zusammen, bis das Team
        // ihn liest - danach erzeugt die naechste Antwort wieder eine Glocke.
        Notify::pushMany($recipients, [
            'type' => NotificationService::TYPE_TICKET,
            'title' => '💬 Neue Ticket-Antwort',
            'body' => $body,
            'link' => $link,
            'dedup_key' => 'ticket-reply-' . $ticket->id,
        ]);
    }
}
