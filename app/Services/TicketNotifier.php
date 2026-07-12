<?php

namespace App\Services;

use App\Models\InternalNotification;
use App\Models\Ticket;
use App\Models\User;

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

        foreach ($recipients as $userId) {
            InternalNotification::create([
                'user_id' => $userId,
                'title' => '🎫 Neue Support-Anfrage',
                'body' => $body,
                'link' => $link,
            ]);
        }
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

        $recipients = $ticket->customer->betreuer()->pluck('users.id')
            ->merge(User::whereIn('role', ['admin', 'manager'])->pluck('id'))
            ->unique()->values();

        foreach ($recipients as $userId) {
            InternalNotification::create([
                'user_id' => $userId,
                'title' => '💬 Neue Ticket-Antwort',
                'body' => $body,
                'link' => $link,
            ]);
        }
    }
}
